<script setup lang="ts">
import type { AuthenticationProps, VbenFormSchema } from '@vben/common-ui';

import { computed, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import { Button, Checkbox } from 'ant-design-vue';
import { $t } from '@vben/locales';
import { useVbenForm } from '@vben/common-ui';

// 定义 props
interface Props extends AuthenticationProps {
  formSchema?: VbenFormSchema[];
}

defineOptions({
  name: 'AppAuthenticationLogin',
});

const props = withDefaults(defineProps<Props>(), {
  codeLoginPath: '/auth/code-login',
  forgetPasswordPath: '/auth/forget-password',
  formSchema: () => [],
  loading: false,
  qrCodeLoginPath: '/auth/qrcode-login',
  registerPath: '/auth/register',
  showCodeLogin: true,
  showForgetPassword: true,
  showQrcodeLogin: true,
  showRegister: true,
  showRememberMe: true,
  showThirdPartyLogin: true,
  submitButtonText: '',
  subTitle: '',
  title: '',
});

const emit = defineEmits<{
  submit: [Record<string, any>];
}>();

// 使用表单
const [Form, formApi] = useVbenForm(
  reactive({
    commonConfig: {
      hideLabel: true,
      hideRequiredMark: true,
    },
    schema: computed(() => props.formSchema),
    showDefaultActions: false,
  }),
);

const router = useRouter();

const REMEMBER_ME_KEY = `REMEMBER_ME_USERNAME_${location.hostname}`;
const localUsername = localStorage.getItem(REMEMBER_ME_KEY) || '';
const rememberMe = ref(!!localUsername);

async function handleSubmit() {
  const { valid } = await formApi.validate();
  const values = await formApi.getValues();
  if (valid) {
    localStorage.setItem(
      REMEMBER_ME_KEY,
      rememberMe.value ? values?.username : '',
    );
    emit('submit', values);
  }
}

function handleGo(path: string) {
  router.push(path);
}

// 暴露表单 API
defineExpose({
  getFormApi: () => formApi,
});

</script>

<template>
  <div @keydown.enter.prevent="handleSubmit">
    <!-- 标题区域 -->
    <div v-if="title" class="mb-6 text-center">
      <h2 class="text-2xl font-bold">
        {{ title || `${$t('authentication.welcomeBack')} 👋🏻` }}
      </h2>
      <p v-if="subTitle" class="mt-2 text-sm text-gray-500">
        {{ subTitle || $t('authentication.loginSubtitle') }}
      </p>
    </div>

    <Form />

    <!-- 记住我和忘记密码 -->
    <div
      v-if="showRememberMe || showForgetPassword"
      class="mb-6 flex justify-between"
    >
      <div class="flex items-center">
        <Checkbox v-if="showRememberMe" v-model:checked="rememberMe">
          {{ $t('authentication.rememberMe') }}
        </Checkbox>
      </div>

      <span
        v-if="showForgetPassword"
        class="cursor-pointer text-sm text-blue-500 hover:text-blue-600"
        @click="handleGo(forgetPasswordPath)"
      >
        {{ $t('authentication.forgetPassword') }}
      </span>
    </div>

    <!-- 提交按钮 -->
    <Button
      :class="{
        'cursor-wait': loading,
      }"
      :loading="loading"
      class="w-full"
      type="primary"
      @click="handleSubmit"
    >
      {{ submitButtonText || $t('common.login') }}
    </Button>

    <!-- 其他登录方式 -->
    <div
      v-if="showCodeLogin || showQrcodeLogin"
      class="mb-2 mt-4 flex items-center justify-between gap-2"
    >
      <Button
        v-if="showCodeLogin"
        class="w-1/2"
        @click="handleGo(codeLoginPath)"
      >
        {{ $t('authentication.mobileLogin') }}
      </Button>
      <Button
        v-if="showQrcodeLogin"
        class="w-1/2"
        @click="handleGo(qrCodeLoginPath)"
      >
        {{ $t('authentication.qrcodeLogin') }}
      </Button>
    </div>

    <!-- 注册提示 -->
    <div v-if="showRegister" class="mt-3 text-center text-sm">
      {{ $t('authentication.accountTip') }}
      <span
        class="cursor-pointer text-sm text-blue-500 hover:text-blue-600"
        @click="handleGo(registerPath)"
      >
        {{ $t('authentication.createAccount') }}
      </span>
    </div>
  </div>
</template>
