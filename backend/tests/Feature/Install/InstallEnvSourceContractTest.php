<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use PHPUnit\Framework\TestCase;

final class InstallEnvSourceContractTest extends TestCase
{
    public function testDockerBackendUsesRootEnvFileWithoutWorkspaceAlias(): void
    {
        $root = dirname(__DIR__, 4);
        $compose = (string) file_get_contents($root . '/docker-compose.dev.yml');
        $entrypoint = (string) file_get_contents($root . '/deploy/docker/docker-entrypoint.sh');
        $docs = (string) file_get_contents($root . '/docs/install/docker-backend-only.md');

        $this->assertStringNotContainsString('- .:/workspace:ro', $compose);
        $this->assertSame(
            2,
            substr_count($compose, "env_file:\n      - path: .env\n        required: false"),
        );
        $this->assertStringContainsString('"${REDIS_HOST_PORT:-6379}:6379"', $compose);
        $this->assertStringNotContainsString('"${REDIS_PORT:-6379}:6379"', $compose);
        $this->assertStringContainsString('ROOT_ENV="/workspace/.env"', $entrypoint);
        $this->assertStringContainsString('derive_backend_env', $entrypoint);
        $this->assertStringContainsString('apply_root_env_to_backend', $entrypoint);
        $this->assertStringContainsString('默认端口、单套本地环境下，可以不准备根 `.env`', $docs);
        $this->assertStringContainsString('MALLBASE_COMPOSE_PROJECT_NAME', $docs);
        $this->assertStringContainsString('MALLBASE_CONTAINER_PREFIX', $docs);
        $this->assertStringContainsString('`MYSQL_PORT` / `REDIS_HOST_PORT` 是方式三 MySQL / Redis 容器给宿主机暴露端口时用的变量', $docs);
        $this->assertStringContainsString('`DB_HOST` 和 `REDIS_HOST` 不是启动后端容器的必填项', $docs);
        $this->assertStringNotContainsString('cp backend/.example.env backend/.env', $docs);
        $this->assertStringContainsString('不要手动复制或编辑 `backend/.mallbase-env/backend.env`', $docs);
        $this->assertStringContainsString('MALLBASE_BACKEND_ENV_PATH: /app/.mallbase-env/backend.env', $compose);
        $this->assertStringContainsString('BACKEND_ENV=${MALLBASE_BACKEND_ENV_PATH:-/app/.mallbase-env/backend.env}', $entrypoint);
    }

    public function testInstallServiceLetsRootEnvOverrideDerivedRuntimeEnvForInstallMeta(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/app/service/install/InstallService.php');

        $this->assertStringContainsString("DIRECTORY_SEPARATOR . 'workspace' . DIRECTORY_SEPARATOR . '.env'", $source);
        $this->assertStringContainsString('return array_merge($this->readBackendEnvFile(), $this->readRootEnvFile());', $source);
    }

    public function testInstallServiceRefreshesCacheDriverAfterRuntimeEnvChange(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/app/service/install/InstallService.php');

        $this->assertStringContainsString("\$cacheManager = app()->make('cache');", $source);
        $this->assertStringContainsString("\$cacheManager->forgetDriver(['redis', 'file']);", $source);
    }

    public function testInstallerDoesNotShipDefaultAdminPassword(): void
    {
        $root = dirname(__DIR__, 4);
        $service = (string) file_get_contents($root . '/backend/app/service/install/InstallService.php');
        $page = (string) file_get_contents($root . '/backend/public/install/index.html');
        $autoInstall = (string) file_get_contents($root . '/backend/app/command/InstallAuto.php');

        $this->assertStringNotContainsString('admin123', $service);
        $this->assertStringNotContainsString('admin123', $page);
        $this->assertStringNotContainsString('admin123', $autoInstall);
        $this->assertStringContainsString("bin2hex(random_bytes(12))", $autoInstall);
        $this->assertStringContainsString('INSTALL_ADMIN_PASSWORD 至少需要 12 个字符', $autoInstall);
    }

    public function testFullStackComposeKeepsInstallDefaultsAndDataInternal(): void
    {
        $root = dirname(__DIR__, 4);
        $compose = (string) file_get_contents($root . '/docker-compose.full.yml');
        $service = (string) file_get_contents($root . '/backend/app/service/install/InstallService.php');

        $this->assertStringContainsString('MALLBASE_WEB_IMAGE', $compose);
        $this->assertStringContainsString('backend_bootstrap:/workspace:ro', $compose);
        $this->assertStringContainsString('BACKEND_ENV_EXPORT: /bootstrap/.env', $compose);
        $this->assertStringContainsString('QUEUE_CONNECTION: "${QUEUE_CONNECTION:-redis}"', $compose);
        $this->assertStringContainsString('QUEUE_REDIS_USE_CACHE_AUTH: "true"', $compose);
        $this->assertStringContainsString('${MALLBASE_BIND_HOST:-127.0.0.1}', $compose);
        $this->assertStringContainsString('backend_certs:/app/storage/cert', $compose);
        $this->assertStringContainsString('backend_config:/app/.mallbase-env', $compose);
        $this->assertStringContainsString('backend_uploads:/srv/mallbase-uploads:ro', $compose);
        $this->assertStringContainsString('MALLBASE_BACKEND_ENV_PATH: /app/.mallbase-env/backend.env', $compose);
        $this->assertStringContainsString('prepare-backend-volumes:', $compose);
        $this->assertStringContainsString('backend_demo:/app/public/static/demo', $compose);
        $this->assertStringContainsString('backend_public_storage:/app/public/storage', $compose);
        $this->assertStringContainsString('backend_upgrade:/app/upgrade', $compose);
        $this->assertSame(3, substr_count($compose, '<<: *backend-runtime'));
        foreach (['http', 'queue', 'cron'] as $role) {
            $this->assertStringContainsString('MALLBASE_RUNTIME_ROLE: ' . $role, $compose);
        }
        $this->assertSame(2, substr_count($compose, "healthcheck:\n      disable: true"));
        $this->assertStringContainsString('http://127.0.0.1:8080/healthz', $compose);
        $this->assertStringContainsString('/usr/local/bin/mallbase-healthcheck.php', $compose);
        $this->assertStringContainsString('internal: true', $compose);
        $this->assertStringContainsString('check-db-auth:', $compose);
        $this->assertStringContainsString('condition: service_completed_successfully', $compose);
        $this->assertStringNotContainsString('MYSQL_PORT:-', $compose);
        $this->assertStringNotContainsString('REDIS_HOST_PORT:-', $compose);
        $this->assertStringContainsString("\$this->envFlagText(\$get('CRON_ENABLE')) === 'true'", $service);
        $this->assertStringContainsString("\$this->envFlagText(\$get('SWOOLE_QUEUE_ENABLE')) === 'true'", $service);
        $this->assertStringContainsString("'QUEUE_CONNECTION'       => \$swooleQueueEnable ? 'redis' : 'sync'", $service);
        $this->assertStringContainsString("'QUEUE_REDIS_PASSWORD'   => \$redisConfig['password']", $service);
    }

    public function testDockerProductionUsesRootEnvFileWithoutWorkspaceMount(): void
    {
        $root = dirname(__DIR__, 4);
        $compose = (string) file_get_contents($root . '/docker-compose.yml');
        $dockerfile = (string) file_get_contents($root . '/deploy/docker/Dockerfile');
        $entrypoint = (string) file_get_contents($root . '/deploy/docker/docker-entrypoint.sh');
        $docs = (string) file_get_contents($root . '/docs/install/docker-production.md');

        $this->assertMatchesRegularExpression('/env_file:\s+- \.env/s', $compose);
        $this->assertStringNotContainsString('/workspace', $compose);
        $this->assertStringNotContainsString('backend/.env', $compose);
        $this->assertStringContainsString('COPY .version /.version', $dockerfile);
        $this->assertStringContainsString('apply_runtime_env_to_backend', $entrypoint);
        $this->assertStringContainsString('RUNTIME_TO_BACKEND_KEYS', $entrypoint);
        $this->assertStringContainsString('cp deploy/docker/.example.env .env', $docs);
        $this->assertStringContainsString('不等于安装前必须把数据库和 Redis 全部填完', $docs);
        $this->assertStringContainsString('可以先在 Web 安装向导里填写', $docs);
        $this->assertStringContainsString('安装完成后请把最终生效值同步回项目根目录 `.env`', $docs);
        $this->assertStringContainsString('不要手动复制或编辑 `backend/.env`', $docs);
        $this->assertStringContainsString('生产 compose 不挂载 `/workspace`', $docs);
        $this->assertStringContainsString('生产的三个业务角色不启动 MySQL / Redis 容器，所以不需要配置 `MYSQL_PORT` / `REDIS_HOST_PORT`', $docs);
        $this->assertStringContainsString('`backend_runtime` volume', $docs);
        $this->assertStringContainsString('`backend_uploads` volume', $docs);
        $this->assertStringContainsString('`data/backend/cert`', $docs);
    }
}
