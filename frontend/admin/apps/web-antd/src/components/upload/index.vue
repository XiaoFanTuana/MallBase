<script lang="ts" setup>
import type { UploadFile, UploadProps } from 'ant-design-vue';

import type { UploadParams } from '#/api/core/upload';

import { computed, onMounted, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import { getUploadConfigApi, uploadSingleApi } from '#/api/core/upload';

/** 文件信息对象 */
export interface FileInfo {
  url: string;
  full_url?: string;
  name: string;
}

/**
 * Upload 组件
 *
 * @example
 * <Upload type="image" :value="fileList" module="dynamic_form" :related-id="123" />
 * <Upload type="image" :value="fileList" :custom-upload="handleUpload" />
 */
interface Props {
  /** 上传类型：image=单图 | images=多图 | file=单文件 | files=多文件，默认 image */
  type?: 'file' | 'files' | 'image' | 'images';
  /** 已上传的文件值 */
  value?: FileInfo | FileInfo[] | string;
  /** 是否禁用上传，默认 false */
  disabled?: boolean;
  /** 文件大小上限（MB），不传则从后端获取 */
  maxSize?: number;
  /** 最大上传数量，不传则从后端获取 */
  maxCount?: number;
  /** 允许的 MIME 类型数组，不传则从后端获取 */
  accept?: string[];
  /** 是否显示已上传文件列表，默认 true */
  showUploadList?: boolean;
  /**
   * 模块名：dynamic_form / admin 等
   * 不传 customUpload 时，组件内部调用 uploadSingleApi 会带上此参数
   */
  module?: string;
  /**
   * 关联 ID（如设置项 ID）
   * 配合 module 使用
   */
  relatedId?: number | string;
  /** 是否支持上传文件夹，默认根据 type 自动判断（files 类型为 true），传了以传的为准 */
  directory?: boolean;
  /** 是否支持多选，默认根据 type 自动判断（files/images 为 true），传了以传的为准 */
  multiple?: boolean;
  /**
   * 自定义上传方法（可选）
   * 不传则组件内部自动调用 uploadSingleApi
   */
  customUpload?: (
    file: File,
    params: UploadParams,
  ) => Promise<FileInfo | undefined>;
  /** 自定义删除方法 */
  customRemove?: (index?: number) => Promise<void>;
}

const props = withDefaults(defineProps<Props>(), {
  type: 'image',
  value: undefined,
  disabled: false,
  maxCount: undefined,
  accept: undefined,
  maxSize: undefined,
  showUploadList: true,
  module: undefined,
  relatedId: undefined,
  directory: undefined,
  multiple: undefined,
  customUpload: undefined,
  customRemove: undefined,
});

const emit = defineEmits<{
  (e: 'update:value', value: any): void;
}>();

// ==================== 后端配置 ====================

const remoteConfig = ref<null | {
  acceptTypes: string[];
  maxCount: number;
  maxSize: number;
}>(null);

const loadRemoteConfig = async () => {
  if (
    props.maxSize !== undefined &&
    props.maxCount !== undefined &&
    props.accept !== undefined
  ) {
    return;
  }
  try {
    const res = await getUploadConfigApi(props.type);
    if (res) {
      remoteConfig.value = {
        maxSize: res.max_size,
        maxCount: res.max_count,
        acceptTypes: res.accept_types,
      };
    }
  } catch (error) {
    console.warn('获取上传配置失败，使用前端兜底配置:', error);
  }
};

onMounted(loadRemoteConfig);

// ==================== 合并后的配置 ====================

const fallbackConfig: Record<string, { maxCount: number; maxSize: number }> = {
  file: { maxCount: 1, maxSize: 10 },
  files: { maxCount: 5, maxSize: 10 },
  image: { maxCount: 1, maxSize: 2 },
  images: { maxCount: 9, maxSize: 5 },
};

const effectiveMaxSize = computed(() => {
  if (props.maxSize !== undefined) return props.maxSize;
  return (
    remoteConfig.value?.maxSize ?? fallbackConfig[props.type]?.maxSize ?? 5
  );
});

const effectiveMaxCount = computed(() => {
  if (props.maxCount !== undefined) return props.maxCount;
  return (
    remoteConfig.value?.maxCount ?? fallbackConfig[props.type]?.maxCount ?? 1
  );
});

const effectiveAcceptTypes = computed(() => {
  if (props.accept) return props.accept;
  return remoteConfig.value?.acceptTypes ?? [];
});

const isImageType = computed(() => ['image', 'images'].includes(props.type));

/** 多选：files/images 自动开启，传了 multiple 以传的为准 */
const effectiveMultiple = computed(() => {
  if (props.multiple !== undefined) return props.multiple;
  return ['files', 'images'].includes(props.type);
});

/** 文件夹上传：默认关闭，传了 directory 以传的为准 */
const effectiveDirectory = computed(() => {
  if (props.directory !== undefined) return props.directory;
  return false;
});

// ==================== 工具函数 ====================

const extractFileName = (url: string): string => {
  if (!url) return '未知文件';
  const decoded = decodeURIComponent(url);
  const segments = decoded.split('/');
  const lastSegment = segments.pop() || '';
  const name = lastSegment.split('?')[0] || '';
  if (name.length > 40) {
    const ext = name.includes('.') ? `.${name.split('.').pop()}` : '';
    return `${name.slice(0, 30)}...${ext}`;
  }
  return name || '未知文件';
};

const toFullUrl = (path: string) => {
  if (!path) return '';
  if (path.startsWith('http://') || path.startsWith('https://')) return path;
  const base = import.meta.env.VITE_GLOB_API_URL || '';
  return `${base}${path}`;
};

// ==================== 上传配置 ====================

const uploadProps = computed<UploadProps>(() => ({
  name: 'file',
  maxCount: effectiveMaxCount.value,
  listType: isImageType.value ? 'picture-card' : 'text',
  showUploadList: props.showUploadList
    ? { showDownloadIcon: false, showPreviewIcon: true, showRemoveIcon: true }
    : false,
  directory: effectiveDirectory.value || undefined,
  multiple: effectiveMultiple.value,
  beforeUpload: handleBeforeUpload,
  customRequest: handleCustomRequest,
  onRemove: handleRemove,
}));

const buildFileList = (): UploadFile[] => {
  const val = props.value;
  if (!val) return [];

  if (Array.isArray(val)) {
    return val.map((item: FileInfo, index: number) => ({
      uid: `${index}`,
      name: item.name || extractFileName(item.url),
      status: 'done' as const,
      url: item.full_url || item.url,
    }));
  }

  if (typeof val === 'object') {
    return [
      {
        uid: '0',
        name: (val as FileInfo).name || extractFileName((val as FileInfo).url),
        status: 'done' as const,
        url: (val as FileInfo).full_url || (val as FileInfo).url,
      },
    ];
  }

  return [
    {
      uid: '0',
      name: extractFileName(val),
      status: 'done' as const,
      url: val,
    },
  ];
};

const fileList = ref<UploadFile[]>([]);

watch(
  () => props.value,
  () => {
    fileList.value = buildFileList();
  },
  { immediate: true, deep: true },
);

// ==================== 事件处理 ====================

const handleBeforeUpload = (file: File) => {
  const maxSize = effectiveMaxSize.value;
  if (file.size / 1024 / 1024 > maxSize) {
    message.error(`文件大小不能超过 ${maxSize}MB`);
    return false;
  }
  const acceptTypes = effectiveAcceptTypes.value;
  if (acceptTypes.length > 0 && !acceptTypes.includes(file.type)) {
    message.error('不支持的文件类型');
    return false;
  }
  return true;
};

/** 构建 UploadParams */
const buildUploadParams = (): UploadParams => ({
  type: props.type,
  module: props.module,
  related_id: props.relatedId,
});

const handleCustomRequest = async (options: any) => {
  const { file, onSuccess, onError } = options;
  try {
    const params = buildUploadParams();
    let fileInfo: FileInfo | undefined;

    if (props.customUpload) {
      // 用户传了自定义上传方法，使用用户的
      fileInfo = await props.customUpload(file as File, params);
    } else {
      // 使用内置上传逻辑
      const res = await uploadSingleApi(file as File, params);
      if (res) {
        fileInfo = {
          url: res.url,
          full_url: res.full_url || toFullUrl(res.url),
          name: res.name || (file as File).name,
        };
      }
    }

    if (fileInfo) {
      if (['files', 'images'].includes(props.type)) {
        const current = Array.isArray(props.value) ? [...props.value] : [];
        current.push(fileInfo);
        emit('update:value', current);
      } else {
        emit('update:value', fileInfo);
      }
    }

    onSuccess?.({}, file);
  } catch (error) {
    console.error('上传失败:', error);
    message.error('上传失败');
    onError?.(error);
  }
};

const handleRemove = async (file: UploadFile) => {
  if (props.customRemove) {
    const index = fileList.value.findIndex((item) => item.uid === file.uid);
    await props.customRemove(index === -1 ? undefined : index);
  } else if (['files', 'images'].includes(props.type)) {
    const index = fileList.value.findIndex((item) => item.uid === file.uid);
    if (index !== -1) {
      const current = Array.isArray(props.value) ? [...props.value] : [];
      current.splice(index, 1);
      emit('update:value', current);
    }
  } else {
    emit('update:value', undefined);
  }
};

const showUploadButton = computed(() => {
  if (props.disabled) return false;
  const val = props.value;
  if (Array.isArray(val)) {
    return val.length < effectiveMaxCount.value;
  }
  return !val;
});

const handlePreview = (file: UploadFile) => {
  if (file.url) {
    window.open(file.url, '_blank');
  }
};
</script>

<template>
  <a-upload
    v-bind="uploadProps"
    :file-list="fileList"
    :disabled="disabled"
    @preview="handlePreview"
  >
    <!-- 图片类型：缩略图卡片 -->
    <template v-if="isImageType && showUploadButton">
      <div>
        <span>+</span>
        <div style="margin-top: 8px">
          {{ type === 'image' ? '上传图片' : '添加图片' }}
        </div>
      </div>
    </template>

    <!-- 文件类型：按钮上传 -->
    <template v-else-if="showUploadButton">
      <a-button>
        <template #icon>
          <span>📤</span>
        </template>
        {{
          directory ? '上传文件夹' : type === 'file' ? '上传文件' : '添加文件'
        }}
      </a-button>
    </template>
  </a-upload>
</template>

<style scoped>
:deep(.ant-upload-list) {
  margin-top: 8px;
}

:deep(.ant-upload-list-picture-card .ant-upload-list-item) {
  border-radius: 8px;
}

:deep(.ant-upload-list-picture-card .ant-upload-list-item-thumbnail) {
  object-fit: cover;
}

:deep(.ant-upload-list-text .ant-upload-list-item) {
  padding: 4px 8px;
  border-radius: 6px;
  transition: background-color 0.2s;
}

:deep(.ant-upload-list-text .ant-upload-list-item:hover) {
  background-color: #f5f5f5;
}
</style>
