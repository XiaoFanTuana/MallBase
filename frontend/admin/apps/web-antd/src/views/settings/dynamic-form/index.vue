<script lang="ts" setup>
import type { SettingApi } from '#/api/setting';

import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';

import { message, Spin } from 'ant-design-vue';

import { uploadFileApi, uploadImageApi } from '#/api/core/upload';
import { getSettingConfigApi, saveSettingConfigApi } from '#/api/setting';
import Upload from '#/components/upload/index.vue';

defineOptions({ name: 'SettingDynamicForm' });

const route = useRoute();
const loading = ref(false);
const saving = ref(false);
const groupInfo = ref<SettingApi.ConfigResponse['group']>();
const settings = ref<SettingApi.SettingItem[]>([]);
const formValues = ref<Record<string, any>>({});

/** 从路由路径中提取 groupCode */
const groupCode = computed(() => {
  const path = route.path;
  const segments = path.split('/').filter(Boolean);
  return segments[segments.length - 1] || '';
});

/** 按类型分组 */
const basicSettings = computed(() =>
  settings.value.filter((s) =>
    [
      'checkbox',
      'input',
      'number',
      'password',
      'radio',
      'select',
      'switch',
      'textarea',
    ].includes(s.type),
  ),
);

const mediaSettings = computed(() =>
  settings.value.filter((s) =>
    ['file', 'files', 'image', 'images'].includes(s.type),
  ),
);

const advancedSettings = computed(() =>
  settings.value.filter((s) => ['editor', 'json'].includes(s.type)),
);

/** 加载配置 */
const loadConfig = async () => {
  if (!groupCode.value) return;

  loading.value = true;
  try {
    const res = await getSettingConfigApi(groupCode.value);
    groupInfo.value = res.group;
    settings.value = res.settings;

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

/** 根据类型转换值 */
const convertValue = (value: string, type: string) => {
  if (value === undefined || value === null) return undefined;

  switch (type) {
    case 'checkbox': {
      if (typeof value === 'string' && value.startsWith('[')) {
        try {
          return JSON.parse(value);
        } catch {
          return value ? value.split(',') : [];
        }
      }
      return value ? value.split(',') : [];
    }
    case 'files':
    case 'images': {
      if (typeof value === 'string' && value.startsWith('[')) {
        try {
          return JSON.parse(value);
        } catch {
          return [];
        }
      }
      return [];
    }
    case 'number': {
      const num = Number(value);
      return Number.isNaN(num) ? value : num;
    }
    case 'switch': {
      return value === '1' || value === 'true';
    }
    default: {
      return value;
    }
  }
};

/** 序列化值 */
const serializeValue = (value: any, type: string): any => {
  if (value === undefined || value === null) return '';

  switch (type) {
    case 'checkbox': {
      if (Array.isArray(value)) {
        return JSON.stringify(value);
      }
      return String(value);
    }
    case 'files':
    case 'images': {
      if (Array.isArray(value)) {
        return JSON.stringify(value);
      }
      return String(value);
    }
    case 'number': {
      return String(value);
    }
    case 'switch': {
      return value ? '1' : '0';
    }
    default: {
      return String(value);
    }
  }
};

/** 解析 options */
const parseOptions = (options: any) => {
  if (!options) return [];
  if (typeof options === 'string') {
    try {
      return JSON.parse(options);
    } catch {
      return [];
    }
  }
  return options;
};

/** 获取 editor 预览内容 */
const getEditorHtml = (code: string) => {
  return formValues.value[code] || '';
};

/** 上传处理 */
const handleUpload = (code: string, type: string) => {
  return async (file: File) => {
    try {
      const isImage = ['image', 'images'].includes(type);
      const res = isImage
        ? await uploadImageApi(file)
        : await uploadFileApi(file);
      const url = res?.url || res?.data?.url || '';

      if (['files', 'images'].includes(type)) {
        const current = formValues.value[code] || [];
        formValues.value[code] = [...current, url];
      } else {
        formValues.value[code] = url;
      }
    } catch (error) {
      console.error('上传失败:', error);
      message.error('上传失败');
    }
  };
};

const handleUploadRemove = (code: string, type: string) => {
  return async (index?: number) => {
    if (['files', 'images'].includes(type)) {
      const current = formValues.value[code] || [];
      if (index !== undefined) {
        current.splice(index, 1);
        formValues.value[code] = [...current];
      }
    } else {
      formValues.value[code] = '';
    }
  };
};

/** 保存配置 */
const handleSave = async () => {
  if (!groupCode.value) return;

  for (const item of settings.value) {
    if (item.is_required === 1) {
      const val = formValues.value[item.code];
      if (
        val === undefined ||
        val === null ||
        val === '' ||
        (Array.isArray(val) && val.length === 0)
      ) {
        message.warning(`请填写「${item.name}」`);
        return;
      }
    }
  }

  for (const item of settings.value.filter((s) => s.type === 'json')) {
    const val = formValues.value[item.code];
    if (val) {
      try {
        JSON.parse(val);
      } catch {
        message.warning(`「${item.name}」JSON 格式不正确`);
        return;
      }
    }
  }

  saving.value = true;
  try {
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

watch(() => route.path, loadConfig);

onMounted(loadConfig);
</script>

<template>
  <div class="setting-form-page">
    <Spin :spinning="loading">
      <!-- 页面头部 -->
      <div v-if="groupInfo" class="setting-header">
        <div class="header-content">
          <div v-if="groupInfo.icon" class="header-icon">
            <span :class="`i-${groupInfo.icon}`" class="text-2xl"></span>
          </div>
          <div class="header-text">
            <h2 class="header-title">{{ groupInfo.name }}</h2>
            <p class="header-desc">共 {{ settings.length }} 项配置</p>
          </div>
        </div>
      </div>

      <!-- 基础设置 -->
      <div v-if="basicSettings.length > 0" class="setting-section">
        <div class="section-title">
          <span class="i-ant-design:setting-outlined mr-2 text-lg"></span>
          基础设置
        </div>
        <div class="setting-card">
          <div class="form-grid">
            <div
              v-for="item in basicSettings"
              :key="item.code"
              class="form-item-wrapper"
            >
              <div class="form-label">
                <span class="label-text">{{ item.name }}</span>
                <span v-if="item.is_required === 1" class="required-star"
                  >*</span
                >
              </div>

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
                :rows="3"
              />

              <!-- number -->
              <a-input-number
                v-else-if="item.type === 'number'"
                v-model:value="formValues[item.code]"
                :placeholder="item.placeholder || `请输入${item.name}`"
                class="w-full"
              />

              <!-- switch -->
              <div v-else-if="item.type === 'switch'" class="switch-wrapper">
                <a-switch v-model:checked="formValues[item.code]" />
                <span class="switch-label">
                  {{ formValues[item.code] ? '已开启' : '已关闭' }}
                </span>
              </div>

              <!-- select -->
              <a-select
                v-else-if="item.type === 'select'"
                v-model:value="formValues[item.code]"
                :placeholder="item.placeholder || `请选择${item.name}`"
                :options="parseOptions(item.options)"
                class="w-full"
              />

              <!-- radio -->
              <a-radio-group
                v-else-if="item.type === 'radio'"
                v-model:value="formValues[item.code]"
                :options="parseOptions(item.options)"
              />

              <!-- checkbox -->
              <a-checkbox-group
                v-else-if="item.type === 'checkbox'"
                v-model:value="formValues[item.code]"
                :options="parseOptions(item.options)"
              />

              <div v-if="item.remark" class="form-remark">
                {{ item.remark }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 媒体文件 -->
      <div v-if="mediaSettings.length > 0" class="setting-section">
        <div class="section-title">
          <span class="i-ant-design:picture-outlined mr-2 text-lg"></span>
          媒体文件
        </div>
        <div class="setting-card">
          <div class="media-grid">
            <div
              v-for="item in mediaSettings"
              :key="item.code"
              class="media-item-wrapper"
            >
              <div class="form-label">
                <span class="label-text">{{ item.name }}</span>
                <span v-if="item.is_required === 1" class="required-star">*</span>
              </div>
              <Upload
                :type="item.type as any"
                :value="formValues[item.code]"
                :custom-upload="handleUpload(item.code, item.type)"
                :custom-remove="handleUploadRemove(item.code, item.type)"
              />
              <div v-if="item.remark" class="form-remark">
                {{ item.remark }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 高级配置 -->
      <div v-if="advancedSettings.length > 0" class="setting-section">
        <div class="section-title">
          <span class="i-ant-design:code-outlined mr-2 text-lg"></span>
          高级配置
        </div>
        <div class="setting-card">
          <div class="advanced-grid">
            <div
              v-for="item in advancedSettings"
              :key="item.code"
              class="advanced-item-wrapper"
            >
              <div class="form-label">
                <span class="label-text">{{ item.name }}</span>
                <span v-if="item.is_required === 1" class="required-star"
                  >*</span
                >
              </div>

              <!-- editor -->
              <template v-if="item.type === 'editor'">
                <a-tabs type="card" size="small" class="editor-tabs">
                  <a-tab-pane key="edit" tab="编辑">
                    <a-textarea
                      v-model:value="formValues[item.code]"
                      :placeholder="item.placeholder || '请输入内容'"
                      :rows="8"
                      class="font-mono text-sm"
                    />
                  </a-tab-pane>
                  <a-tab-pane key="preview" tab="预览">
                    <div class="editor-preview">
                      <div
                        v-if="getEditorHtml(item.code)"
                        v-html="getEditorHtml(item.code)"
                      ></div>
                      <span v-else class="text-gray-300">暂无内容</span>
                    </div>
                  </a-tab-pane>
                </a-tabs>
              </template>

              <!-- json -->
              <template v-else-if="item.type === 'json'">
                <a-textarea
                  v-model:value="formValues[item.code]"
                  placeholder='{"key": "value"}'
                  :rows="6"
                  class="font-mono text-sm"
                />
              </template>

              <div v-if="item.remark" class="form-remark">
                {{ item.remark }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 空状态 -->
      <div v-if="settings.length === 0 && !loading" class="empty-state">
        <span class="i-ant-design:inbox-outlined text-5xl text-gray-300"></span>
        <p class="mt-3 text-gray-400">暂无配置项</p>
      </div>

      <!-- 底部保存栏 -->
      <div v-if="settings.length > 0" class="save-bar">
        <div class="save-bar-inner">
          <span class="save-tip">修改后请点击保存</span>
          <a-button
            type="primary"
            size="large"
            :loading="saving"
            @click="handleSave"
          >
            <template #icon>
              <span class="i-ant-design:save-outlined mr-1"></span>
            </template>
            保存设置
          </a-button>
        </div>
      </div>
    </Spin>
  </div>
</template>

<style lang="css" scoped>
.setting-form-page {
  min-height: 100%;
  padding: 24px;
  background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%);
}

.setting-header {
  margin-bottom: 24px;
  padding: 24px 28px;
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 1px 4px rgb(0 0 0 / 5%);
}

.header-content {
  display: flex;
  align-items: center;
  gap: 16px;
}

.header-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  border-radius: 12px;
  color: #fff;
  flex-shrink: 0;
}

.header-title {
  margin: 0;
  font-size: 20px;
  font-weight: 600;
  color: #1a1a2e;
}

.header-desc {
  margin: 4px 0 0;
  font-size: 13px;
  color: #8c8c8c;
}

.setting-section {
  margin-bottom: 20px;
}

.section-title {
  display: flex;
  align-items: center;
  margin-bottom: 12px;
  padding-left: 4px;
  font-size: 15px;
  font-weight: 600;
  color: #333;
}

.setting-card {
  padding: 24px;
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 1px 4px rgb(0 0 0 / 5%);
}

.form-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
  gap: 24px;
}

.media-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 24px;
}

.advanced-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 24px;
}

.form-item-wrapper,
.media-item-wrapper,
.advanced-item-wrapper {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.form-label {
  display: flex;
  align-items: center;
  gap: 2px;
  font-size: 14px;
  font-weight: 500;
  color: #333;
}

.required-star {
  color: #ff4d4f;
  margin-left: 2px;
}

.form-remark {
  font-size: 12px;
  color: #8c8c8c;
  line-height: 1.5;
  margin-top: 2px;
}

.switch-wrapper {
  display: flex;
  align-items: center;
  gap: 10px;
  height: 32px;
}

.switch-label {
  font-size: 13px;
  color: #8c8c8c;
}

.editor-tabs {
  border: 1px solid #f0f0f0;
  border-radius: 8px;
  overflow: hidden;
}

.editor-preview {
  min-height: 160px;
  padding: 12px 16px;
  background: #fafafa;
  border-radius: 0 0 8px 8px;
  font-size: 14px;
  line-height: 1.8;
  color: #333;
}

.editor-preview :deep(img) {
  max-width: 100%;
  border-radius: 4px;
}

.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 80px 0;
}

.save-bar {
  position: sticky;
  bottom: 0;
  z-index: 10;
  margin-top: 24px;
  padding: 16px 24px;
  background: #fff;
  border-top: 1px solid #f0f0f0;
  border-radius: 12px;
  box-shadow: 0 -2px 8px rgb(0 0 0 / 6%);
}

.save-bar-inner {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 16px;
}

.save-tip {
  font-size: 13px;
  color: #8c8c8c;
}

@media (width <= 768px) {
  .setting-form-page {
    padding: 16px;
  }

  .form-grid,
  .media-grid {
    grid-template-columns: 1fr;
  }
}
</style>
