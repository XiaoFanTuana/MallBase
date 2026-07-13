<script lang="ts" setup>
import type { UpgradeSessionResponse } from '#/api/system/upgrade';

import { onBeforeUnmount, onMounted, ref } from 'vue';

import { message } from 'ant-design-vue';

import {
  confirmRecoveryApi,
  takeoverUpgradeSessionApi,
  UpgradeApiError,
} from '#/api/system/upgrade';

defineOptions({ name: 'Maintenance' });

const POLL_INTERVAL = 5000;
const PENDING_TAKEOVER_CONFIRMATION_KEY =
  'mallbase_upgrade_takeover_pending_confirmation';

interface PendingTakeoverConfirmation {
  confirmationNonce: string;
  requestId: string;
}

const recoveryCredential = ref('');
const replacement = ref<null | UpgradeSessionResponse>(null);
const copyAcknowledged = ref(false);
const copied = ref(false);
const takingOver = ref(false);
const confirming = ref(false);
const statusError = ref('');
const takeoverError = ref('');
const state = ref('maintenance');
const retryAfter = ref(5);
let pollTimer: ReturnType<typeof setTimeout> | undefined;
let disposed = false;

async function fetchMaintenanceStatus(): Promise<boolean> {
  try {
    const response = await fetch('/upgrade/api/maintenance', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    const body = await response.json().catch(() => null);
    if (!response.ok || body?.code !== 200) {
      throw new Error('维护状态暂时不可用');
    }
    state.value = body.data?.state || 'maintenance';
    retryAfter.value = Math.max(1, Number(body.data?.retry_after) || 5);
    statusError.value = '';
    return true;
  } catch {
    statusError.value = '正在重新连接维护状态服务';
    return false;
  }
}

function schedulePoll() {
  if (disposed) return;
  clearTimeout(pollTimer);
  pollTimer = setTimeout(async () => {
    await fetchMaintenanceStatus();
    schedulePoll();
  }, POLL_INTERVAL);
}

async function takeover() {
  const credential = recoveryCredential.value.trim();
  if (!credential) {
    takeoverError.value = '请输入恢复凭据';
    return;
  }
  takingOver.value = true;
  takeoverError.value = '';
  try {
    replacement.value = await takeoverUpgradeSessionApi(credential);
    recoveryCredential.value = '';
    copied.value = false;
    copyAcknowledged.value = false;
  } catch (error) {
    if (error instanceof UpgradeApiError && error.status === 409) {
      await fetchMaintenanceStatus();
      takeoverError.value = '当前会话尚未达到可接管时间，请稍后重试';
    } else if (error instanceof UpgradeApiError && error.status === 401) {
      takeoverError.value = '恢复凭据无效、已确认或已失效';
    } else {
      takeoverError.value =
        error instanceof Error ? error.message : '升级会话接管失败';
    }
  } finally {
    takingOver.value = false;
  }
}

async function copyReplacementCredential() {
  const credential = replacement.value?.recovery_credential || '';
  if (!credential) return;
  try {
    await navigator.clipboard.writeText(credential);
    copied.value = true;
    message.success('新恢复凭据已复制');
  } catch {
    copied.value = true;
    message.error('复制失败，请手动选择并复制新恢复凭据');
  }
}

function readPendingTakeoverConfirmation(): null | PendingTakeoverConfirmation {
  const raw = sessionStorage.getItem(PENDING_TAKEOVER_CONFIRMATION_KEY);
  if (!raw) return null;
  try {
    const pending = JSON.parse(raw) as PendingTakeoverConfirmation;
    if (
      typeof pending.requestId === 'string' &&
      pending.requestId.length > 0 &&
      typeof pending.confirmationNonce === 'string' &&
      pending.confirmationNonce.length > 0
    ) {
      return pending;
    }
  } catch {
    // 非法的标签页临时状态不能参与确认请求。
  }
  sessionStorage.removeItem(PENDING_TAKEOVER_CONFIRMATION_KEY);
  return null;
}

async function confirmPendingTakeover(pending: PendingTakeoverConfirmation) {
  await confirmRecoveryApi(pending.requestId, pending.confirmationNonce);
  if (!(await fetchMaintenanceStatus())) {
    throw new Error(
      '无法访问独立升级入口，请确认 /upgrade/ 已同源转发到 PHP 且未被重写。',
    );
  }
  sessionStorage.removeItem(PENDING_TAKEOVER_CONFIRMATION_KEY);
  window.location.replace('/upgrade/#/upgrade');
}

async function confirmAndEnter() {
  if (!replacement.value || !copied.value || !copyAcknowledged.value) return;
  const pending: PendingTakeoverConfirmation = {
    confirmationNonce: replacement.value.confirmation_nonce,
    requestId: replacement.value.recovery_request_id,
  };
  sessionStorage.setItem(
    PENDING_TAKEOVER_CONFIRMATION_KEY,
    JSON.stringify(pending),
  );
  confirming.value = true;
  takeoverError.value = '';
  try {
    await confirmPendingTakeover(pending);
  } catch (error) {
    takeoverError.value =
      error instanceof Error ? error.message : '恢复凭据确认失败，请重试';
  } finally {
    confirming.value = false;
  }
}

onMounted(async () => {
  disposed = false;
  const pending = readPendingTakeoverConfirmation();
  if (pending) {
    confirming.value = true;
    try {
      await confirmPendingTakeover(pending);
      return;
    } catch (error) {
      takeoverError.value =
        error instanceof Error ? error.message : '恢复凭据确认失败，请重试';
    } finally {
      confirming.value = false;
    }
  }
  await fetchMaintenanceStatus();
  schedulePoll();
});

onBeforeUnmount(() => {
  disposed = true;
  clearTimeout(pollTimer);
});
</script>

<template>
  <main class="maintenance-page">
    <a-card class="maintenance-card" :bordered="true">
      <a-result
        status="warning"
        sub-title="普通后台功能已暂停，请等待升级完成或使用已保存的恢复凭据接管升级会话。"
        title="系统正在维护"
      >
        <template #extra>
          <a-space direction="vertical" size="middle">
            <a-tag color="orange">当前状态：{{ state }}</a-tag>
            <a-typography-text type="secondary">
              {{ statusError || `约 ${retryAfter} 秒后自动刷新状态` }}
            </a-typography-text>
          </a-space>
        </template>
      </a-result>

      <a-divider>恢复升级会话</a-divider>
      <a-alert
        class="mb-4"
        message="Admin 登录凭据不能用于接管升级。请粘贴创建升级会话时保存的恢复凭据。"
        show-icon
        type="info"
      />
      <a-alert
        v-if="takeoverError"
        class="mb-4"
        :message="takeoverError"
        show-icon
        type="error"
      />
      <a-textarea
        v-model:value="recoveryCredential"
        :auto-size="{ minRows: 3, maxRows: 6 }"
        placeholder="粘贴恢复凭据"
      />
      <a-button
        class="mt-3"
        :loading="takingOver"
        type="primary"
        @click="takeover"
      >
        接管升级会话
      </a-button>

      <template v-if="replacement">
        <a-divider>保存新的恢复凭据</a-divider>
        <a-textarea
          :auto-size="{ minRows: 3, maxRows: 6 }"
          readonly
          :value="replacement.recovery_credential"
        />
        <div class="mt-3 flex flex-wrap items-center gap-3">
          <a-button @click="copyReplacementCredential">复制新凭据</a-button>
          <a-checkbox v-model:checked="copyAcknowledged">
            我已将新恢复凭据保存到安全位置
          </a-checkbox>
        </div>
        <a-button
          class="mt-4"
          :disabled="!copied || !copyAcknowledged"
          :loading="confirming"
          type="primary"
          @click="confirmAndEnter"
        >
          确认并进入升级页面
        </a-button>
      </template>

      <a-divider>宿主机恢复</a-divider>
      <a-typography-paragraph type="secondary">
        如果 owner cookie 和恢复凭据同时丢失，等待原 owner 超时后，在服务器的
        MallBase 根目录执行：
      </a-typography-paragraph>
      <a-typography-text code>mallbase-agent recovery issue</a-typography-text>
    </a-card>
  </main>
</template>

<style scoped>
.maintenance-page {
  display: grid;
  min-height: 100vh;
  padding: 32px 20px;
  background: hsl(var(--background));
  color: hsl(var(--foreground));
  place-items: center;
}

.maintenance-card {
  width: min(760px, 100%);
  border-color: hsl(var(--border));
  background: hsl(var(--card));
  color: hsl(var(--foreground));
}
</style>
