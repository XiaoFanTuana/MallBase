/* eslint-disable test/no-import-node-test */
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';

const viteSource = readFileSync(
  new URL('../../vite.config.mts', import.meta.url),
  'utf8',
);
const playwrightSource = readFileSync(
  new URL('../../playwright.config.ts', import.meta.url),
  'utf8',
);
const upgradeE2eUrl = new URL(
  '../e2e/upgrade-maintenance.spec.ts',
  import.meta.url,
);

test('upgrade E2E always owns its Vite process and uses the real admin API prefix', () => {
  assert.match(
    playwrightSource,
    /VITE_GLOB_API_URL=\/admin\/api/,
    'The E2E server must send Admin requests through the dedicated backend proxy.',
  );
  assert.match(
    playwrightSource,
    /reuseExistingServer:\s*false/,
    'Reusing an ordinary developer Vite process would silently omit the upgrade proxy.',
  );
});

test('E2E-only Vite proxy keeps Admin and the complete upgrade surface same-origin', () => {
  assert.match(viteSource, /VITE_E2E/);
  assert.match(viteSource, /MALLBASE_E2E_BACKEND_ORIGIN/);
  assert.match(viteSource, /['"]\/admin\/api['"]/);
  assert.match(viteSource, /['"]\/upgrade['"]/);
  assert.doesNotMatch(
    viteSource,
    /['"]\/upgrade['"][\s\S]{0,240}\brewrite\s*:/,
    'The PHP upgrade prefix must be forwarded without rewriting.',
  );
});

test('real-backend upgrade E2E covers route content types instead of a Vite fallback', () => {
  assert.equal(
    existsSync(upgradeE2eUrl),
    true,
    'upgrade-maintenance.spec.ts is required by the upgrade UI plan.',
  );
  const e2eSource = readFileSync(upgradeE2eUrl, 'utf8');
  assert.match(e2eSource, /\/admin\/api\/system\/upgrade\/session/);
  assert.match(e2eSource, /\/upgrade\/api\/maintenance/);
  assert.match(e2eSource, /text\/html/);
  assert.match(e2eSource, /application\/json/);
  assert.match(e2eSource, /data-mallbase-upgrade-shell/);
});
