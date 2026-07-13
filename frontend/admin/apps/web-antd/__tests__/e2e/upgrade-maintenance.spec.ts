import { randomUUID } from 'node:crypto';

import { expect, test } from '@playwright/test';

import { authLogin } from './common/auth';

test.describe('Upgrade maintenance boundary', () => {
  test('serves the independent shell and API with their real content types', async ({
    page,
  }) => {
    const maintenance = await page.request.get('/upgrade/api/maintenance');
    expect(maintenance.ok()).toBeTruthy();
    expect(maintenance.headers()['content-type']).toContain('application/json');
    const maintenanceBody = await maintenance.json();
    expect(maintenanceBody.code).toBe(200);

    const shell = await page.request.get('/upgrade/');
    expect(shell.ok()).toBeTruthy();
    expect(shell.headers()['content-type']).toContain('text/html');
    expect(await shell.text()).toContain('data-mallbase-upgrade-shell');

    await page.goto('/auth/login?e2e=1');
    const login = await authLogin(page);
    test.skip(!login.accessToken, '登录未返回 access token，无法验证超级管理员入口');

    const session = await page.request.post(
      '/admin/api/system/upgrade/session',
      {
        data: {},
        headers: {
          Authorization: `Bearer ${login.accessToken}`,
          'Idempotency-Key': randomUUID(),
        },
      },
    );
    expect(session.headers()['content-type']).toContain('application/json');
    expect([200, 409]).toContain(session.status());
  });
});
