<script lang="ts" setup>
import type { UpgradeApi } from '#/api/system/upgrade';

import { computed, onMounted, reactive, ref } from 'vue';

import { useAccess } from '@vben/access';
import { Page } from '@vben/common-ui';

import { message } from 'ant-design-vue';

import {
  createUpgradeEntryApi,
  getUpgradeRecordsApi,
  probeUpgradeAgentApi,
} from '#/api/system/upgrade';

defineOptions({ name: 'SystemUpgrade' });

const { hasAccessByCodes } = useAccess();
const canEnter = computed(() =>
  hasAccessByCodes(['SystemUpgradeSessionCreate']),
);
const loading = ref(false);
const entering = ref(false);
const checkingAgent = ref(false);
const agentOnline = ref(false);
const records = ref<UpgradeApi.RecordItem[]>([]);
const selectedRecord = ref<null | UpgradeApi.RecordItem>(null);
const detailOpen = ref(false);
const pagination = reactive({ current: 1, pageSize: 20, total: 0 });

const columns = [
  { dataIndex: 'action', key: 'action', title: '操作', width: 90 },
  { key: 'version', title: '版本', width: 180 },
  { dataIndex: 'status', key: 'status', title: '状态', width: 150 },
  { dataIndex: 'backup_path', key: 'backup_path', title: '备份位置' },
  { dataIndex: 'created_at', key: 'created_at', title: '创建时间', width: 180 },
  { key: 'operation', title: '详情', width: 80 },
];

const statusMeta: Record<string, { color: string; label: string }> = {
  applying: { color: 'processing', label: '正在应用代码' },
  awaiting_php_restart: { color: 'warning', label: '等待手动部署 PHP 代码' },
  backing_up: { color: 'processing', label: '正在备份' },
  completed: { color: 'success', label: '已完成' },
  downloading: { color: 'processing', label: '正在下载' },
  draining: { color: 'processing', label: '正在排空业务' },
  failed: { color: 'error', label: '失败' },
  preparing: { color: 'processing', label: '准备中' },
  queued: { color: 'default', label: '等待执行' },
  running: { color: 'processing', label: '执行中' },
  rolling_back: { color: 'processing', label: '正在恢复' },
  verifying: { color: 'processing', label: '正在校验' },
};

function actionLabel(action: UpgradeApi.Action): string {
  return action === 'rollback' ? '恢复' : '升级';
}

function statusLabel(status: UpgradeApi.Status): string {
  return statusMeta[status]?.label || status;
}

function statusColor(status: UpgradeApi.Status): string {
  return statusMeta[status]?.color || 'default';
}

function formatTime(timestamp: number): string {
  if (!timestamp) return '-';
  return new Intl.DateTimeFormat('zh-CN', {
    dateStyle: 'medium',
    timeStyle: 'medium',
  }).format(new Date(timestamp * 1000));
}

async function loadRecords() {
  loading.value = true;
  try {
    const result = await getUpgradeRecordsApi({
      limit: pagination.pageSize,
      page: pagination.current,
    });
    records.value = result.list || [];
    pagination.total = result.total || 0;
  } catch (error) {
    message.error(error instanceof Error ? error.message : '升级记录加载失败');
  } finally {
    loading.value = false;
  }
}

async function checkAgent() {
  checkingAgent.value = true;
  try {
    agentOnline.value = await probeUpgradeAgentApi();
  } finally {
    checkingAgent.value = false;
  }
}

async function refreshAll() {
  await Promise.all([loadRecords(), checkAgent()]);
}

async function enterUpgrade() {
  if (!canEnter.value) return;
  if (!agentOnline.value) {
    message.warning('请先在服务器手动启动 Go 升级程序');
    return;
  }
  entering.value = true;
  try {
    const entry = await createUpgradeEntryApi();
    window.location.assign(entry.upgrade_url);
  } catch (error) {
    message.error(error instanceof Error ? error.message : '无法进入升级页面');
  } finally {
    entering.value = false;
  }
}

function showDetail(record: UpgradeApi.RecordItem) {
  selectedRecord.value = record;
  detailOpen.value = true;
}

function handleTableChange(pager: { current?: number; pageSize?: number }) {
  pagination.current = pager.current || pagination.current;
  pagination.pageSize = pager.pageSize || pagination.pageSize;
  void loadRecords();
}

onMounted(refreshAll);
</script>

<template>
  <Page
    description="查看 Go 升级程序生成的记录、备份和升级包位置，并进入独立升级页面。"
    title="系统升级"
  >
    <div class="space-y-4">
      <a-card class="theme-card" title="升级程序">
        <div class="flex flex-wrap items-center justify-between gap-4">
          <a-space>
            <a-badge :status="agentOnline ? 'success' : 'default'" />
            <a-typography-text strong>
              {{ agentOnline ? 'Go 升级程序已启动' : 'Go 升级程序未启动' }}
            </a-typography-text>
          </a-space>
          <a-space>
            <a-button :loading="checkingAgent" @click="checkAgent">
              检测状态
            </a-button>
            <a-button
              v-access:code="'SystemUpgradeSessionCreate'"
              :disabled="!agentOnline"
              :loading="entering"
              type="primary"
              @click="enterUpgrade"
            >
              进入升级页面
            </a-button>
          </a-space>
        </div>
        <a-alert
          v-if="!agentOnline"
          class="mt-4"
          message="升级服务未启动"
          description="商城业务不受影响。需要升级或恢复时，请先在服务器手动启动 Go 程序；本页历史记录仍可查看。"
          show-icon
          type="info"
        />
      </a-card>

      <a-card class="theme-card" title="升级记录">
        <template #extra>
          <a-button :loading="loading" @click="refreshAll">刷新</a-button>
        </template>
        <a-table
          row-key="job_id"
          :columns="columns"
          :data-source="records"
          :loading="loading"
          :pagination="pagination"
          :scroll="{ x: 980 }"
          @change="handleTableChange"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'action'">
              {{ actionLabel(record.action) }}
            </template>
            <template v-else-if="column.key === 'version'">
              <a-typography-text code>
                {{ record.source_version || '-' }} →
                {{ record.target_version || '-' }}
              </a-typography-text>
            </template>
            <template v-else-if="column.key === 'status'">
              <a-tag :color="statusColor(record.status)">
                {{ statusLabel(record.status) }}
              </a-tag>
            </template>
            <template v-else-if="column.key === 'backup_path'">
              <a-typography-text v-if="record.backup_path" code copyable>
                {{ record.backup_path }}
              </a-typography-text>
              <span v-else>-</span>
            </template>
            <template v-else-if="column.key === 'created_at'">
              {{ formatTime(record.created_at) }}
            </template>
            <template v-else-if="column.key === 'operation'">
              <a-button type="link" @click="showDetail(record)">查看</a-button>
            </template>
          </template>
        </a-table>
      </a-card>
    </div>

    <a-drawer v-model:open="detailOpen" title="升级记录详情" width="520">
      <a-descriptions v-if="selectedRecord" bordered :column="1" size="small">
        <a-descriptions-item label="任务 ID">
          <a-typography-text code copyable>
            {{ selectedRecord.job_id }}
          </a-typography-text>
        </a-descriptions-item>
        <a-descriptions-item label="操作">
          {{ actionLabel(selectedRecord.action) }}
        </a-descriptions-item>
        <a-descriptions-item label="版本">
          {{ selectedRecord.source_version || '-' }} →
          {{ selectedRecord.target_version || '-' }}
        </a-descriptions-item>
        <a-descriptions-item label="状态">
          {{ statusLabel(selectedRecord.status) }}
        </a-descriptions-item>
        <a-descriptions-item label="备份位置">
          {{ selectedRecord.backup_path || '-' }}
        </a-descriptions-item>
        <a-descriptions-item label="升级包位置">
          {{ selectedRecord.package_path || '-' }}
        </a-descriptions-item>
        <a-descriptions-item label="日志位置">
          {{ selectedRecord.log_path || '-' }}
        </a-descriptions-item>
        <a-descriptions-item label="开始时间">
          {{ formatTime(selectedRecord.started_at) }}
        </a-descriptions-item>
        <a-descriptions-item label="结束时间">
          {{ formatTime(selectedRecord.finished_at) }}
        </a-descriptions-item>
        <a-descriptions-item v-if="selectedRecord.error" label="失败原因">
          <a-typography-text type="danger">
            {{ selectedRecord.error }}
          </a-typography-text>
        </a-descriptions-item>
      </a-descriptions>
    </a-drawer>
  </Page>
</template>

<style scoped>
.theme-card {
  border-color: hsl(var(--border));
  background: hsl(var(--card));
  color: hsl(var(--foreground));
}
</style>
