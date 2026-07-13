<script lang="ts" setup>
import type { UpgradeSessionResponse } from '#/api/system/upgrade';

import { onMounted, ref } from 'vue';

import { Page } from '@vben/common-ui';

import { message } from 'ant-design-vue';

import {
  confirmRecoveryApi,
  createUpgradeSessionApi,
} from '#/api/system/upgrade';

defineOptions({ name: 'SystemUpgrade' });

const CREATE_REQUEST_KEY = 'mallbase_upgrade_create_request_id';
const PENDING_CONFIRMATION_KEY = 'mallbase_upgrade_pending_confirmation';
const UUID_PATTERN =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
const PROXY_GUIDANCE =
  '无法访问独立升级入口。请先配置 Web 服务器将 /upgrade/ 同源转发到 PHP，并保持路径不被重写。';

interface PendingConfirmation {
  confirmationNonce: string;
  requestId: string;
}

const loading = ref(true);
const submitting = ref(false);
const copyAcknowledged = ref(false);
const copied = ref(false);
const errorMessage = ref('');
const session = ref<null | UpgradeSessionResponse>(null);

function getOrCreateRequestId(): string {
  const existing = sessionStorage.getItem(CREATE_REQUEST_KEY) || '';
  if (UUID_PATTERN.test(existing)) return existing;
  const requestId = createRequestId();
  sessionStorage.setItem(CREATE_REQUEST_KEY, requestId);
  return requestId;
}

function createRequestId(): string {
  if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  bytes[6] = ((bytes[6] ?? 0) % 16) + 64;
  bytes[8] = ((bytes[8] ?? 0) % 64) + 128;
  const hex = [...bytes].map((value) => value.toString(16).padStart(2, '0'));
  return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10).join('')}`;
}

function readPendingConfirmation(): null | PendingConfirmation {
  const raw = sessionStorage.getItem(PENDING_CONFIRMATION_KEY);
  if (!raw) return null;
  try {
    const pending = JSON.parse(raw) as PendingConfirmation;
    if (
      UUID_PATTERN.test(pending.requestId) &&
      typeof pending.confirmationNonce === 'string' &&
      pending.confirmationNonce.length > 0
    ) {
      return pending;
    }
  } catch {
    // 非法的标签页临时状态不能参与确认请求。
  }
  sessionStorage.removeItem(PENDING_CONFIRMATION_KEY);
  return null;
}

async function ensureUpgradeProxy(): Promise<void> {
  const response = await fetch('/upgrade/api/maintenance', {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  });
  const body = await response.json().catch(() => null);
  if (!response.ok || body?.code !== 200) {
    throw new Error(PROXY_GUIDANCE);
  }
}

async function confirmAndEnter(pending: PendingConfirmation) {
  await confirmRecoveryApi(pending.requestId, pending.confirmationNonce);
  await ensureUpgradeProxy();
  sessionStorage.removeItem(PENDING_CONFIRMATION_KEY);
  sessionStorage.removeItem(CREATE_REQUEST_KEY);
  window.location.replace('/upgrade/#/upgrade');
}

async function initialize() {
  loading.value = true;
  errorMessage.value = '';
  const pending = readPendingConfirmation();
  try {
    if (pending) {
      await confirmAndEnter(pending);
      return;
    }
    session.value = await createUpgradeSessionApi(getOrCreateRequestId());
  } catch (error) {
    errorMessage.value =
      error instanceof Error ? error.message : '无法创建升级控制会话';
  } finally {
    loading.value = false;
  }
}

async function copyRecoveryCredential() {
  const credential = session.value?.recovery_credential || '';
  if (!credential) return;
  try {
    await navigator.clipboard.writeText(credential);
    copied.value = true;
    message.success('恢复凭据已复制');
  } catch {
    copied.value = true;
    message.error('复制失败，请手动选择并复制恢复凭据');
  }
}

async function acknowledgeAndEnter() {
  if (!session.value || !copied.value || !copyAcknowledged.value) return;
  const pending: PendingConfirmation = {
    confirmationNonce: session.value.confirmation_nonce,
    requestId: session.value.recovery_request_id,
  };
  sessionStorage.setItem(PENDING_CONFIRMATION_KEY, JSON.stringify(pending));
  submitting.value = true;
  errorMessage.value = '';
  try {
    await confirmAndEnter(pending);
  } catch (error) {
    errorMessage.value =
      error instanceof Error ? error.message : '恢复凭据确认失败，请重试';
  } finally {
    submitting.value = false;
  }
}

onMounted(initialize);
</script>

<template>
  <Page
    description="创建独立升级会话，并在进入升级控制页前保存恢复凭据。"
    title="系统升级"
  >
    <a-card class="upgrade-card" :loading="loading">
      <a-alert
        v-if="errorMessage"
        class="mb-5"
        :message="errorMessage"
        show-icon
        type="error"
      >
        <template #description>
          如果创建响应在返回前中断且当前标签页状态已丢失，请在服务器终端执行
          <a-typography-text code>
            mallbase-agent recovery issue
          </a-typography-text>
          获取新的恢复凭据。
        </template>
      </a-alert>

      <template v-if="session">
        <a-alert
          class="mb-5"
          message="恢复凭据只显示在当前页面，请保存到安全位置。浏览器关闭不会停止已经开始的升级任务。"
          show-icon
          type="warning"
        />
        <a-typography-title :level="5">升级恢复凭据</a-typography-title>
        <a-textarea
          class="credential-field"
          :auto-size="{ minRows: 3, maxRows: 6 }"
          readonly
          :value="session.recovery_credential"
        />
        <div class="mt-4 flex flex-wrap items-center gap-3">
          <a-button @click="copyRecoveryCredential">复制恢复凭据</a-button>
          <a-checkbox v-model:checked="copyAcknowledged">
            我已将恢复凭据保存到安全位置
          </a-checkbox>
        </div>
        <a-divider />
        <a-button
          :disabled="!copied || !copyAcknowledged"
          :loading="submitting"
          type="primary"
          @click="acknowledgeAndEnter"
        >
          确认并进入升级页面
        </a-button>
      </template>
    </a-card>
  </Page>
</template>

<style scoped>
.upgrade-card {
  max-width: 760px;
  border-color: hsl(var(--border));
  background: hsl(var(--card));
  color: hsl(var(--foreground));
}

.credential-field {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}
</style>
