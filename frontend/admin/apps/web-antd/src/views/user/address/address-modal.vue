<script lang="ts" setup>
import type { ClientUserApi, UserAddressApi } from '#/api/user';

import { computed, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import {
  createUserAddressApi,
  getClientUserListApi,
  updateUserAddressApi,
} from '#/api/user';
import RegionPicker from '#/components/region-picker/index.vue';

defineOptions({ name: 'UserAddressModal' });

const props = withDefaults(defineProps<{
  visible?: boolean;
  editData?: UserAddressApi.AddressItem | null;
}>(), {
  visible: false,
  editData: null,
});

const emit = defineEmits<{
  (e: 'update:visible', value: boolean): void;
  (e: 'success'): void;
}>();

const isEdit = computed(() => !!props.editData?.id);
const title = computed(() => (isEdit.value ? '编辑地址' : '新增地址'));
const formRef = ref();
const loading = ref(false);
const userOptions = ref<ClientUserApi.UserItem[]>([]);

const formData = ref({
  user_id: undefined as number | undefined,
  receiver_name: '',
  receiver_mobile: '',
  region_path: [] as number[],
  address_detail: '',
  tag: '',
  is_default: 0,
});

const rules = {
  user_id: [{ required: true, message: '请选择用户' }],
  receiver_name: [{ required: true, message: '请输入收货人' }],
  receiver_mobile: [{ required: true, pattern: /^1[3-9]\d{9}$/, message: '联系电话格式不正确' }],
  region_path: [{ required: true, type: 'array' as const, min: 4, message: '请选择省市区街道' }],
  address_detail: [{ required: true, message: '请输入详细地址' }],
};

async function loadUsers(keyword = '') {
  const result = await getClientUserListApi({ keyword, limit: 20 });
  userOptions.value = result.list;
}

async function handleSubmit() {
  await formRef.value?.validate();
  loading.value = true;
  try {
    if (formData.value.region_path.length !== 4) {
      message.error('请选择完整的省市区街道');
      return;
    }

    const regionPath = formData.value.region_path.map(Number) as [number, number, number, number];
    const [province_id, city_id, district_id, street_id] = regionPath;
    const payload = {
      user_id: Number(formData.value.user_id),
      receiver_name: formData.value.receiver_name,
      receiver_mobile: formData.value.receiver_mobile,
      province_id,
      city_id,
      district_id,
      street_id,
      address_detail: formData.value.address_detail,
      tag: formData.value.tag || undefined,
      is_default: formData.value.is_default,
    };

    if (isEdit.value) {
      await updateUserAddressApi(props.editData!.id, payload);
      message.success('更新成功');
    } else {
      await createUserAddressApi(payload);
      message.success('创建成功');
    }
    emit('success');
    handleClose();
  } finally {
    loading.value = false;
  }
}

function handleClose() {
  emit('update:visible', false);
}

watch(
  () => props.visible,
  (visible) => {
    if (visible) {
      loadUsers();
    }
  },
);

watch(
  () => props.editData,
  (data) => {
    formData.value = data ? {
      user_id: data.user_id,
      receiver_name: data.receiver_name,
      receiver_mobile: data.receiver_mobile,
      region_path: [data.province_id, data.city_id, data.district_id, data.street_id],
      address_detail: data.address_detail,
      tag: data.tag || '',
      is_default: data.is_default ?? 0,
    } : {
      user_id: undefined,
      receiver_name: '',
      receiver_mobile: '',
      region_path: [],
      address_detail: '',
      tag: '',
      is_default: 0,
    };
  },
  { immediate: true },
);
</script>

<template>
  <a-modal :open="visible" :title="title" :confirm-loading="loading" width="720px" @ok="handleSubmit" @cancel="handleClose">
    <a-form ref="formRef" :model="formData" :rules="rules" :label-col="{ style: { width: '100px' } }" class="pt-4">
      <a-form-item label="所属用户" name="user_id">
        <a-select
          v-model:value="formData.user_id"
          show-search
          allow-clear
          placeholder="搜索昵称/手机号"
          :filter-option="false"
          @search="loadUsers"
        >
          <a-select-option v-for="item in userOptions" :key="item.id" :value="item.id">
            {{ item.nickname || `用户#${item.id}` }} / {{ item.mobile || '-' }}
          </a-select-option>
        </a-select>
      </a-form-item>
      <a-form-item label="收货人" name="receiver_name">
        <a-input v-model:value="formData.receiver_name" placeholder="请输入收货人" allow-clear />
      </a-form-item>
      <a-form-item label="联系电话" name="receiver_mobile">
        <a-input v-model:value="formData.receiver_mobile" placeholder="请输入联系电话" allow-clear />
      </a-form-item>
      <a-form-item label="地区" name="region_path">
        <RegionPicker v-model:value="formData.region_path" />
      </a-form-item>
      <a-form-item label="详细地址" name="address_detail">
        <a-textarea v-model:value="formData.address_detail" :rows="3" placeholder="请输入详细地址" />
      </a-form-item>
      <a-form-item label="标签">
        <a-input v-model:value="formData.tag" placeholder="如：家、公司" allow-clear />
      </a-form-item>
      <a-form-item label="默认地址">
        <a-switch v-model:checked="formData.is_default" :checked-value="1" :un-checked-value="0" />
      </a-form-item>
    </a-form>
  </a-modal>
</template>
