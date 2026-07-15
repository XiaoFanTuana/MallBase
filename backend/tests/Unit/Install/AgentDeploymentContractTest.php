<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use PHPUnit\Framework\TestCase;

final class AgentDeploymentContractTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = dirname(__DIR__, 4);
    }

    public function testProductionUsesOrdinaryDockerBuildAndKeepsThreeBusinessRoles(): void
    {
        $compose = $this->read('docker-compose.yml');

        self::assertStringContainsString('dockerfile: deploy/docker/Dockerfile', $compose);
        self::assertStringContainsString('image: ${MALLBASE_BACKEND_IMAGE:-mallbase-backend:latest}', $compose);
        self::assertStringContainsString('x-mallbase-runtime: &mallbase-runtime', $compose);
        self::assertStringContainsString('sh deploy/docker/host-preflight.sh', $compose);
        self::assertStringContainsString('docker compose up -d --build backend queue cron', $compose);
        self::assertSame(3, substr_count($compose, '<<: *mallbase-runtime'));
        foreach (['http', 'queue', 'cron'] as $role) {
            self::assertStringContainsString('MALLBASE_RUNTIME_ROLE: ' . $role, $compose);
        }
        self::assertStringContainsString(
            'command: ["php", "think", "queue:work", "redis", "--queue=default", "--tries=3"]',
            $compose,
        );
        self::assertStringContainsString('read_only: true', $compose);
        self::assertStringContainsString('no-new-privileges:true', $compose);
        self::assertMatchesRegularExpression('/cap_drop:\s+- ALL/s', $compose);
    }

    public function testComposeMountsOnlyTheSimpleUpgradeSharedSurface(): void
    {
        $production = $this->read('docker-compose.yml');
        $development = $this->read('docker-compose.dev.yml');

        foreach ([$production, $development] as $compose) {
            foreach ([
                ['./upgrade/bin', '/app/upgrade/bin'],
                ['./upgrade/config', '/app/upgrade/config'],
                ['./upgrade/run', '/app/upgrade/run'],
                ['./upgrade/jobs', '/app/upgrade/jobs'],
                ['./upgrade/backups', '/app/upgrade/backups'],
            ] as [$source, $target]) {
                self::assertStringContainsString('source: ' . $source, $compose);
                self::assertStringContainsString('target: ' . $target, $compose);
            }
        }

        foreach ([$production, $development] as $compose) {
            foreach (['storage-ready', 'storage-cutover', 'layout-generation', 'sealed'] as $legacy) {
                self::assertStringNotContainsString($legacy, $compose);
            }
        }
        foreach ([$production, $development] as $compose) {
            self::assertStringNotContainsString('bootstrap-retention', $compose);
        }
        foreach (['env', 'cert', 'demo', 'public-storage'] as $directory) {
            self::assertStringContainsString(
                'source: ./data/backend/' . $directory,
                $production,
                'persistent backend data must be mounted from data/backend',
            );
        }
    }

    public function testProductionKeepsBusinessDataInPlainNamedVolumes(): void
    {
        $compose = $this->read('docker-compose.yml');

        foreach ([
            ['backend_runtime', '/app/runtime'],
            ['backend_uploads', '/app/public/uploads'],
        ] as [$source, $target]) {
            self::assertStringContainsString('source: ' . $source, $compose);
            self::assertStringContainsString('target: ' . $target, $compose);
        }

        self::assertStringNotContainsString('com.mallbase.storage.', $compose);
        self::assertStringNotContainsString('nocopy: true', $compose);
        self::assertStringNotContainsString('MALLBASE_STORAGE_NAMESPACE', $compose);
        self::assertStringContainsString('MALLBASE_RUNTIME_VOLUME_NAME', $compose);
        self::assertStringContainsString('MALLBASE_UPLOADS_VOLUME_NAME', $compose);
        self::assertStringContainsString('name: "${MALLBASE_RUNTIME_VOLUME_NAME:-mallbase_runtime}"', $compose);
        self::assertStringContainsString('name: "${MALLBASE_UPLOADS_VOLUME_NAME:-mallbase_uploads}"', $compose);
    }

    public function testProductionDockerfileHasNoSealedOrCutoverRuntime(): void
    {
        $dockerfile = $this->read('deploy/docker/Dockerfile');

        self::assertMatchesRegularExpression(
            '/^FROM phpswoole\/swoole:php8\.2-alpine@sha256:[0-9a-f]{64} AS mallbase-runtime/m',
            $dockerfile,
        );
        self::assertStringContainsString('USER mallbase', $dockerfile);
        self::assertStringContainsString('ENTRYPOINT ["docker-entrypoint.sh"]', $dockerfile);
        foreach ([
            'sealed-context-validation', '.mallbase-sealed-context.json',
            '.mallbase-deployment.json', 'validate-sealed-attestation',
            'legacy-state-', 'target-state-verify', 'validate-storage-cutover',
            'runtime-init.sh',
        ] as $legacy) {
            self::assertStringNotContainsString($legacy, $dockerfile);
        }

        $dockerignore = $this->read('.dockerignore');
        self::assertMatchesRegularExpression('/^\/data\/$/m', $dockerignore);
    }

    public function testHostPreflightOnlyPreparesTheSimpleUpgradeWorkspace(): void
    {
        $preflight = $this->read('deploy/docker/host-preflight.sh');

        foreach ([
            'config', 'run', 'jobs', 'backups', 'packages', 'agent-private', 'staging',
        ] as $directory) {
            self::assertStringContainsString('"$UPGRADE_ROOT/' . $directory . '"', $preflight);
        }
        foreach (['env', 'cert', 'demo', 'public-storage'] as $directory) {
            self::assertStringContainsString('"$BACKEND_DATA_ROOT/' . $directory . '"', $preflight);
        }
        foreach ([
            'storage-init-results', 'legacy-import', 'legacy-results',
            'bootstrap-retention', 'prepare-cutover', 'prepare-bootstrap-adopt',
            'MALLBASE_STORAGE_NAMESPACE',
        ] as $legacy) {
            self::assertStringNotContainsString($legacy, $preflight);
        }
        self::assertStringContainsString('"$UPGRADE_ROOT/run/requests"', $preflight);
        self::assertStringContainsString('mallbase-agent-linux-$AGENT_ARCHITECTURE', $preflight);
        self::assertStringContainsString('MALLBASE_AGENT_USER', $preflight);
    }

    public function testSystemdStartsOneAgentProcessForEachQueuedJob(): void
    {
        $pathUnit = $this->read('deploy/systemd/mallbase-agent@.path');
        $serviceUnit = $this->read('deploy/systemd/mallbase-agent@.service');

        self::assertStringContainsString('PathExistsGlob=%f/upgrade/run/requests/*.json', $pathUnit);
        self::assertStringContainsString('Unit=mallbase-agent@%i.service', $pathUnit);
        self::assertStringContainsString('ExecStart=%f/upgrade/bin/mallbase-agent run-job', $serviceUnit);
        self::assertStringContainsString('User=mallbase-agent', $serviceUnit);
        self::assertStringContainsString('Group=mallbase-upgrade', $serviceUnit);
        self::assertStringNotContainsString(' serve', $pathUnit . $serviceUnit);
    }

    public function testProductionBackendPortIsLoopbackOnly(): void
    {
        $compose = $this->read('docker-compose.yml');

        self::assertStringContainsString(
            '127.0.0.1:${SWOOLE_HTTP_PORT:-8080}:${SWOOLE_HTTP_PORT:-8080}',
            $compose,
        );
    }

    public function testLegacyDeploymentFilesAndCommandsAreRemoved(): void
    {
        foreach ([
            'docker-compose.storage-adoption.yml',
            'docker-compose.storage-bootstrap.yml',
            'docker-compose.storage-cutover.yml',
            '.mallbase-deployment.json.example',
            'deploy/docker/bootstrap-permission-normalize.sh',
            'deploy/docker/bootstrap-retention-export.sh',
            'deploy/docker/bootstrap-retention-import.sh',
            'deploy/docker/bootstrap-retention-probe.php',
            'deploy/docker/bootstrap-retention-verify.sh',
            'deploy/docker/build-sealed-image.sh',
            'deploy/docker/fresh-storage-bootstrap.sh',
            'deploy/docker/fresh-storage-inspect.sh',
            'deploy/docker/fresh-storage-stamp.sh',
            'deploy/docker/legacy-state-export-verify.sh',
            'deploy/docker/legacy-state-import.sh',
            'deploy/docker/run-target-php.php',
            'deploy/docker/runtime-init.sh',
            'deploy/docker/start-sealed-image.sh',
            'deploy/docker/storage-cutover.sh',
            'deploy/docker/target-state-verify.sh',
            'deploy/docker/validate-bootstrap-adoption.php',
            'deploy/docker/validate-fresh-storage.php',
            'deploy/docker/validate-sealed-attestation.php',
            'deploy/docker/validate-storage-cutover.php',
            'backend/app/command/StorageCutoverTargetSnapshot.php',
            'backend/app/command/UpgradeBootstrapRetentionFinalize.php',
            'backend/app/command/UpgradeAdminSchema.php',
            'backend/app/command/UpgradeClientDecorationCustomMenu.php',
            'backend/app/command/UpgradeClientSearchSchema.php',
            'backend/app/command/UpgradeUserRegisterType.php',
            'backend/app/command/UpgradeUserWechatSchema.php',
            'backend/app/validate/admin/upgrade/UpgradeRequest.php',
            'backend/bin/upgrade-process-guard.php',
        ] as $file) {
            self::assertFileDoesNotExist($this->projectRoot . '/' . $file, $file);
        }

        $console = $this->read('backend/config/console.php');
        self::assertStringNotContainsString('storage-cutover', $console);
        self::assertStringNotContainsString('bootstrap-retention', $console);
        self::assertStringNotContainsString('upgrade:admin-schema', $console);
        self::assertStringNotContainsString('upgrade:client-decoration-custom-menu', $console);
        self::assertStringNotContainsString('upgrade:client-search-schema', $console);
        self::assertStringNotContainsString('upgrade:user-register-type', $console);
        self::assertStringNotContainsString('upgrade:user-wechat-schema', $console);
    }

    public function testReleasedAgentBinariesAndChecksumManifestRemainConsistent(): void
    {
        $bin = $this->projectRoot . '/upgrade/bin';
        $checksums = $bin . '/checksums.sha256';
        self::assertFileExists($checksums);
        self::assertSame(0444, fileperms($checksums) & 0777);

        $expected = [];
        foreach (file($checksums, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}  mallbase-agent-linux-(?:amd64|arm64)$/D', $line);
            [$digest, $name] = explode('  ', $line, 2);
            $expected[$name] = $digest;
        }
        self::assertSame(['mallbase-agent-linux-amd64', 'mallbase-agent-linux-arm64'], array_keys($expected));

        foreach ($expected as $name => $digest) {
            $path = $bin . '/' . $name;
            self::assertFileExists($path);
            self::assertSame(0555, fileperms($path) & 0777);
            self::assertSame($digest, hash_file('sha256', $path));
        }
    }

    public function testProductionShellSignalTrapsDoNotOverwriteExitCleanup(): void
    {
        foreach (glob($this->projectRoot . '/deploy/docker/*.sh') ?: [] as $path) {
            if (str_ends_with($path, '_test.sh')) {
                continue;
            }
            $script = file_get_contents($path);
            self::assertIsString($script);
            self::assertDoesNotMatchRegularExpression(
                '/^trap[ \t]+(?!-[ \t])[^\r\n]*[ \t]+(?:0|EXIT)[ \t]+(?:HUP|INT|TERM)(?:[ \t]|$)/m',
                $script,
                basename($path) . ' must keep signal and exit cleanup separate',
            );
        }
    }

    private function read(string $relative): string
    {
        $contents = file_get_contents($this->projectRoot . '/' . $relative);
        self::assertIsString($contents);

        return $contents;
    }
}
