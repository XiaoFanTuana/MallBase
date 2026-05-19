<script lang="ts" setup>
import type { SmsSignApi } from '#/api/sms/sign';

import { computed, ref } from 'vue';

import { useAccess } from '@vben/access';

import { message } from 'ant-design-vue';

import { isPnvsDriver } from '#/api/sms/constants';
import { getSmsProviderListApi } from '#/api/sms/provider';
import type { SmsProviderApi } from '#/api/sms/provider';
import {
  createSmsSignApi,
  deleteSmsSignApi,
  getSmsSignListApi,
  importSmsSignApi,
  syncAllSmsSignApi,
  syncSmsSignStatusApi,
} from '#/api/sms/sign';
import { useFormModal, useTableCrud } from '#/composables/useTableCrud';

defineOptions({ name: 'SmsSign' });

const { hasAccessByCodes } = useAccess();

const auditStatusOptions = [
  { label: '审核中', value: 'pending', color: 'gold' },
  { label: '审核通过', value: 'passed', color: 'green' },
  { label: '审核失败', value: 'rejected', color: 'red' },
  { label: '仅本地', value: 'local_only', color: 'default' },
];

const providers = ref<SmsProviderApi.ProviderItem[]>([]);
const loadProviders = async () => {
  const res = await getSmsProviderListApi({ page: 1, limit: 100 });
  providers.value = res.list;
};

// PNVS 服务商:可本地登记签名(签名由阿里云预置,无远端管理 API)
const pnvsProviders = computed(() =>
  providers.value.filter((p) => isPnvsDriver(p.driver)),
);
// 非 PNVS 服务商:签名只能从阿里云控制台导入已审核记录
const importableProviders = computed(() =>
  providers.value.filter((p) => !isPnvsDriver(p.driver)),
);
const canCreate = computed(() => pnvsProviders.value.length > 0);
const canImport = computed(() => importableProviders.value.length > 0);

const isPnvsRow = (record: SmsSignApi.SignItem) => {
  const p = providers.value.find((p) => p.id === record.provider_id);
  return isPnvsDriver(p?.driver);
};

const searchParams = ref<SmsSignApi.ListParams>({
  keyword: '',
  provider_id: undefined,
  audit_status: undefined,
});

const { tableData, loading, pagination, loadData, refresh, handleDelete } =
  useTableCrud<SmsSignApi.SignItem, SmsSignApi.ListParams>(
    {
      delete: deleteSmsSignApi,
      list: getSmsSignListApi,
    },
    { immediateLoad: false },
  );

const { modalVisible, modalTitle, formData, formRef, openCreateModal, handleSubmit } =
  useFormModal<SmsSignApi.SignItem>();

const handleCreate = async () => {
  if (providers.value.length === 0) await loadProviders();
  if (!canCreate.value) {
    message.warning('请先在「服务商管理」新建 PNVS 服务商');
    return;
  }
  openCreateModal({
    provider_id: pnvsProviders.value[0]?.id,
    sign_name: '',
    remark: '',
  });
};

const handleFormSubmit = async () => {
  await handleSubmit({ create: createSmsSignApi }, () => {
    loadData(searchParams.value);
  });
};

// ------------------- 导入已审核签名 -------------------

const importModalVisible = ref(false);
const importFormData = ref<SmsSignApi.ImportParams>({
  provider_id: 0,
  sign_name: '',
});
const importing = ref(false);

const openImportModal = async () => {
  if (providers.value.length === 0) await loadProviders();
  if (!canImport.value) {
    message.warning('当前没有可导入签名的服务商(PNVS 不支持导入)');
    return;
  }
  importFormData.value = {
    provider_id: importableProviders.value[0]?.id || 0,
    sign_name: '',
  };
  importModalVisible.value = true;
};

const handleImportSubmit = async () => {
  if (!importFormData.value.provider_id || !importFormData.value.sign_name) {
    message.error('请完整填写服务商和签名名称');
    return;
  }
  importing.value = true;
  try {
    await importSmsSignApi(importFormData.value);
    message.success('导入成功');
    importModalVisible.value = false;
    loadData(searchParams.value);
  } finally {
    importing.value = false;
  }
};

const handleSync = async (row: SmsSignApi.SignItem) => {
  await syncSmsSignStatusApi(row.id);
  message.success('同步成功');
  loadData(searchParams.value);
};

const handleSyncAll = async () => {
  let providerId = searchParams.value.provider_id;
  // 只有 1 个服务商时自动选中,免去人工筛选
  if (!providerId && providers.value.length === 1) {
    providerId = providers.value[0]!.id;
  }
  if (!providerId) {
    message.warning('当前存在多个服务商,请先在上方筛选中选择要同步的服务商');
    return;
  }
  const stat = await syncAllSmsSignApi(providerId);
  message.success(`同步完成: 成功 ${stat.success} 个,失败 ${stat.failed} 个`);
  loadData(searchParams.value);
};

const resetSearch = () => {
  searchParams.value = {
    keyword: '',
    provider_id: undefined,
    audit_status: undefined,
  };
  pagination.current = 1;
  loadData(searchParams.value);
};

const auditStatusTag = (status: string) =>
  auditStatusOptions.find((o) => o.value === status);

const providerName = (id: number) =>
  providers.value.find((p) => p.id === id)?.name || `#${id}`;

const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '服务商', dataIndex: 'provider_id', width: 160 },
  { title: '签名', dataIndex: 'sign_name', width: 180 },
  { title: '类型', dataIndex: 'sign_type', width: 100 },
  { title: '审核状态', dataIndex: 'audit_status', width: 120 },
  { title: '审核备注', dataIndex: 'audit_reason', width: 360 },
  { title: '最近同步', dataIndex: 'last_synced_at', width: 180 },
  { title: '操作', key: 'action', width: 200 },
];

if (hasAccessByCodes(['SmsSignList'])) {
  loadProviders();
  loadData(searchParams.value);
}
</script>

<template>
  <div class="p-4">
    <div class="mb-4">
      <a-tooltip
        :title="
          canCreate
            ? '录入 PNVS 系统赠送的签名（仅本地登记，不推送远端）'
            : '请先在「服务商管理」新建 PNVS 服务商'
        "
      >
        <a-button
          type="primary"
          :disabled="!canCreate"
          @click="handleCreate"
          v-access:code="'SmsSignCreate'"
        >
          新增签名
        </a-button>
      </a-tooltip>
      <a-tooltip title="把已经在阿里云审核通过的签名拉回本地,不触发新审核">
        <a-button
          class="ml-2"
          :disabled="!canImport"
          @click="openImportModal"
          v-access:code="'SmsSignImport'"
        >
          导入已审核签名
        </a-button>
      </a-tooltip>
      <a-tooltip
        title="把本地所有签名一次性向阿里云查最新审核状态并回写,适合提交后过段时间批量刷新"
      >
        <a-button
          class="ml-2"
          @click="handleSyncAll"
          v-access:code="'SmsSignSyncAll'"
        >
          批量同步状态
        </a-button>
      </a-tooltip>
      <a-button class="ml-2" @click="refresh" v-access:code="'SmsSignList'">
        刷新
      </a-button>
    </div>

    <a-form layout="inline" class="mb-4" v-access:code="'SmsSignList'">
      <a-form-item label="服务商">
        <a-select
          v-model:value="searchParams.provider_id"
          placeholder="全部"
          allow-clear
          :options="providers.map((p) => ({ label: p.name, value: p.id }))"
          style="width: 180px"
        />
      </a-form-item>
      <a-form-item label="关键词">
        <a-input
          v-model:value="searchParams.keyword"
          placeholder="签名名称"
          allow-clear
          style="width: 200px"
        />
      </a-form-item>
      <a-form-item label="审核">
        <a-select
          v-model:value="searchParams.audit_status"
          placeholder="全部"
          allow-clear
          :options="auditStatusOptions.map((o) => ({ label: o.label, value: o.value }))"
          style="width: 140px"
        />
      </a-form-item>
      <a-form-item>
        <a-button
          type="primary"
          @click="
            () => {
              pagination.current = 1;
              loadData(searchParams);
            }
          "
        >
          搜索
        </a-button>
        <a-button class="ml-2" @click="resetSearch">重置</a-button>
      </a-form-item>
    </a-form>

    <a-table
      :columns="columns"
      :data-source="tableData"
      :loading="loading"
      :pagination="pagination"
      :scroll="{ x: 1200 }"
      row-key="id"
      @change="
        (newPagination) => {
          pagination.current = newPagination.current;
          pagination.pageSize = newPagination.pageSize;
          loadData(searchParams);
        }
      "
      v-access:code="'SmsSignList'"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.dataIndex === 'provider_id'">
          {{ providerName(record.provider_id) }}
        </template>
        <template v-if="column.dataIndex === 'sign_type'">
          {{ record.sign_type === 0 ? '验证码' : '通用' }}
        </template>
        <template v-if="column.dataIndex === 'audit_status'">
          <a-tag :color="auditStatusTag(record.audit_status)?.color">
            {{ auditStatusTag(record.audit_status)?.label || record.audit_status }}
          </a-tag>
        </template>
        <template v-if="column.dataIndex === 'audit_reason'">
          <div
            class="whitespace-pre-wrap break-all text-xs leading-relaxed"
            style="max-height: 120px; overflow-y: auto"
          >
            {{ record.audit_reason || '-' }}
          </div>
        </template>
        <template v-if="column.key === 'action'">
          <a-space>
            <a-button
              v-if="!isPnvsRow(record)"
              type="link"
              size="small"
              @click="handleSync(record)"
              v-access:code="'SmsSignSyncStatus'"
            >
              同步状态
            </a-button>
            <a-button
              type="link"
              danger
              size="small"
              @click="handleDelete(record, 'sign_name')"
              v-access:code="'SmsSignDelete'"
            >
              删除
            </a-button>
          </a-space>
        </template>
      </template>
    </a-table>

    <a-modal
      v-model:open="modalVisible"
      :title="modalTitle"
      width="600px"
      @ok="handleFormSubmit"
    >
      <a-form
        ref="formRef"
        :model="formData"
        :label-col="{ span: 6 }"
        :wrapper-col="{ span: 16 }"
      >
        <a-alert
          type="info"
          show-icon
          message="签名为阿里云号码认证服务（PNVS）系统赠送,请到 PNVS 控制台「赠送签名配置」页面查看可用签名名称后填入,仅本地登记,不推送远端审核。"
          class="mb-3"
        />
        <a-form-item
          label="服务商"
          name="provider_id"
          :rules="[{ required: true, message: '请选择服务商' }]"
        >
          <a-select
            v-model:value="formData.provider_id"
            :options="pnvsProviders.map((p) => ({ label: p.name, value: p.id }))"
          />
        </a-form-item>
        <a-form-item
          label="签名名称"
          name="sign_name"
          :rules="[{ required: true, message: '请输入签名名称' }]"
        >
          <a-input
            v-model:value="formData.sign_name"
            placeholder="PNVS 控制台「赠送签名配置」中的签名文本"
          />
        </a-form-item>
        <a-form-item label="备注" name="remark">
          <a-textarea
            v-model:value="formData.remark"
            :rows="3"
            placeholder="可选:留作备注,例如「营销验证码」"
          />
        </a-form-item>
      </a-form>
    </a-modal>

    <a-modal
      v-model:open="importModalVisible"
      title="从阿里云导入已审核签名"
      width="520px"
      :confirm-loading="importing"
      @ok="handleImportSubmit"
    >
      <a-alert
        type="info"
        show-icon
        message="只调用 QuerySmsSign 把阿里云上已审核通过的签名拉回本地,不会触发新审核。PNVS 服务商不支持导入。"
        class="mb-4"
      />
      <a-form :label-col="{ span: 6 }" :wrapper-col="{ span: 16 }">
        <a-form-item label="服务商" required>
          <a-select
            v-model:value="importFormData.provider_id"
            :options="
              importableProviders.map((p) => ({ label: p.name, value: p.id }))
            "
          />
        </a-form-item>
        <a-form-item label="签名名称" required>
          <a-input
            v-model:value="importFormData.sign_name"
            placeholder="必须与阿里云控制台的签名文本完全一致"
          />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>
