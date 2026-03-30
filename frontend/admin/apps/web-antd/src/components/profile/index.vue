<script setup lang="ts">
import type { BasicUserInfo } from '#/modules/user';

import { computed } from 'vue';

import { preferences } from '@vben/preferences';

import { Avatar, Card, Menu, MenuItem } from 'ant-design-vue';

defineOptions({
  name: 'ProfileUI',
});

withDefaults(defineProps<Props>(), {
  title: '个人中心',
  tabs: () => [],
});

interface Props {
  title?: string;
  userInfo: BasicUserInfo | null;
  tabs: {
    label: string;
    value: string;
  }[];
}

const tabsValue = defineModel<string>('modelValue');

// Menu 的 selectedKeys 需要数组
const selectedKeys = computed({
  get: () => (tabsValue.value ? [tabsValue.value] : []),
  set: (val: string[]) => {
    if (val.length > 0) {
      tabsValue.value = val[0];
    }
  },
});
</script>

<template>
  <div class="flex h-full w-full gap-4 p-4">
    <!-- 左侧面板 -->
    <Card class="w-60 flex-none">
      <div class="flex flex-col items-center gap-3 py-4">
        <Avatar
          :size="80"
          :src="userInfo?.avatar || preferences.app.defaultAvatar"
        />
        <span class="text-lg font-semibold">
          {{ userInfo?.nickname || '' }}
        </span>
        <span class="text-sm text-foreground/80">
          {{ userInfo?.username || '' }}
        </span>
      </div>
      <div class="my-4 border-t"></div>
      <Menu v-model:selected-keys="selectedKeys" mode="inline">
        <MenuItem v-for="tab in tabs" :key="tab.value">
          {{ tab.label }}
        </MenuItem>
      </Menu>
    </Card>

    <!-- 右侧内容区 -->
    <Card class="flex-auto p-6">
      <slot name="content"></slot>
    </Card>
  </div>
</template>
