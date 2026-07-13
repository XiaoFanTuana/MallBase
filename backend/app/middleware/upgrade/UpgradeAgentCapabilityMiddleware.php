<?php

declare(strict_types=1);

namespace app\middleware\upgrade;

use app\service\upgrade\UpgradeAgentNonceStore;
use app\service\upgrade\UpgradeAgentRuntimePolicy;
use app\service\upgrade\UpgradeSameOriginPolicy;
use app\service\upgrade\UpgradeSharedFileStore;
use app\service\upgrade\UpgradeStrictJsonDecoder;
use Closure;
use RuntimeException;
use think\Request;
use think\Response;
use Throwable;

/** Authenticates one Agent request without creating a generic maintenance bypass. */
final readonly class UpgradeAgentCapabilityMiddleware
{
    public function __construct(
        private ?UpgradeSharedFileStore $files = null,
        private ?UpgradeAgentNonceStore $nonces = null,
        private ?UpgradeAgentRuntimePolicy $policy = null,
        private ?UpgradeSameOriginPolicy $origin = null,
        private ?UpgradeStrictJsonDecoder $decoder = null,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $jobId = strtolower((string) $request->param('jobId', ''));
            $action = $this->action($request);
            $document = ($this->files ?? app()->make(UpgradeSharedFileStore::class))->readJobRequest($jobId);
            $values = $document === null ? [] : get_object_vars($document);
            $token = $values['capability_token'] ?? null;
            if (($values['job_id'] ?? null) !== $jobId || !is_string($token)
                || preg_match('/^[A-Za-z0-9_-]{32,512}$/D', $token) !== 1) {
                throw new RuntimeException('UPGRADE_AGENT_AUTH_INVALID');
            }
            $authorization = trim((string) $request->header('Authorization', ''));
            if (!str_starts_with($authorization, 'MallBase-Agent ')
                || !hash_equals($token, substr($authorization, 15))) {
                throw new RuntimeException('UPGRADE_AGENT_AUTH_INVALID');
            }
            ($this->origin ?? app()->make(UpgradeSameOriginPolicy::class))
                ->assert(trim((string) $request->header('Origin', '')));

            $timestamp = $this->timestamp((string) $request->header('X-MallBase-Agent-Timestamp', ''));
            if (abs(time() - $timestamp) > 60) {
                throw new RuntimeException('UPGRADE_AGENT_AUTH_EXPIRED');
            }
            $nonce = strtolower(trim((string) $request->header('X-MallBase-Agent-Nonce', '')));
            $signature = strtolower(trim((string) $request->header('X-MallBase-Agent-Signature', '')));
            $body = (string) $request->getContent();
            if (strlen($body) > 65536 || preg_match('/^[0-9a-f-]{36}$/D', $nonce) !== 1
                || preg_match('/^sha256=[0-9a-f]{64}$/D', $signature) !== 1) {
                throw new RuntimeException('UPGRADE_AGENT_AUTH_INVALID');
            }
            $uri = (string) $request->server('REQUEST_URI', '');
            if ($uri === '' || !str_starts_with($uri, '/upgrade/api/agent/jobs/')
                || str_contains($uri, "\0") || strlen($uri) > 2048) {
                throw new RuntimeException('UPGRADE_AGENT_AUTH_INVALID');
            }
            $canonical = strtoupper($request->method()) . "\n" . $uri . "\n" . $timestamp . "\n"
                . $nonce . "\n" . hash('sha256', $body);
            $key = hash('sha256', "mallbase-agent-local-api-v1\0" . $token, true);
            $expected = 'sha256=' . hash_hmac('sha256', $canonical, $key);
            if (!hash_equals($expected, $signature)) {
                throw new RuntimeException('UPGRADE_AGENT_AUTH_INVALID');
            }
            ($this->nonces ?? app()->make(UpgradeAgentNonceStore::class))->consume($jobId, $nonce);
            $context = $this->policyContext($action, $body, $request);
            $principal = ($this->policy ?? app()->make(UpgradeAgentRuntimePolicy::class))
                ->authorize($jobId, $action, $context);
            $request->upgrade_agent = [
                'job_id' => $jobId,
                'action' => $action,
                'request' => $values,
                'gate' => $principal['gate'],
                'identity' => $principal['identity'],
            ];

            return $next($request);
        } catch (Throwable $exception) {
            $reason = $exception->getMessage();
            [$status, $message] = match ($reason) {
                'RUNTIME_IDENTITY_FENCED', 'UPGRADE_AGENT_ACTION_FORBIDDEN' => [409, '当前运行实例无权执行此升级操作'],
                'UPGRADE_AGENT_REPLAYED' => [409, '升级请求已处理'],
                'UPGRADE_AGENT_NONCE_UNAVAILABLE' => [503, '升级鉴权暂不可用'],
                default => [401, '升级 Agent 鉴权失败'],
            };

            return json([
                'code' => $status,
                'message' => $message,
                'data' => ['reason' => preg_match('/^[A-Z0-9_]{1,64}$/D', $reason) === 1
                    ? $reason : 'UPGRADE_AGENT_AUTH_INVALID'],
                'timestamp' => time(),
            ], $status)->header(['Cache-Control' => 'no-store']);
        }
    }

    private function timestamp(string $value): int
    {
        $value = trim($value);
        if (preg_match('/^(?:0|[1-9][0-9]{0,10})$/D', $value) !== 1) {
            throw new RuntimeException('UPGRADE_AGENT_AUTH_INVALID');
        }

        return (int) $value;
    }

    private function action(Request $request): string
    {
        $path = trim($request->pathinfo(), '/');
        if (preg_match('~^upgrade/api/agent/jobs/[0-9a-f-]{36}/operations/[0-9a-f-]{36}$~D', $path) === 1) {
            return 'operation';
        }
        if (preg_match('~^upgrade/api/agent/jobs/[0-9a-f-]{36}/([a-z-]+)$~D', $path, $matches) !== 1) {
            throw new RuntimeException('UPGRADE_AGENT_ACTION_FORBIDDEN');
        }

        return $matches[1];
    }

    /** @return array<string,mixed> */
    private function policyContext(string $action, string $body, Request $request): array
    {
        $fields = match ($action) {
            'runtime-fence' => ['operation_id', 'expected_revision', 'source', 'target', 'attempt'],
            'migrations' => ['operation_id', 'migration_id', 'source', 'fence_operation_id', 'attempt'],
            'state-transition' => [
                'operation_id', 'expected_revision', 'expected_state', 'next_state',
                'platform_sync_pending', 'source', 'fence_operation_id', 'attempt',
            ],
            'resume' => [
                'operation_id', 'expected_revision', 'phase', 'control_expected_revision',
                'control_request_id', 'source', 'fence_operation_id', 'attempt',
            ],
            'cancel' => [
                'operation_id', 'expected_revision', 'control_expected_revision',
                'control_request_id', 'source', 'fence_operation_id', 'attempt',
            ],
            'platform-receipt' => [
                'operation_id', 'expected_revision', 'terminal_state',
                'normal_transition_operation_id', 'source', 'fence_operation_id', 'attempt',
            ],
            default => null,
        };
        if ($fields === null) {
            return [];
        }
        $length = trim((string) $request->header('Content-Length', ''));
        try {
            return ($this->decoder ?? app()->make(UpgradeStrictJsonDecoder::class))->decode(
                $body,
                (string) $request->header('Content-Type', ''),
                $length === '' ? null : (int) $length,
                $fields,
            );
        } catch (Throwable) {
            // A normal target runtime is still allowed to reach the strict
            // controller decoder and receive an argument error. A fenced
            // source runtime receives no exceptional authority unless this
            // exact signed context can be decoded here.
            return [];
        }
    }
}
