/* eslint-disable test/no-import-node-test */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const apiSource = readFileSync(
  new URL('../../src/api/system/upgrade.ts', import.meta.url),
  'utf8',
);
const bridgeSource = readFileSync(
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

test('upgrade API keeps admin creation separate from cookie-scoped recovery', () => {
  assert.match(apiSource, /createUpgradeSessionApi/);
  assert.match(apiSource, /\/system\/upgrade\/session/);
  assert.match(apiSource, /Idempotency-Key/);
  assert.match(apiSource, /takeoverUpgradeSessionApi/);
  assert.match(
    apiSource,
    /takeoverUpgradeSessionApi[\s\S]{0,240}deriveTakeoverRequestId/,
  );
  assert.match(
    apiSource,
    /\/upgrade\/api\/recovery\/takeover[\s\S]{0,240}Idempotency-Key/,
  );
  assert.match(apiSource, /confirmRecoveryApi/);
  assert.match(apiSource, /\/upgrade\/api\/recovery\/confirm/);
  assert.match(apiSource, /rotateRecoveryApi/);
  assert.match(apiSource, /\/upgrade\/api\/recovery\/rotate/);
});

test('admin bridge persists only retry-safe identifiers and confirms copied recovery data', () => {
  assert.match(bridgeSource, /sessionStorage/);
  assert.match(bridgeSource, /createUpgradeSessionApi/);
  assert.match(bridgeSource, /confirmRecoveryApi/);
  assert.match(bridgeSource, /navigator\.clipboard\.writeText/);
  assert.match(bridgeSource, /copyAcknowledged/);
  assert.match(bridgeSource, /\/upgrade\/api\/maintenance/);
  assert.match(bridgeSource, /window\.location\.replace\(['"]\/upgrade\/#\/upgrade['"]\)/);
  assert.doesNotMatch(
    bridgeSource,
    /(?:localStorage|sessionStorage)\.setItem\([^\n]*recovery_credential/,
  );
  assert.doesNotMatch(bridgeSource, /console\.(?:debug|info|log|warn)\([^\n]*recovery/i);
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
  assert.doesNotMatch(redirectSource, /setAccessToken|setRefreshToken|logout/);
});

test('maintenance is a standalone core route and uses only public recovery authority', () => {
  assert.match(routesSource, /name: 'Maintenance'/);
  assert.match(routesSource, /path: '\/maintenance'/);
  assert.match(routesSource, /views\/_core\/maintenance\/index\.vue/);
  assert.match(maintenanceSource, /\/upgrade\/api\/maintenance/);
  assert.match(maintenanceSource, /takeoverUpgradeSessionApi/);
  assert.match(maintenanceSource, /confirmRecoveryApi/);
  assert.match(maintenanceSource, /mallbase-agent recovery issue/);
  assert.doesNotMatch(maintenanceSource, /useAccessStore|accessToken|refreshToken/);
});

test('takeover confirmation survives response loss without storing recovery secrets', () => {
  assert.match(maintenanceSource, /sessionStorage/);
  assert.match(maintenanceSource, /PENDING_TAKEOVER_CONFIRMATION_KEY/);
  assert.match(maintenanceSource, /confirmRecoveryApi/);
  assert.doesNotMatch(
    maintenanceSource,
    /sessionStorage\.setItem\([^\n]*recovery_credential/,
  );
});
