import { requestClient } from '#/api/request';

export interface UpgradeSessionResponse {
  confirmation_nonce: string;
  recovery_credential: string;
  recovery_request_id: string;
  upgrade_url: string;
}

export interface RecoveryRotationResponse {
  confirmation_nonce: string;
  recovery_credential: string;
  recovery_request_id: string;
}

interface ProtocolResponse<T> {
  code: number;
  data: T;
  message?: string;
  msg?: string;
}

export class UpgradeApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly code: number,
  ) {
    super(message);
    this.name = 'UpgradeApiError';
  }
}

export async function createUpgradeSessionApi(idempotencyKey: string) {
  return requestClient.post<UpgradeSessionResponse>(
    '/system/upgrade/session',
    {},
    {
      headers: {
        'Idempotency-Key': idempotencyKey,
      },
    },
  );
}

export async function takeoverUpgradeSessionApi(recoveryCredential: string) {
  const requestId = await deriveTakeoverRequestId(recoveryCredential);
  return postRecoveryApi<UpgradeSessionResponse>(
    '/upgrade/api/recovery/takeover',
    { recovery_credential: recoveryCredential },
    { 'Idempotency-Key': requestId },
  );
}

export async function confirmRecoveryApi(
  requestId: string,
  confirmationNonce: string,
): Promise<void> {
  await postRecoveryApi('/upgrade/api/recovery/confirm', {
    confirmation_nonce: confirmationNonce,
    request_id: requestId,
  });
}

export async function rotateRecoveryApi(idempotencyKey: string) {
  return postRecoveryApi<RecoveryRotationResponse>(
    '/upgrade/api/recovery/rotate',
    {},
    { 'Idempotency-Key': idempotencyKey },
  );
}

async function deriveTakeoverRequestId(
  recoveryCredential: string,
): Promise<string> {
  const digest = new Uint8Array(
    await crypto.subtle.digest(
      'SHA-256',
      new TextEncoder().encode(recoveryCredential),
    ),
  );
  digest[6] = ((digest[6] ?? 0) % 16) + 64;
  digest[8] = ((digest[8] ?? 0) % 64) + 128;
  const hex = [...digest.subarray(0, 16)].map((value) =>
    value.toString(16).padStart(2, '0'),
  );
  return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10).join('')}`;
}

async function postRecoveryApi<T>(
  url: string,
  data: Record<string, string>,
  headers: Record<string, string> = {},
): Promise<T> {
  const response = await fetch(url, {
    body: JSON.stringify(data),
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...headers,
    },
    method: 'POST',
  });
  const body = await readProtocolResponse<T>(response);
  if (!response.ok || body.code !== 200) {
    throw new UpgradeApiError(
      body.message || body.msg || '升级会话请求失败',
      response.status,
      body.code,
    );
  }
  return body.data;
}

async function readProtocolResponse<T>(
  response: Response,
): Promise<ProtocolResponse<T>> {
  try {
    const body = (await response.json()) as ProtocolResponse<T>;
    if (body && typeof body === 'object' && typeof body.code === 'number') {
      return body;
    }
  } catch {
    // 统一转换为不包含响应正文的固定错误。
  }
  throw new UpgradeApiError('升级会话响应异常', response.status, 0);
}
