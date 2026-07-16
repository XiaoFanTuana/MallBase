import MockAdapter from 'axios-mock-adapter';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { authenticateResponseInterceptor } from './preset-interceptors';
import { RequestClient } from './request-client';

describe('authenticateResponseInterceptor', () => {
  let client: RequestClient;
  let mock: MockAdapter;

  beforeEach(() => {
    client = new RequestClient();
    mock = new MockAdapter(client.instance);
  });

  afterEach(() => {
    mock.restore();
  });

  it('refreshes once and replays concurrent unauthorized requests', async () => {
    const doReAuthenticate = vi.fn(async () => {});
    const doRefreshToken = vi.fn(async () => 'refreshed-token');

    client.addResponseInterceptor(
      authenticateResponseInterceptor({
        client,
        doReAuthenticate,
        doRefreshToken,
        enableRefreshToken: true,
        formatToken: (token) => `Bearer ${token}`,
      }),
    );

    mock
      .onGet('/protected')
      .reply((config) =>
        config.headers?.Authorization === 'Bearer refreshed-token'
          ? [200, { ok: true }]
          : [401, { message: 'expired' }],
      );

    const responses = await Promise.all([
      client.get('/protected'),
      client.get('/protected'),
    ]);

    expect(responses.map((response) => response.data)).toEqual([
      { ok: true },
      { ok: true },
    ]);
    expect(doRefreshToken).toHaveBeenCalledTimes(1);
    expect(doReAuthenticate).not.toHaveBeenCalled();
    expect(client.refreshTokenQueue).toHaveLength(0);
    expect(client.isRefreshing).toBe(false);
  });

  it('rejects every queued request when refreshing fails', async () => {
    const refreshError = new Error('REFRESH_TOKEN_FAILED');
    let rejectRefresh!: (error: Error) => void;
    const doReAuthenticate = vi.fn(async () => {});
    const doRefreshToken = vi.fn(
      () =>
        new Promise<string>((_resolve, reject) => {
          rejectRefresh = reject;
        }),
    );

    client.addResponseInterceptor(
      authenticateResponseInterceptor({
        client,
        doReAuthenticate,
        doRefreshToken,
        enableRefreshToken: true,
        formatToken: (token) => `Bearer ${token}`,
      }),
    );
    mock.onGet('/protected').reply(401, { message: 'expired' });

    const first = client.get('/protected');
    await vi.waitFor(() => expect(doRefreshToken).toHaveBeenCalledTimes(1));
    const second = client.get('/protected');
    await vi.waitFor(() => expect(client.refreshTokenQueue).toHaveLength(1));

    const settled = Promise.allSettled([first, second]);
    rejectRefresh(refreshError);
    const results = await settled;

    expect(results.every(({ status }) => status === 'rejected')).toBe(true);
    expect(doReAuthenticate).toHaveBeenCalledTimes(1);
    expect(client.refreshTokenQueue).toHaveLength(0);
    expect(client.isRefreshing).toBe(false);
  });
});
