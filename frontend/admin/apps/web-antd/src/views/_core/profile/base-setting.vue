<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';

import { message } from 'ant-design-vue';

import {
  getCurrentAdminInfoApi,
  updateCurrentAdminInfoApi,
} from '#/api/core/auth';
import { uploadImageApi } from '#/api/core/upload';
import Upload from '#/components/upload/index.vue';
import { useUserStore } from '#/modules/user';

const userStore = useUserStore();
const saving = ref(false);
const formRef = ref();

// 表单验证规则
const rules = {
  email: [{ type: 'email' as const, message: '请输入有效的邮箱地址' }],
  mobile: [
    {
      pattern: /^1[3-9]\d{9}$/,
      message: '请输入有效的手机号',
    },
  ],
};

// 头像相关
const avatarUrl = ref('');
const avatarPath = ref('');

// 表单数据
const formData = reactive({
  username: '',
  nickname: '',
  email: '',
  mobile: '',
  roleNames: '',
  remark: '',
  last_login_time: '',
  last_login_ip: '',
});

// 头像上传
const handleAvatarUpload = async (file: File) => {
  const res = await uploadImageApi(file);
  avatarPath.value = res.url;
  avatarUrl.value = res.full_url;
};

const handleAvatarRemove = async () => {
  avatarPath.value = '';
  avatarUrl.value = '';
};

// 保存个人资料
const handleSave = async () => {
  // 先进行表单验证
  try {
    await formRef.value?.validate();
  } catch {
    return;
  }

  saving.value = true;
  try {
    await updateCurrentAdminInfoApi({
      nickname: formData.nickname,
      avatar: avatarPath.value,
      email: formData.email,
      mobile: formData.mobile,
      remark: formData.remark,
    });
    message.success('个人资料更新成功');

    // 重新获取用户信息并更新 userStore
    const adminInfo = await getCurrentAdminInfoApi();
    if (adminInfo) {
      userStore.setUserInfo({
        avatar: adminInfo.avatar_full_url || '',
        id: String(adminInfo.id),
        nickname: adminInfo.nickname || adminInfo.username,
        roles: adminInfo.roles?.map((role) => role.code) || [],
        userId: String(adminInfo.id),
        username: adminInfo.username,
      });
    }
  } catch {
    message.error('更新失败，请重试');
  } finally {
    saving.value = false;
  }
};

onMounted(async () => {
  const data = await getCurrentAdminInfoApi();
  avatarPath.value = data.avatar || '';
  avatarUrl.value = data.avatar_full_url || '';

  formData.username = data.username || '';
  formData.nickname = data.nickname || '';
  formData.email = data.email || '';
  formData.mobile = data.mobile || '';
  formData.roleNames =
    data.roles?.map((role: any) => role.name).join('、') || '';
  formData.remark = data.remark || '';
  formData.last_login_time = data.last_login_time || '';
  formData.last_login_ip = data.last_login_ip || '';
});
</script>

<template>
  <div class="p-4">
    <!-- 表单区域 -->
    <a-form
      ref="formRef"
      :model="formData"
      :rules="rules"
      :label-col="{ span: 4 }"
      :wrapper-col="{ span: 16 }"
    >
      <a-form-item label="用户名">
        <a-input v-model:value="formData.username" disabled />
      </a-form-item>

      <a-form-item label="昵称">
        <a-input v-model:value="formData.nickname" placeholder="请输入昵称" />
      </a-form-item>

      <a-form-item label="头像">
        <Upload
          :value="avatarUrl"
          type="image"
          :custom-upload="handleAvatarUpload"
          :custom-remove="handleAvatarRemove"
        />
      </a-form-item>

      <a-form-item label="邮箱" name="email">
        <a-input v-model:value="formData.email" placeholder="请输入邮箱" />
      </a-form-item>

      <a-form-item label="手机号" name="mobile">
        <a-input v-model:value="formData.mobile" placeholder="请输入手机号" />
      </a-form-item>

      <a-form-item label="角色">
        <a-input v-model:value="formData.roleNames" disabled />
      </a-form-item>

      <a-form-item label="备注">
        <a-textarea
          v-model:value="formData.remark"
          :rows="3"
          placeholder="请输入备注"
        />
      </a-form-item>

      <a-form-item label="最后登录时间">
        <a-input v-model:value="formData.last_login_time" disabled />
      </a-form-item>

      <a-form-item label="最后登录IP">
        <a-input v-model:value="formData.last_login_ip" disabled />
      </a-form-item>

      <a-form-item :wrapper-col="{ offset: 4, span: 16 }">
        <a-button type="primary" :loading="saving" @click="handleSave">
          保存修改
        </a-button>
      </a-form-item>
    </a-form>
  </div>
</template>
