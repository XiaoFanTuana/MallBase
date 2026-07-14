<?php

declare(strict_types=1);

namespace app\controller\upgrade;

use app\service\upgrade\SimpleUpgradeRuntimeService;
use app\service\upgrade\UpgradeStrictJsonDecoder;
use Closure;
use mall_base\base\BaseController;
use think\Response;
use Throwable;

/** Thin local runtime API used only by the Go upgrade process. */
final class SimpleUpgradeController extends BaseController
{
    protected string $serviceClass = SimpleUpgradeRuntimeService::class;

    public function pause(string $jobId): Response
    {
        return $this->execute(
            ['action', 'source_version', 'target_version'],
            fn($service, array $body): array => $service->pause($jobId, $body),
        );
    }

    public function backupDatabase(string $jobId): Response
    {
        return $this->execute(
            [],
            fn($service, array $body): array => $service->backup($jobId, $body),
        );
    }

    public function migrations(string $jobId): Response
    {
        return $this->execute(
            ['migration_id', 'version', 'path', 'sha256'],
            fn($service, array $body): array => $service->migrate($jobId, $body),
        );
    }

    public function restoreDatabase(string $jobId): Response
    {
        return $this->execute(
            ['source_job_id', 'database_path', 'database_sha256'],
            fn($service, array $body): array => $service->restore($jobId, $body),
        );
    }

    public function awaitingRestart(string $jobId): Response
    {
        return $this->execute(
            ['action', 'target_version'],
            fn($service, array $body): array => $service->awaitingRestart($jobId, $body),
        );
    }

    public function resume(string $jobId): Response
    {
        return $this->execute(
            [],
            fn($service, array $body): array => $service->resume($jobId, $body),
        );
    }

    /**
     * @param list<string> $fields
     * @param Closure(SimpleUpgradeRuntimeService,array<string,mixed>):array<string,mixed> $operation
     */
    private function execute(array $fields, Closure $operation): Response
    {
        try {
            $length = trim((string) $this->request->header('Content-Length', ''));
            $body = app()->make(UpgradeStrictJsonDecoder::class)->decode(
                (string) $this->request->getContent(),
                (string) $this->request->header('Content-Type', ''),
                $length === '' ? null : (int) $length,
                $fields,
            );
            /** @var SimpleUpgradeRuntimeService $service */
            $service = $this->service();
            $data = $operation($service, $body);

            return Response::create([
                'code' => 200,
                'message' => '操作成功',
                'data' => $data,
                'timestamp' => time(),
            ], 'json', 200)->header(['Cache-Control' => 'no-store']);
        } catch (Throwable $exception) {
            $reason = $exception->getMessage();
            $status = match ($reason) {
                'UPGRADE_JSON_INVALID', 'SIMPLE_UPGRADE_INPUT_INVALID' => 422,
                'SIMPLE_UPGRADE_GATE_NOT_PAUSED', 'SIMPLE_UPGRADE_STATE_CONFLICT' => 409,
                default => 500,
            };

            return Response::create([
                'code' => $status,
                'message' => '升级本地操作失败',
                'data' => null,
                'timestamp' => time(),
            ], 'json', $status)->header(['Cache-Control' => 'no-store']);
        }
    }
}
