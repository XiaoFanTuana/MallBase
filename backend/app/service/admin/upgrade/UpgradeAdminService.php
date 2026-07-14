<?php

declare(strict_types=1);

namespace app\service\admin\upgrade;

use app\model\upgrade\UpgradeRecord;
use app\service\install\InstallLockService;
use Closure;
use mall_base\base\BaseService;
use RuntimeException;

/**
 * @extends BaseService<UpgradeRecord>
 */
final class UpgradeAdminService extends BaseService
{
    private const ENTRY_TICKET_TTL = 60;

    protected string $modelClass = UpgradeRecord::class;

    public function __construct(
        private readonly ?string $configuredRoot = null,
        private readonly ?Closure $clock = null,
        private readonly ?Closure $ticketFactory = null,
        private readonly ?InstallLockService $installLock = null,
    ) {
    }

    /**
     * @return array{total:int,list:list<array<string, int|string>>}
     */
    public function getList(int $page, int $limit): array
    {
        if ($page < 1 || $limit < 1 || $limit > 100) {
            throw new RuntimeException('UPGRADE_RECORD_ARGUMENT_INVALID');
        }
        $records = $this->model()->scan($this->root());
        $total = count($records);
        $list = array_slice($records, ($page - 1) * $limit, $limit);

        return compact('total', 'list');
    }

    /**
     * @return array{upgrade_url:string,expires_at:int}
     */
    public function createEntryTicket(int $adminId): array
    {
        if ($adminId < 1) {
            throw new RuntimeException('UPGRADE_ENTRY_ARGUMENT_INVALID');
        }
        $platformToken = $this->platformToken();
        $ticket = $this->newTicket();
        $issuedAt = $this->now();
        $expiresAt = $issuedAt + self::ENTRY_TICKET_TTL;
        $hash = hash('sha256', $ticket);
        $this->model()->writeEntryTicket($this->root(), [
            'schema_version' => 1,
            'ticket_hash' => $hash,
            'admin_id' => $adminId,
            'platform_token' => $platformToken,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
        ]);

        return [
            'upgrade_url' => '/upgrade/?ticket=' . rawurlencode($ticket),
            'expires_at' => $expiresAt,
        ];
    }

    private function root(): string
    {
        return $this->configuredRoot ?? (string) config('agent.upgrade_root', '');
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) ($this->clock)();
    }

    private function newTicket(): string
    {
        $ticket = $this->ticketFactory === null
            ? rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')
            : (string) ($this->ticketFactory)();
        if (preg_match('/^[0-9A-Za-z_-]{43}$/D', $ticket) !== 1) {
            throw new RuntimeException('UPGRADE_ENTRY_UNAVAILABLE');
        }

        return $ticket;
    }

    private function platformToken(): string
    {
        $platform = ($this->installLock ?? app()->make(InstallLockService::class))->getPlatformState();
        $token = $platform['token'] ?? null;
        if (($platform['disabled'] ?? false) === true || !is_string($token)
            || strlen($token) < 1 || strlen($token) > 4096
            || preg_match('/^[\x21-\x7E]+$/D', $token) !== 1) {
            throw new RuntimeException('UPGRADE_ENTRY_UNAVAILABLE');
        }

        return $token;
    }
}
