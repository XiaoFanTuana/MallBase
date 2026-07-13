<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\UpgradeSessionAuthStore;
use app\service\upgrade\UpgradeSharedFileStore;
use PHPUnit\Framework\TestCase;

final class UpgradeSessionAuthStoreTest extends TestCase
{
    private const AGENT_UID = 33001;
    private const SHARED_GID = 33002;
    private const PHP_UID = 33003;
    private const INSTANCE_ID = 'd3ec761b-c5d1-4663-8c76-7d2d351efad5';
    private const CREATE_REQUEST = '11111111-1111-4111-8111-111111111111';
    private const ROTATE_REQUEST = '22222222-2222-4222-8222-222222222222';
    private const TAKEOVER_REQUEST = '33333333-3333-4333-8333-333333333333';

    private string $root;
    private UpgradeSharedFileStore $files;
    private UpgradeSessionAuthStore $sessions;
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/mallbase-session-auth-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0750, true);
        mkdir($this->root . '/run', 02770);
        mkdir($this->root . '/jobs', 02770);
        chmod($this->root, 0750);
        chmod($this->root . '/run', 02770);
        chmod($this->root . '/jobs', 02770);
        $this->files = new UpgradeSharedFileStore(
            $this->root,
            self::AGENT_UID,
            self::SHARED_GID,
            self::PHP_UID,
            65536,
            50,
            $this->statOperations(),
        );
        $this->sessions = new UpgradeSessionAuthStore($this->files);
        $this->key = rtrim(strtr(base64_encode(str_repeat("\x42", 32)), '+/', '-_'), '=');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testCreateReplayConfirmRotateAndTakeoverAreLockLinearizedAndSecretFreeAtRest(): void
    {
        $created = $this->sessions->create(
            self::INSTANCE_ID,
            $this->key,
            1,
            self::CREATE_REQUEST,
            1000,
        );
        $this->assertMatchesRegularExpression($this->ownerPattern(), $created['owner_cookie']);
        $this->assertMatchesRegularExpression($this->recoveryPattern(), $created['recovery_credential']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $created['confirmation_nonce']);
        $this->assertSame($created, $this->sessions->create(
            self::INSTANCE_ID,
            $this->key,
            1,
            self::CREATE_REQUEST,
            1010,
        ));
        $this->assertFailure('UPGRADE_SESSION_EXISTS', fn() => $this->sessions->create(
            self::INSTANCE_ID,
            $this->key,
            1,
            self::ROTATE_REQUEST,
            1010,
        ));

        $raw = (string) file_get_contents($this->root . '/run/session-auth.json');
        $this->assertStringNotContainsString($created['owner_cookie'], $raw);
        $this->assertStringNotContainsString($created['recovery_credential'], $raw);
        $this->assertStringNotContainsString($this->key, $raw);
        $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $document['schema_version']);
        $this->assertSame(1, $document['revision']);
        $this->assertTrue($document['copy_confirmation_pending']);
        $this->assertSame('create', $document['pending_request']['action']);

        $this->assertFailure('UPGRADE_SESSION_UNAUTHORIZED', fn() => $this->sessions->confirm(
            $this->key,
            $created['owner_cookie'],
            self::CREATE_REQUEST,
            str_repeat('A', 43),
            1011,
        ));
        $confirmed = $this->sessions->confirm(
            $this->key,
            $created['owner_cookie'],
            self::CREATE_REQUEST,
            $created['confirmation_nonce'],
            1011,
        );
        $this->assertFalse($confirmed['copy_confirmation_pending']);
        $this->assertSame($confirmed, $this->sessions->confirm(
            $this->key,
            $created['owner_cookie'],
            self::CREATE_REQUEST,
            $created['confirmation_nonce'],
            1012,
        ));

        $principal = $this->sessions->authenticateOwner($created['owner_cookie'], 1012);
        $this->assertSame($created['session_id'], $principal['session_id']);
        $this->assertSame(2, $principal['revision']);

        $rotated = $this->sessions->rotateRecovery(
            $this->key,
            $created['owner_cookie'],
            self::ROTATE_REQUEST,
            1020,
        );
        $this->assertSame($created['owner_cookie'], $rotated['owner_cookie']);
        $this->assertNotSame($created['recovery_credential'], $rotated['recovery_credential']);
        $this->assertSame($rotated, $this->sessions->rotateRecovery(
            $this->key,
            $created['owner_cookie'],
            self::ROTATE_REQUEST,
            1021,
        ));
        $this->sessions->confirm(
            $this->key,
            $created['owner_cookie'],
            self::ROTATE_REQUEST,
            $rotated['confirmation_nonce'],
            1022,
        );

        $this->assertFailure('UPGRADE_OWNER_NOT_STALE', fn() => $this->sessions->takeover(
            $this->key,
            $rotated['recovery_credential'],
            self::TAKEOVER_REQUEST,
            1081,
        ));
        $taken = $this->sessions->takeover(
            $this->key,
            $rotated['recovery_credential'],
            self::TAKEOVER_REQUEST,
            1082,
        );
        $this->assertNotSame($created['owner_cookie'], $taken['owner_cookie']);
        $this->assertNotSame($rotated['recovery_credential'], $taken['recovery_credential']);
        $this->assertSame($taken, $this->sessions->takeover(
            $this->key,
            $rotated['recovery_credential'],
            self::TAKEOVER_REQUEST,
            1083,
        ));
        $this->assertFailure('UPGRADE_SESSION_UNAUTHORIZED', fn() => $this->sessions->authenticateOwner(
            $created['owner_cookie'],
            1083,
        ));
        $this->sessions->confirm(
            $this->key,
            $taken['owner_cookie'],
            self::TAKEOVER_REQUEST,
            $taken['confirmation_nonce'],
            1084,
        );
        $this->assertFailure('UPGRADE_SESSION_UNAUTHORIZED', fn() => $this->sessions->takeover(
            $this->key,
            $rotated['recovery_credential'],
            self::TAKEOVER_REQUEST,
            1200,
        ));
    }

    public function testHostBreakGlassCredentialCanTakeOverWithoutAnOwner(): void
    {
        $sessionId = '44444444-4444-4444-8444-444444444444';
        $credential = 'mbur1.' . $sessionId . '.'
            . rtrim(strtr(base64_encode(str_repeat("\x55", 32)), '+/', '-_'), '=');
        $this->files->writeJson('session_auth', (object) [
            'schema_version' => 1,
            'instance_id' => self::INSTANCE_ID,
            'session_id' => $sessionId,
            'job_id' => null,
            'owner_sha256' => null,
            'recovery_sha256' => $this->hash($credential),
            'previous_recovery_sha256' => null,
            'copy_confirmation_pending' => false,
            'pending_request' => null,
            'confirmation_receipt' => null,
            'last_break_glass_event_id' => '55555555-5555-4555-8555-555555555555',
            'issued_at' => 1000,
            'owner_expires_at' => 87400,
            'recovery_expires_at' => 87400,
            'last_seen_at' => 1000,
            'revision' => 2,
        ]);

        $result = $this->sessions->takeover($this->key, $credential, self::TAKEOVER_REQUEST, 1001);

        $this->assertMatchesRegularExpression($this->ownerPattern(), $result['owner_cookie']);
        $this->assertSame($sessionId, $result['session_id']);
    }

    public function testJobBindingAndControlMutationRevalidateRevisionUnderSessionLock(): void
    {
        $jobId = '018f5d35-3f42-7a31-a731-9e45df3356c2';
        $created = $this->sessions->create(self::INSTANCE_ID, $this->key, 1, self::CREATE_REQUEST, 1000);
        $this->sessions->confirm(
            $this->key,
            $created['owner_cookie'],
            self::CREATE_REQUEST,
            $created['confirmation_nonce'],
            1001,
        );
        $published = 0;
        $bound = $this->sessions->bindJob(
            $created['owner_cookie'],
            $jobId,
            2,
            1002,
            static function (array $session) use (&$published, $jobId): void {
                self::assertSame($jobId, $session['job_id']);
                $published++;
            },
        );
        self::assertSame(3, $bound['revision']);
        self::assertSame($jobId, $bound['job_id']);

        $replayed = $this->sessions->bindJob(
            $created['owner_cookie'],
            $jobId,
            2,
            1003,
            static function () use (&$published): void {
                $published++;
            },
        );
        self::assertSame($bound, $replayed);
        self::assertSame(2, $published);
        $this->assertFailure('UPGRADE_SESSION_CONFLICT', fn() => $this->sessions->bindJob(
            $created['owner_cookie'],
            $jobId,
            3,
            1004,
            static fn() => null,
        ));

        $result = $this->sessions->withAuthorizedJobMutation(
            $created['owner_cookie'],
            $jobId,
            3,
            1004,
            static fn(array $session): array => ['job_id' => $session['job_id'], 'revision' => $session['revision']],
        );
        self::assertSame(['job_id' => $jobId, 'revision' => 3], $result);
        $this->assertFailure('UPGRADE_SESSION_CONFLICT', fn() => $this->sessions->withAuthorizedJobMutation(
            $created['owner_cookie'],
            $jobId,
            2,
            1004,
            static fn(): array => [],
        ));
    }

    public function testStrictSchemaRejectsMissingNullableUnknownAndInvalidNestedFields(): void
    {
        $created = $this->sessions->create(self::INSTANCE_ID, $this->key, 1, self::CREATE_REQUEST, 1000);
        $valid = json_decode((string) file_get_contents($this->root . '/run/session-auth.json'), true, 512, JSON_THROW_ON_ERROR);
        $cases = [];
        $cases['missing nullable'] = $valid;
        unset($cases['missing nullable']['job_id']);
        $cases['unknown'] = $valid + ['owner_cookie' => $created['owner_cookie']];
        $cases['invalid nested'] = $valid;
        $cases['invalid nested']['pending_request']['extra'] = true;
        $cases['missing nested'] = $valid;
        unset($cases['missing nested']['pending_request']['issued_at']);

        foreach ($cases as $name => $document) {
            $this->files->writeJson('session_auth', $this->toObject($document));
            $this->assertFailure('UPGRADE_SESSION_INVALID', fn() => $this->sessions->load(), $name);
        }
    }

    /** @return array<string, callable> */
    private function statOperations(): array
    {
        return [
            'lstat' => function (string $path): array|false {
                $stat = @lstat($path);
                if ($stat === false) {
                    return false;
                }
                $stat['gid'] = self::SHARED_GID;
                $stat['uid'] = $this->expectedOwner($path, ($stat['mode'] & 0170000) === 0040000);

                return $stat;
            },
            'fstat' => function ($handle): array|false {
                $stat = fstat($handle);
                if ($stat === false) {
                    return false;
                }
                $uri = (string) (stream_get_meta_data($handle)['uri'] ?? '');
                $stat['gid'] = self::SHARED_GID;
                $stat['uid'] = $this->expectedOwner($uri, ($stat['mode'] & 0170000) === 0040000);

                return $stat;
            },
        ];
    }

    private function expectedOwner(string $path, bool $directory): int
    {
        return $directory || str_ends_with($path, '/run/session-auth.json')
            || str_ends_with($path, '/run/session-auth.lock')
            ? self::AGENT_UID
            : self::PHP_UID;
    }

    private function hash(string $value): string
    {
        return 'sha256:' . hash('sha256', $value);
    }

    private function ownerPattern(): string
    {
        return '/^mbuo1\.[0-9a-f-]{36}\.[A-Za-z0-9_-]{43}$/D';
    }

    private function recoveryPattern(): string
    {
        return '/^mbur1\.[0-9a-f-]{36}\.[A-Za-z0-9_-]{43}$/D';
    }

    private function assertFailure(string $message, callable $callback, string $context = ''): void
    {
        try {
            $callback();
            self::fail('Expected ' . $message);
        } catch (\RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage(), $context);
            self::assertNull($exception->getPrevious());
        }
    }

    private function toObject(array $value): object
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @chmod($path, 0660);
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @chmod($path, 0770);
        @rmdir($path);
    }
}
