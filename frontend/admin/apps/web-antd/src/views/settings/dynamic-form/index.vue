<script lang="ts" setup>
import type { SettingApi } from '#/api/setting';

import { computed, onMounted, ref, watch } from 'vue';

import { useRoute } from 'vue-router';

import { message, Spin } from 'ant-design-vue';

import {
  getSettingConfigApi,
  saveSettingConfigApi,
} from '#/api/setting';

defineOptions({ name: 'SettingDynamicForm' });

const route = useRoute();
const loading = ref(false);
const saving = ref(false);
const groupInfo = ref<SettingApi.ConfigResponse['group']>();
const settings = ref<SettingApi.SettingItem[]>([]);
const formValues = ref<Record<string, any>>({});

/** 从路由路径中提取 groupCode：/settings/wechat → wechat */
const groupCode = computed(() => {
  const path = route.path;
  const segments = path.split('/').filter(Boolean);
  // 路径格式: /settings/{groupCode}
  return segments[segments.length - 1] || '';
});

/** 加载配置 */
const loadConfig = async () => {
  if (!groupCode.value) return;

  loading.value = true;
  try {
    const res = await getSettingConfigApi(groupCode.value);
    groupInfo.value = res.group;
    settings.value = res.settings;

    // 初始化表单值
    const values: Record<string, any> = {};
    for (const item of res.settings) {
      values[item.code] = convertValue(item.value, item.type);
    }
    formValues.value = values;
  } catch (error) {
    console.error('加载配置失败:', error);
    message.error('加载配置失败');
  } finally {
    loading.value = false;
  }
};

/** 根据类型转换后端返回的字符串值 */
const convertValue = (value: string, type: string) => {
  if (value === undefined || value === null) return undefined;

  switch (type) {
    case 'switch': {
      return value === '1' || value === 'true';
    }
    case 'number': {
      const num = Number(value);
      return Number.isNaN(num) ? value : num;
    }
    case 'checkbox': {
      // checkbox 可能是 JSON 数组字符串
      if (typeof value === 'string' && value.startsWith('[')) {
        try {
          return JSON.parse(value);
        } catch {
          return value ? value.split(',') : [];
        }
      }
      return value ? value.split(',') : [];
    }
    case 'images':
    case 'files': {
      // JSON 数组字符串
      if (typeof value === 'string' && value.startsWith('[')) {
        try {
          return JSON.parse(value);
        } catch {
          return [];
        }
      }
      return [];
    }
    default: {
      return value;
    }
  }
};

/** 将表单值转换回后端需要的字符串格式 */
const serializeValue = (value: any, type: string): any => {
  if (value === undefined || value === null) return '';

  switch (type) {
    case 'switch': {
      return value ? '1' : '0';
    }
    case 'checkbox': {
      if (Array.isArray(value)) {
        return value.join(',');
      }
      return String(value);
    }
    case 'images':
    case 'files': {
      if (Array.isArray(value)) {
        return JSON.stringify(value);
      }
      return String(value);
    }
    case 'number': {
      return String(value);
    }
    default: {
      return String(value);
    }
  }
};

/** 保存配置 */
const handleSave = async () => {
  if (!groupCode.value) return;

  // 必填校验
  for (const item of settings.value) {
    if (item.is_required === 1) {
      const val = formValues.value[item.code];
      if (
        val === undefined ||
        val === null ||
        val === '' ||
        (Array.isArray(val) && val.length === 0)
      ) {
        message.warning(`请填写${item.name}`);
        return;
      }
    }
  }

  saving.value = true;
  try {
    // 将表单值序列化为后端需要的格式
    const submitData: Record<string, any> = {};
    for (const item of settings.value) {
      submitData[item.code] = serializeValue(
        formValues.value[item.code],
        item.type,
      );
    }

    await saveSettingConfigApi(groupCode.value, submitData);
    message.success('保存成功');
  } catch (error) {
    console.error('保存失败:', error);
    message.error('保存失败');
  } finally {
    saving.value = false;
  }
};

/** 监听路由变化重新加载 */
watch(() => route.path, loadConfig);

onMounted(loadConfig);
</script>

<template>
  <div class="p-4">
    <Spin :spinning="loading">
      <!-- 页面标题 -->
      <div v-if="groupInfo" class="mb-6">
        <h2 class="text-xl font-semibold">
          {{ groupInfo.name }}
        </h2>
      </div>

      <!-- 动态表单 -->
      <div v-if="settings.length > 0" class="max-w-3xl">
        <a-form layout="vertical">
          <a-form-item
            v-for="item in settings"
            :key="item.code"
            :label="item.name"
            :required="item.is_required === 1"
            :extra="item.remark"
          >
            <!-- input -->
            <a-input
              v-if="item.type === 'input'"
              v-model:value="formValues[item.code]"
              :placeholder="item.placeholder || `请输入${item.name}`"
            />

            <!-- password -->
            <a-input-password
              v-else-if="item.type === 'password'"
              v-model:value="formValues[item.code]"
              :placeholder="item.placeholder || `请输入${item.name}`"
            />

            <!-- textarea -->
            <a-textarea
              v-else-if="item.type === 'textarea'"
              v-model:value="formValues[item.code]"
              :placeholder="item.placeholder || `请输入${item.name}`"
              :rows="4"
            />

            <!-- number -->
            <a-input-number
              v-else-if="item.type === 'number'"
              v-model:value="formValues[item.code]"
              :placeholder="item.placeholder || `请输入${item.name}`"
              class="w-full"
            />

            <!-- switch -->
            <a-switch
              v-else-if="item.type === 'switch'"
              v-model:checked="formValues[item.code]"
            />

            <!-- select -->
            <a-select
              v-else-if="item.type === 'select'"
              v-model:value="formValues[item.code]"
              :placeholder="item.placeholder || `请选择${item.name}`"
              :options="
                typeof item.options === 'string'
                  ? JSON.parse(item.options)
                  : item.options || []
              "
            />

            <!-- radio -->
            <a-radio-group
              v-else-if="item.type === 'radio'"
              v-model:value="formValues[item.code]"
              :options="
                typeof item.options === 'string'
                  ? JSON.parse(item.options)
                  : item.options || []
              "
            />

            <!-- checkbox -->
            <a-checkbox-group
              v-else-if="item.type === 'checkbox'"
              v-model:value="formValues[item.code]"
              :options="
                typeof item.options === 'string'
                  ? JSON.parse(item.options)
                  : item.options || []
              "
            />

            <!-- json（使用 textarea，后续可替换为 JSON 编辑器） -->
            <a-textarea
              v-else-if="item.type === 'json'"
              v-model:value="formValues[item.code]"
              :placeholder="item.placeholder || '请输入 JSON'"
              :rows="6"
              class="font-mono"
            />

            <!-- editor（使用 textarea，后续可替换为富文本编辑器） -->
            <a-textarea
              v-else-if="item.type === 'editor'"
              v-model:value="formValues[item.code]"
              :placeholder="item.placeholder || `请输入内容`"
              :rows="8"
            />

            <!-- 默认 fallback：input -->
            <a-input
              v-else
              v-model:value="formValues[item.code]"
              :placeholder="item.placeholder || `请输入${item.name}`"
            />
          </a-form-item>

          <!-- 提交按钮 -->
          <a-form-item>
            <a-button
              type="primary"
              :loading="saving"
              @click="handleSave"
            >
              保存设置
            </a-button>
          </a-form-item>
        </a-form>
      </div>

      <!-- 空状态 -->
      <a-empty
        v-else-if="!loading"
        description="暂无配置项"
      />
    </Spin>
  </div>
</template>