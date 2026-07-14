/* eslint-disable test/no-import-node-test */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const apiSource = readFileSync(
  new URL('../../src/api/system/upgrade.ts', import.meta.url),
  'utf8',
);
const adminSource = readFileSync(
  new URL('../../src/views/system/upgrade/index.vue', import.meta.url),
  'utf8',
);
const maintenanceSource = readFileSync(
  new URL('../../src/views/_core/maintenance/index.vue', import.meta.url),
  'utf8',
);
const redirectSource = readFileSync(
  new URL('../../src/utils/maintenance-redirect.ts', import.meta.url),
  'utf8',
);
const requestSource = readFileSync(
  new URL('../../src/api/request.ts', import.meta.url),
  'utf8',
);
const routesSource = readFileSync(
  new URL('../../src/router/routes/core.ts', import.meta.url),
  'utf8',
);

test('Admin API is read-only except for issuing a one-time Go entry ticket', () => {
  assert.match(apiSource, /getUpgradeRecordsApi/);
  assert.match(apiSource, /\/system\/upgrade\/records/);
  assert.match(apiSource, /createUpgradeEntryApi/);
  assert.match(apiSource, /\/system\/upgrade\/session/);
  assert.match(apiSource, /probeUpgradeAgentApi/);
  assert.match(apiSource, /\/upgrade\/health/);
  assert.doesNotMatch(apiSource, /takeover|rotateRecovery|confirmRecovery/);
});

test('Admin page shows records and gates the Go page button by backend permission', () => {
  assert.match(adminSource, /getUpgradeRecordsApi/);
  assert.match(adminSource, /SystemUpgradeSessionCreate/);
  assert.match(adminSource, /v-access:code/);
  assert.match(adminSource, /backup_path/);
  assert.match(adminSource, /package_path/);
  assert.match(adminSource, /log_path/);
  assert.match(adminSource, /Go 升级程序未启动/);
  assert.match(adminSource, /等待手动部署 PHP 代码/);
  assert.match(adminSource, /window\.location\.assign\(entry\.upgrade_url\)/);
  assert.doesNotMatch(
    adminSource,
    /recovery_credential|sessionStorage|clipboard/,
  );
});

test('maintenance page points to the independent Go process without recovery credentials', () => {
  assert.match(maintenanceSource, /probeUpgradeAgentApi/);
  assert.match(maintenanceSource, /window\.location\.assign\('\/upgrade\/'\)/);
  assert.match(maintenanceSource, /PHP 代码部署由管理员手动完成/);
  assert.match(maintenanceSource, /Docker 部署.*重新构建镜像/);
  assert.match(maintenanceSource, /非 Docker 部署.*先重启 Queue\/Cron/);
  assert.match(maintenanceSource, /不会执行 Docker、systemctl 或服务重启命令/);
  assert.doesNotMatch(
    maintenanceSource,
    /recovery_credential|恢复凭据|takeover|confirmRecovery/,
  );
});

test('maintenance response redirects once before generic request error handling', () => {
  assert.match(redirectSource, /let redirecting = false/);
  assert.match(redirectSource, /SYSTEM_MAINTENANCE/);
  assert.match(redirectSource, /router\.replace\(\{ name: 'Maintenance' \}\)/);
  assert.match(requestSource, /handleMaintenanceResponse/);
  assert.ok(
    requestSource.indexOf('handleMaintenanceResponse') <
      requestSource.indexOf('defaultResponseInterceptor({'),
  );
});

test('maintenance remains a standalone core route', () => {
  assert.match(routesSource, /name: 'Maintenance'/);
  assert.match(routesSource, /path: '\/maintenance'/);
  assert.match(routesSource, /views\/_core\/maintenance\/index\.vue/);
});
