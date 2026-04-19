<script lang="ts" setup>
import type { VbenFormSchema } from '@vben/common-ui';
import type { Recordable } from '@vben/types';

import { computed, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import { useVbenForm, VbenButton, z } from '@vben/common-ui';
import { preferences } from '@vben/preferences';

import { message } from 'ant-design-vue';

import { changePasswordApi } from '#/api/auth/admin';
import { useAuthStore } from '#/store';

defineOptions({ name: 'ChangePassword' });

const loading = ref(false);
const router = useRouter();
const authStore = useAuthStore();

const formSchema = computed((): VbenFormSchema[] => [
  {
    component: 'VbenInputPassword',
    componentProps: {
      placeholder: '请输入当前密码（默认 admin123）',
    },
    fieldName: 'old_password',
    label: '当前密码',
    rules: z.string().min(1, { message: '请输入当前密码' }),
  },
  {
    component: 'VbenInputPassword',
    componentProps: {
      passwordStrength: true,
      placeholder: '请输入新密码（至少 6 位）',
    },
    fieldName: 'password',
    label: '新密码',
    rules: z
      .string()
      .min(6, { message: '新密码至少 6 位' })
      .refine((v) => v !== 'admin123', { message: '新密码不能与默认密码相同' }),
  },
  {
    component: 'VbenInputPassword',
    componentProps: {
      placeholder: '请再次输入新密码',
    },
    fieldName: 'password_confirm',
    label: '确认新密码',
    dependencies: {
      rules(values) {
        const password = values.password as string;
        return z
          .string({ required_error: '请再次输入新密码' })
          .min(1, { message: '请再次输入新密码' })
          .refine((value) => value === password, {
            message: '两次输入的密码不一致',
          });
      },
      triggerFields: ['password'],
    },
  },
]);

const [Form, formApi] = useVbenForm(
  reactive({
    commonConfig: {
      hideLabel: true,
      hideRequiredMark: true,
    },
    schema: computed(() => formSchema.value),
    showDefaultActions: false,
  }),
);

async function handleSubmit() {
  const { valid } = await formApi.validate();
  if (!valid) return;
  const values = (await formApi.getValues()) as Recordable<string>;
  const oldPassword = values.old_password ?? '';
  const newPassword = values.password ?? '';
  const confirmPassword = values.password_confirm ?? '';

  try {
    loading.value = true;
    await changePasswordApi({
      old_password: oldPassword,
      password: newPassword,
      password_confirm: confirmPassword,
    });
    message.success('密码修改成功，请使用新密码登录');
    await authStore.logout(false);
  } finally {
    loading.value = false;
  }
}

async function handleCancel() {
  await authStore.logout(false);
}

if (!preferences.app.defaultHomePath) {
  router.replace('/auth/login');
}
</script>

<template>
  <div>
    <h2 class="mb-3 text-2xl font-bold leading-tight tracking-tight lg:text-3xl">
      首次登录，请修改密码 🔐
    </h2>
    <p class="text-muted-foreground mb-6 text-sm">
      为了账号安全，请先将默认密码 admin123 修改为自定义密码；修改成功后需重新登录。
    </p>

    <Form />

    <div class="mt-2">
      <VbenButton
        :class="{ 'cursor-wait': loading }"
        :disabled="loading"
        aria-label="submit"
        class="mt-2 w-full"
        @click="handleSubmit"
      >
        {{ loading ? '提交中...' : '修改密码' }}
      </VbenButton>
      <VbenButton
        class="mt-4 w-full"
        variant="outline"
        @click="handleCancel"
      >
        返回登录
      </VbenButton>
    </div>
  </div>
</template>
