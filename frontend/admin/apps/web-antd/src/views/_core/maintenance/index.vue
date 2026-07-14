<script lang="ts" setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

import { probeUpgradeAgentApi } from '#/api/system/upgrade';

defineOptions({ name: 'Maintenance' });

const POLL_INTERVAL = 5000;
const agentOnline = ref(false);
const checking = ref(false);
let pollTimer: ReturnType<typeof setTimeout> | undefined;
let disposed = false;

async function checkAgent() {
  checking.value = true;
  try {
    agentOnline.value = await probeUpgradeAgentApi();
  } finally {
    checking.value = false;
  }
}

function schedulePoll() {
  if (disposed) return;
  clearTimeout(pollTimer);
  pollTimer = setTimeout(async () => {
    await checkAgent();
    schedulePoll();
  }, POLL_INTERVAL);
}

function openUpgradePage() {
  window.location.assign('/upgrade/');
}

onMounted(async () => {
  disposed = false;
  await checkAgent();
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
        sub-title="普通后台功能已暂停。PHP 代码由你手动重新部署或重启，独立 Go 升级页面不会因此中断。"
        title="系统正在维护"
      >
        <template #extra>
          <a-space direction="vertical" size="middle">
            <a-space>
              <a-badge :status="agentOnline ? 'success' : 'default'" />
              <a-typography-text>
                {{ agentOnline ? '升级程序在线' : '升级程序未连接' }}
              </a-typography-text>
            </a-space>
            <a-space>
              <a-button :loading="checking" @click="checkAgent">
                重新检测
              </a-button>
              <a-button
                :disabled="!agentOnline"
                type="primary"
                @click="openUpgradePage"
              >
                打开升级页面
              </a-button>
            </a-space>
          </a-space>
        </template>
      </a-result>

      <a-alert
        message="PHP 代码部署由管理员手动完成"
        description="升级或恢复完成后，Docker 部署请重新构建镜像并重建 Queue、Cron、HTTP 容器；非 Docker 部署请先重启 Queue/Cron，最后重启 HTTP。Go 程序不会执行 Docker、systemctl 或服务重启命令。"
        show-icon
        type="info"
      />
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
