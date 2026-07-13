<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Browser upgrade capability state machine over the Agent/PHP shared checkpoint.
 *
 * The file is the authorization truth source. This service keeps no request or
 * session state in memory and every mutation holds the cross-process auth lock.
 */
final readonly class UpgradeSessionAuthStore
{
    private const MAX_TIMESTAMP = 4_102_444_800;
    private const OWNER_LIFETIME = 86_400;
    private const RECOVERY_LIFETIME = 86_400;
    private const STALE_SECONDS = 60;

    private const TOP_LEVEL_FIELDS = [
        'schema_version', 'instance_id', 'session_id', 'job_id', 'owner_sha256',
        'recovery_sha256', 'previous_recovery_sha256', 'copy_confirmation_pending',
        'pending_request', 'confirmation_receipt', 'last_break_glass_event_id',
        'issued_at', 'owner_expires_at', 'recovery_expires_at', 'last_seen_at', 'revision',
    ];
    private const PENDING_FIELDS = ['action', 'request_id', 'result_revision', 'issued_at'];
    private const RECEIPT_FIELDS = ['request_id', 'result_revision', 'confirmed_at'];

    public function __construct(private UpgradeSharedFileStore $files)
    {
    }

    /** @return array<string, mixed>|null */
    public function load(): ?array
    {
        return $this->loadUnlocked();
    }

    /** @return array<string, mixed> */
    public function create(
        string $instanceId,
        string $derivationKey,
        int $adminId,
        string $requestId,
        int $now,
    ): array {
        $key = $this->decodeKey($derivationKey);
        if (!$this->validUuid($instanceId) || $adminId < 1 || !$this->validUuid($requestId)) {
            $this->fail('UPGRADE_SESSION_ARGUMENT_INVALID');
        }
        $this->requireTimestamp($now);
        if ($now > self::MAX_TIMESTAMP - self::OWNER_LIFETIME) {
            $this->fail('UPGRADE_SESSION_ARGUMENT_INVALID');
        }

        return $this->files->withSessionAuthLock(function () use (
            $instanceId,
            $key,
            $adminId,
            $requestId,
            $now,
        ): array {
            $sessionId = $this->deriveUuid($key, 'create-session-id', [
                $instanceId,
                (string) $adminId,
                $requestId,
            ]);
            $current = $this->loadUnlocked();
            if ($current !== null) {
                if ($current['session_id'] !== $sessionId
                    || $current['instance_id'] !== $instanceId
                    || !$current['copy_confirmation_pending']
                    || !is_array($current['pending_request'])
                    || $current['pending_request']['action'] !== 'create'
                    || $current['pending_request']['request_id'] !== $requestId
                    || $current['pending_request']['result_revision'] !== $current['revision']) {
                    $this->fail('UPGRADE_SESSION_EXISTS');
                }

                return $this->createResponse(
                    $key,
                    $sessionId,
                    $requestId,
                    $current['pending_request']['result_revision'],
                    (string) $adminId,
                );
            }

            $response = $this->createResponse($key, $sessionId, $requestId, 1, (string) $adminId);
            $document = [
                'schema_version' => 1,
                'instance_id' => $instanceId,
                'session_id' => $sessionId,
                'job_id' => null,
                'owner_sha256' => $this->hashCapability($response['owner_cookie']),
                'recovery_sha256' => $this->hashCapability($response['recovery_credential']),
                'previous_recovery_sha256' => null,
                'copy_confirmation_pending' => true,
                'pending_request' => [
                    'action' => 'create',
                    'request_id' => $requestId,
                    'result_revision' => 1,
                    'issued_at' => $now,
                ],
                'confirmation_receipt' => null,
                'last_break_glass_event_id' => null,
                'issued_at' => $now,
                'owner_expires_at' => $now + self::OWNER_LIFETIME,
                'recovery_expires_at' => $now + self::RECOVERY_LIFETIME,
                'last_seen_at' => $now,
                'revision' => 1,
            ];
            $this->write($document);

            return $response;
        });
    }

    /** @return array<string, mixed> */
    public function authenticateOwner(string $ownerCookie, int $now): array
    {
        $this->requireTimestamp($now);
        if (!$this->parseCapability($ownerCookie, 'mbuo1')) {
            $this->fail('UPGRADE_SESSION_UNAUTHORIZED');
        }

        return $this->files->withSessionAuthLock(function () use ($ownerCookie, $now): array {
            $current = $this->requireSession();
            if (!$this->ownerMatches($current, $ownerCookie, $now)) {
                $this->fail('UPGRADE_SESSION_UNAUTHORIZED');
            }

            // Presence is operational liveness, not an authorization change.
            // Keeping the revision stable prevents status polling in another
            // tab from invalidating an in-flight control request.
            if ($now - $current['last_seen_at'] >= 5) {
                $current['last_seen_at'] = $now;
                $this->write($current);
            }

            return $this->principal($current) + [
                'csrf_nonce' => $this->ownerCsrfNonce($ownerCookie, $current),
            ];
        });
    }

    /**
     * Binds one immutable job and keeps the session lock held until its request
     * document has either been durably published or failed closed.
     *
     * @param Closure(array<string,mixed>):void $publisher
     * @return array<string,mixed>
     */
    public function bindJob(
        string $ownerCookie,
        string $jobId,
        int $expectedRevision,
        int $now,
        Closure $publisher,
    ): array {
        $this->requireTimestamp($now);
        if (!$this->validUuid($jobId) || $expectedRevision < 1
            || !$this->parseCapability($ownerCookie, 'mbuo1')) {
            $this->fail('UPGRADE_SESSION_UNAUTHORIZED');
        }

        return $this->files->withSessionAuthLock(function () use (
            $ownerCookie,
            $jobId,
            $expectedRevision,
            $now,
            $publisher,
        ): array {
            $current = $this->requireSession();
            if (!$this->ownerMatches($current, $ownerCookie, $now)
                || $current['copy_confirmation_pending']) {
                $this->fail('UPGRADE_SESSION_UNAUTHORIZED');
            }
            if ($current['job_id'] === null) {
                if ($current['revision'] !== $expectedRevision) {
                    $this->fail('UPGRADE_SESSION_CONFLICT');
                }
                $this->incrementRevision($current);
                $current['job_id'] = $jobId;
                $current['recovery_expires_at'] = null;
                $current['last_seen_at'] = max($current['last_seen_at'], $now);
                $publisher($current);
                $this->write($current);
            } elseif ($current['job_id'] !== $jobId) {
                $this->fail('UPGRADE_SESSION_JOB_CONFLICT');
            } elseif ($current['revision'] !== $expectedRevision + 1) {
                $this->fail('UPGRADE_SESSION_CONFLICT');
            } else {
                $publisher($current);
            }

            return $this->principal($current);
        });
    }

    /**
     * Revalidates the owner and job while holding the cross-process session
     * lock through the supplied control side effect.
     *
     * @param Closure(array<string,mixed>):array<string,mixed> $mutation
     * @return array<string,mixed>
     */
    public function withAuthorizedJobMutation(
        string $ownerCookie,
        string $jobId,
        int $sessionRevision,
        int $now,
        Closure $mutation,
    ): array {
        $this->requireTimestamp($now);
        if (!$this->validUuid($jobId) || $sessionRevision < 1
            || !$this->parseCapability($ownerCookie, 'mbuo1')) {
            $this->fail('UPGRADE_SESSION_UNAUTHORIZED');
        }

        return $this->files->withSessionAuthLock(function () use (
            $ownerCookie,
            $jobId,
            $sessionRevision,
            $now,
            $mutation,
        ): array {
            $current = $this->requireSession();
            if (!$this->ownerMatches($current, $ownerCookie, $now)
                || $current['copy_confirmation_pending']
                || $current['job_id'] !== $jobId
                || $current['revision'] !== $sessionRevision) {
                $this->fail('UPGRADE_SESSION_CONFLICT');
            }

            return $mutation($current);
        });
    }

    /** @return array<string, mixed> */
    public function confirm(
        string $derivationKey,
        string $ownerCookie,
        string $requestId,
        string $confirmationNonce,
        int $now,
    ): array {
        $key = $this->decodeKey($derivationKey);
        $this->requireTimestamp($now);
        if (!$this->validUuid($requestId) || !$this->validBase64Url32($confirmationNonce)
            || !$this->parseCapability($ownerCookie, 'mbuo1')) {
            $this->fail('UPGRADE_SESSION_UNAUTHORIZED');
        }

        return $this->files->withSessionAuthLock(function () use (
            $key,
            $ownerCookie,
            $requestId,
            $confirmationNonce,
            $now,
        ): array {
            $current = $this->requireSession();
            if (!$this->ownerMatches($current, $ownerCookie, $now)) {
                $this->fail('UPGRADE_SESSION_UNAUTHORIZED');
            }
            $receipt = $current['confirmation_receipt'];
            if (!$current['copy_confirmation_pending']) {
                if (is_array($receipt) && $receipt['request_id'] === $requestId
                    && $receipt['result_revision'] > 1
                    && hash_equals(
                        $this->confirmationNonce(
                            $key,
                            $current['session_id'],
                            $requestId,
                            $receipt['result_revision'] - 1,
                        ),
                        $confirmationNonce,
                    )) {
                    return $this->confirmationResponse($current);
                }
                $this->fail('UPGRADE_SESSION_UNAUTHORIZED');
            }
            $pending = $current['pending_request'];
            if (!is_array($pending) || $pending['request_id'] !== $requestId
                || $pending['result_revision'] !== $current['revision']) {
                $this->fail('UPGRADE_SESSION_UNAUTHORIZED');
            }
            $expectedNonce = $this->confirmationNonce(
                $key,
                $current['session_id'],
                $requestId,
                $pending['result_revision'],
            );
            if (!hash_equals($expectedNonce, $confirmationNonce)) {
                $this->fail('UPGRADE_SESSION_UNAUTHORIZED');
            }
            $this->incrementRevision($current);
            $current['copy_confirmation_pending'] = false;
            $current['pending_request'] = null;
            $current['previous_recovery_sha256'] = null;
            $current['confirmation_receipt'] = [
                'request_id' => $requestId,
                'result_revision' => $current['revision'],
                'confirmed_at' => $now,
            ];
            $current['last_seen_at'] = max($current['last_seen_at'], $now);
            $this->write($current);

            return $this->confirmationResponse($current);
        });
    }

    /** @return array<string, mixed> */
    public function rotateRecovery(
        string $derivationKey,
        string $ownerCookie,
        string $requestId,
        int $now,
    ): array {
        $key = $this->decodeKey($derivationKey);
        $this->requireTimestamp($now);
        if (!$this->validUuid($requestId) || !$this->parseCapability($ownerCookie, 'mbuo1')) {
            $this->fail('UPGRADE_SESSION_UNAUTHORIZED');
        }

        return $this->files->withSessionAuthLock(function () use ($key, $ownerCookie, $requestId, $now): array {
            $current = $this->requireSession();
            if (!$this->ownerMatches($current, $ownerCookie, $now)) {
                $this->fail('UPGRADE_SESSION_UNAUTHORIZED');
            }
            if ($current['copy_confirmation_pending']) {
                $pending = $current['pending_request'];
                if (!is_array($pending) || $pending['action'] !== 'rotate'
                    || $pending['request_id'] !== $requestId
                    || $pending['result_revision'] !== $current['revision']) {
                    $this->fail('UPGRADE_SESSION_CONFIRMATION_REQUIRED');
                }

                return $this->rotationResponse($key, $current, $ownerCookie, $requestId, 'rotate', $ownerCookie);
            }
            $this->incrementRevision($current);
            $response = $this->rotationResponse(
                $key,
                $current,
                $ownerCookie,
                $requestId,
                'rotate',
                $ownerCookie,
            );
            $current['previous_recovery_sha256'] = $current['recovery_sha256'];
            $current['recovery_sha256'] = $this->hashCapability($response['recovery_credential']);
            $current['copy_confirmation_pending'] = true;
            $current['pending_request'] = [
                'action' => 'rotate',
                'request_id' => $requestId,
                'result_revision' => $current['revision'],
                'issued_at' => $now,
            ];
            $current['confirmation_receipt'] = null;
            $current['last_seen_at'] = max($current['last_seen_at'], $now);
            $this->write($current);

            return $response;
        });
    }

    /** @return array<string, mixed> */
    public function takeover(
        string $derivationKey,
        string $recoveryCredential,
        string $requestId,
        int $now,
    ): array {
        $key = $this->decodeKey($derivationKey);
        $this->requireTimestamp($now);
        $parsed = $this->parseCapability($recoveryCredential, 'mbur1');
        if ($parsed === null || !$this->validUuid($requestId)) {
            $this->fail('UPGRADE_SESSION_UNAUTHORIZED');
        }

        return $this->files->withSessionAuthLock(function () use (
            $key,
            $recoveryCredential,
            $requestId,
            $now,
            $parsed,
        ): array {
            $current = $this->requireSession();
            if ($current['session_id'] !== $parsed['session_id'] || !$this->recoveryWithinLifetime($current, $now)) {
                $this->fail('UPGRADE_SESSION_UNAUTHORIZED');
            }
            $credentialHash = $this->hashCapability($recoveryCredential);
            $pending = $current['pending_request'];
            if ($current['copy_confirmation_pending'] && is_array($pending)
                && $pending['action'] === 'takeover' && $pending['request_id'] === $requestId
                && $pending['result_revision'] === $current['revision']
                && is_string($current['previous_recovery_sha256'])
                && hash_equals($current['previous_recovery_sha256'], $credentialHash)) {
                return $this->rotationResponse(
                    $key,
                    $current,
                    $this->deriveOwnerCookie($key, $current['session_id'], 'takeover', $requestId, $current['revision'], $recoveryCredential),
                    $requestId,
                    'takeover',
                    $recoveryCredential,
                );
            }
            if (!hash_equals($current['recovery_sha256'], $credentialHash)) {
                $this->fail('UPGRADE_SESSION_UNAUTHORIZED');
            }
            if ($current['copy_confirmation_pending']) {
                $this->fail('UPGRADE_SESSION_UNAUTHORIZED');
            }
            if ($current['owner_sha256'] !== null && $now - $current['last_seen_at'] < self::STALE_SECONDS) {
                $this->fail('UPGRADE_OWNER_NOT_STALE');
            }

            $this->incrementRevision($current);
            $newOwner = $this->deriveOwnerCookie(
                $key,
                $current['session_id'],
                'takeover',
                $requestId,
                $current['revision'],
                $recoveryCredential,
            );
            $response = $this->rotationResponse(
                $key,
                $current,
                $newOwner,
                $requestId,
                'takeover',
                $recoveryCredential,
            );
            $current['owner_sha256'] = $this->hashCapability($newOwner);
            $current['previous_recovery_sha256'] = $credentialHash;
            $current['recovery_sha256'] = $this->hashCapability($response['recovery_credential']);
            $current['copy_confirmation_pending'] = true;
            $current['pending_request'] = [
                'action' => 'takeover',
                'request_id' => $requestId,
                'result_revision' => $current['revision'],
                'issued_at' => $now,
            ];
            $current['confirmation_receipt'] = null;
            $current['owner_expires_at'] = min(self::MAX_TIMESTAMP, $now + self::OWNER_LIFETIME);
            $current['last_seen_at'] = $now;
            $this->write($current);

            return $response;
        });
    }

    /** @return array<string, mixed> */
    private function createResponse(
        string $key,
        string $sessionId,
        string $requestId,
        int $revision,
        string $adminId,
    ): array {
        $ownerCookie = $this->deriveOwnerCookie($key, $sessionId, 'create', $requestId, $revision, $adminId);
        $recovery = $this->deriveRecoveryCredential($key, $sessionId, 'create', $requestId, $revision, $adminId);

        return [
            'session_id' => $sessionId,
            'owner_cookie' => $ownerCookie,
            'recovery_credential' => $recovery,
            'confirmation_nonce' => $this->confirmationNonce($key, $sessionId, $requestId, $revision),
            'request_id' => $requestId,
            'revision' => $revision,
            'copy_confirmation_pending' => true,
        ];
    }

    /** @param array<string, mixed> $session @return array<string, mixed> */
    private function rotationResponse(
        string $key,
        array $session,
        string $ownerCookie,
        string $requestId,
        string $action,
        string $context,
    ): array {
        $revision = $session['revision'];

        return [
            'session_id' => $session['session_id'],
            'owner_cookie' => $ownerCookie,
            'recovery_credential' => $this->deriveRecoveryCredential(
                $key,
                $session['session_id'],
                $action,
                $requestId,
                $revision,
                $context,
            ),
            'confirmation_nonce' => $this->confirmationNonce(
                $key,
                $session['session_id'],
                $requestId,
                $revision,
            ),
            'request_id' => $requestId,
            'revision' => $revision,
            'copy_confirmation_pending' => true,
        ];
    }

    private function deriveOwnerCookie(
        string $key,
        string $sessionId,
        string $action,
        string $requestId,
        int $revision,
        string $context,
    ): string {
        return 'mbuo1.' . $sessionId . '.' . $this->deriveValue($key, $action . '-owner', [
            $sessionId, $requestId, (string) $revision, $context,
        ]);
    }

    private function deriveRecoveryCredential(
        string $key,
        string $sessionId,
        string $action,
        string $requestId,
        int $revision,
        string $context,
    ): string {
        return 'mbur1.' . $sessionId . '.' . $this->deriveValue($key, $action . '-recovery', [
            $sessionId, $requestId, (string) $revision, $context,
        ]);
    }

    private function deriveUuid(string $key, string $label, array $fields): string
    {
        $bytes = substr($this->deriveBytes($key, $label, $fields), 0, 16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private function deriveValue(string $key, string $label, array $fields): string
    {
        return rtrim(strtr(base64_encode($this->deriveBytes($key, $label, $fields)), '+/', '-_'), '=');
    }

    private function confirmationNonce(string $key, string $sessionId, string $requestId, int $revision): string
    {
        return $this->deriveValue($key, 'confirmation-nonce', [
            $sessionId,
            $requestId,
            (string) $revision,
        ]);
    }

    /** @param array<string, mixed> $session */
    private function ownerCsrfNonce(string $ownerCookie, array $session): string
    {
        $message = "mallbase-upgrade-csrf-v1\0"
            . $this->frame($session['session_id'])
            . $this->frame((string) $session['revision']);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $message, $ownerCookie, true)), '+/', '-_'), '=');
    }

    private function deriveBytes(string $key, string $label, array $fields): string
    {
        $message = "mallbase-upgrade-capability-v1\0" . $this->frame($label);
        foreach ($fields as $field) {
            $message .= $this->frame((string) $field);
        }

        return hash_hmac('sha256', $message, $key, true);
    }

    private function frame(string $value): string
    {
        return pack('N', strlen($value)) . $value;
    }

    /** @return array{session_id:string,value:string}|null */
    private function parseCapability(string $value, string $prefix): ?array
    {
        $quoted = preg_quote($prefix, '/');
        if (preg_match('/^' . $quoted . '\.([0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})\.([A-Za-z0-9_-]{43})$/D', $value, $match) !== 1
            || !$this->validBase64Url32($match[2])) {
            return null;
        }

        return ['session_id' => $match[1], 'value' => $match[2]];
    }

    private function decodeKey(string $value): string
    {
        if (!$this->validBase64Url32($value)) {
            $this->fail('UPGRADE_SESSION_ARGUMENT_INVALID');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/') . '=', true);
        if (!is_string($decoded) || strlen($decoded) !== 32) {
            $this->fail('UPGRADE_SESSION_ARGUMENT_INVALID');
        }

        return $decoded;
    }

    private function validBase64Url32(string $value): bool
    {
        if (strlen($value) !== 43 || preg_match('/^[A-Za-z0-9_-]{43}$/D', $value) !== 1) {
            return false;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/') . '=', true);

        return is_string($decoded) && strlen($decoded) === 32
            && rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') === $value;
    }

    /** @return array<string, mixed>|null */
    private function loadUnlocked(): ?array
    {
        $document = $this->files->readJson('session_auth');
        if ($document === null) {
            return null;
        }
        try {
            return $this->validateDocument($document);
        } catch (Throwable) {
            $this->fail('UPGRADE_SESSION_INVALID');
        }
    }

    /** @return array<string, mixed> */
    private function requireSession(): array
    {
        return $this->loadUnlocked() ?? $this->fail('UPGRADE_SESSION_UNAUTHORIZED');
    }

    /** @return array<string, mixed> */
    private function validateDocument(object $document): array
    {
        $raw = get_object_vars($document);
        if (!$this->hasExactFields($raw, self::TOP_LEVEL_FIELDS)
            || !is_int($raw['schema_version']) || $raw['schema_version'] !== 1
            || !is_string($raw['instance_id']) || !$this->validUuid($raw['instance_id'])
            || !is_string($raw['session_id']) || !$this->validUuid($raw['session_id'])
            || !($raw['job_id'] === null || is_string($raw['job_id']) && $this->validUuid($raw['job_id']))
            || !($raw['owner_sha256'] === null || is_string($raw['owner_sha256']) && $this->validHash($raw['owner_sha256']))
            || !is_string($raw['recovery_sha256']) || !$this->validHash($raw['recovery_sha256'])
            || !($raw['previous_recovery_sha256'] === null
                || is_string($raw['previous_recovery_sha256']) && $this->validHash($raw['previous_recovery_sha256']))
            || !is_bool($raw['copy_confirmation_pending'])
            || !($raw['last_break_glass_event_id'] === null
                || is_string($raw['last_break_glass_event_id']) && $this->validUuid($raw['last_break_glass_event_id']))
            || !$this->isTimestamp($raw['issued_at']) || !$this->isTimestamp($raw['owner_expires_at'])
            || !($raw['recovery_expires_at'] === null || $this->isTimestamp($raw['recovery_expires_at']))
            || !$this->isTimestamp($raw['last_seen_at'])
            || !is_int($raw['revision']) || $raw['revision'] < 1
            || $raw['owner_expires_at'] < $raw['issued_at'] || $raw['last_seen_at'] < $raw['issued_at']) {
            $this->fail('UPGRADE_SESSION_INVALID');
        }
        $pending = $this->validatePending($raw['pending_request']);
        $receipt = $this->validateReceipt($raw['confirmation_receipt']);
        if ($raw['copy_confirmation_pending']) {
            if ($pending === null || $pending['result_revision'] !== $raw['revision'] || $receipt !== null) {
                $this->fail('UPGRADE_SESSION_INVALID');
            }
        } elseif ($pending !== null) {
            $this->fail('UPGRADE_SESSION_INVALID');
        }

        return [
            'schema_version' => $raw['schema_version'],
            'instance_id' => $raw['instance_id'],
            'session_id' => $raw['session_id'],
            'job_id' => $raw['job_id'],
            'owner_sha256' => $raw['owner_sha256'],
            'recovery_sha256' => $raw['recovery_sha256'],
            'previous_recovery_sha256' => $raw['previous_recovery_sha256'],
            'copy_confirmation_pending' => $raw['copy_confirmation_pending'],
            'pending_request' => $pending,
            'confirmation_receipt' => $receipt,
            'last_break_glass_event_id' => $raw['last_break_glass_event_id'],
            'issued_at' => $raw['issued_at'],
            'owner_expires_at' => $raw['owner_expires_at'],
            'recovery_expires_at' => $raw['recovery_expires_at'],
            'last_seen_at' => $raw['last_seen_at'],
            'revision' => $raw['revision'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function validatePending(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!$value instanceof stdClass) {
            $this->fail('UPGRADE_SESSION_INVALID');
        }
        $raw = get_object_vars($value);
        if (!$this->hasExactFields($raw, self::PENDING_FIELDS)
            || !is_string($raw['action']) || !in_array($raw['action'], ['create', 'rotate', 'takeover'], true)
            || !is_string($raw['request_id']) || !$this->validUuid($raw['request_id'])
            || !is_int($raw['result_revision']) || $raw['result_revision'] < 1
            || !$this->isTimestamp($raw['issued_at'])) {
            $this->fail('UPGRADE_SESSION_INVALID');
        }

        return $raw;
    }

    /** @return array<string, mixed>|null */
    private function validateReceipt(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!$value instanceof stdClass) {
            $this->fail('UPGRADE_SESSION_INVALID');
        }
        $raw = get_object_vars($value);
        if (!$this->hasExactFields($raw, self::RECEIPT_FIELDS)
            || !is_string($raw['request_id']) || !$this->validUuid($raw['request_id'])
            || !is_int($raw['result_revision']) || $raw['result_revision'] < 1
            || !$this->isTimestamp($raw['confirmed_at'])) {
            $this->fail('UPGRADE_SESSION_INVALID');
        }

        return $raw;
    }

    /** @param array<string, mixed> $document */
    private function write(array $document): void
    {
        $this->files->writeJson('session_auth', $this->toObject($document));
    }

    /** @param array<string, mixed> $session */
    private function ownerMatches(array $session, string $ownerCookie, int $now): bool
    {
        $parsed = $this->parseCapability($ownerCookie, 'mbuo1');
        return $parsed !== null && $parsed['session_id'] === $session['session_id']
            && is_string($session['owner_sha256']) && $now <= $session['owner_expires_at']
            && hash_equals($session['owner_sha256'], $this->hashCapability($ownerCookie));
    }

    /** @param array<string, mixed> $session */
    private function recoveryWithinLifetime(array $session, int $now): bool
    {
        return $session['recovery_expires_at'] === null || $now <= $session['recovery_expires_at'];
    }

    /** @param array<string, mixed> $session @return array<string, mixed> */
    private function principal(array $session): array
    {
        return [
            'session_id' => $session['session_id'],
            'owner_sha256' => $session['owner_sha256'],
            'job_id' => $session['job_id'],
            'revision' => $session['revision'],
            'copy_confirmation_pending' => $session['copy_confirmation_pending'],
        ];
    }

    /** @param array<string, mixed> $session @return array<string, mixed> */
    private function confirmationResponse(array $session): array
    {
        return [
            'session_id' => $session['session_id'],
            'request_id' => $session['confirmation_receipt']['request_id'],
            'revision' => $session['confirmation_receipt']['result_revision'],
            'copy_confirmation_pending' => false,
        ];
    }

    /** @param array<string, mixed> $document */
    private function incrementRevision(array &$document): void
    {
        if ($document['revision'] === PHP_INT_MAX) {
            $this->fail('UPGRADE_SESSION_REVISION_EXHAUSTED');
        }
        $document['revision']++;
    }

    private function hashCapability(string $value): string
    {
        return 'sha256:' . hash('sha256', $value);
    }

    private function validHash(string $value): bool
    {
        return preg_match('/^sha256:[0-9a-f]{64}$/D', $value) === 1;
    }

    private function validUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }

    private function isTimestamp(mixed $value): bool
    {
        return is_int($value) && $value >= 0 && $value <= self::MAX_TIMESTAMP;
    }

    private function requireTimestamp(int $value): void
    {
        if (!$this->isTimestamp($value)) {
            $this->fail('UPGRADE_SESSION_ARGUMENT_INVALID');
        }
    }

    private function hasExactFields(array $document, array $expected): bool
    {
        return count($document) === count($expected)
            && array_diff(array_keys($document), $expected) === []
            && array_diff($expected, array_keys($document)) === [];
    }

    private function toObject(array $value): object
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    private function fail(string $code): never
    {
        throw new RuntimeException($code);
    }
}
