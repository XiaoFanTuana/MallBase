<script setup lang="ts">
import type { RegionApi } from '#/api/region';

import { computed, ref, watch } from 'vue';

import { getRegionChildrenApi, getRegionPathApi } from '#/api/region';

interface CascaderOption {
  value: number;
  label: string;
  level: number;
  isLeaf?: boolean;
  children?: CascaderOption[];
}

const props = withDefaults(defineProps<{
  value?: Array<number | number[]> | number[];
  multiple?: boolean;
  placeholder?: string;
}>(), {
  value: () => [],
  multiple: false,
  placeholder: '请选择地区',
});

const emit = defineEmits<{
  (e: 'update:value', value: Array<number | number[]> | number[]): void;
}>();

const options = ref<CascaderOption[]>([]);
const innerValue = ref<Array<number | number[]> | number[]>(props.multiple ? [] : []);

watch(
  () => props.value,
  async (value) => {
    innerValue.value = value ?? (props.multiple ? [] : []);
    await ensureValueOptions();
  },
  { immediate: true, deep: true },
);

const cascaderValue = computed({
  get: () => innerValue.value,
  set: (value) => {
    innerValue.value = value;
    emit('update:value', value);
  },
});

async function loadRootOptions() {
  if (options.value.length > 0) return;
  const list = await getRegionChildrenApi(0);
  options.value = list.map(mapOption);
}

function mapOption(item: RegionApi.RegionItem): CascaderOption {
  return {
    value: item.id,
    label: item.name,
    level: item.level,
    isLeaf: item.level >= 4,
  };
}

async function ensureValueOptions() {
  await loadRootOptions();

  const values = props.multiple
    ? ((innerValue.value as Array<number[] | number>) || [])
      .map((item) => Array.isArray(item) ? item[item.length - 1] : item)
      .filter(Boolean)
    : [((innerValue.value as number[]) || [])[3]].filter(Boolean);

  for (const leafId of values) {
    await mergePath(Number(leafId));
  }
}

async function mergePath(leafId: number) {
  if (!leafId) return;
  const path = await getRegionPathApi(leafId);
  if (!Array.isArray(path) || path.length === 0) return;

  if (props.multiple) {
    const current = (innerValue.value as Array<number[] | number>) || [];
    innerValue.value = current.map((item) => {
      if (Array.isArray(item)) {
        return item;
      }
      return item === leafId ? path.map((node) => node.id) : item;
    });
    emit('update:value', innerValue.value);
  }

  let current = options.value;
  for (const node of path) {
    let existing = current.find((item) => item.value === node.id);
    if (!existing) {
      existing = mapOption(node);
      current.push(existing);
    }
    if (!existing.children && !existing.isLeaf) {
      existing.children = [];
    }
    current = existing.children || [];
  }
}

async function handleLoadData(selectedOptions: CascaderOption[]) {
  const targetOption = selectedOptions[selectedOptions.length - 1];
  if (!targetOption || targetOption.isLeaf) {
    return;
  }

  const children = await getRegionChildrenApi(targetOption.value);
  targetOption.children = children.map(mapOption);
}
</script>

<template>
  <a-cascader
    v-model:value="cascaderValue"
    :options="options"
    :load-data="handleLoadData"
    :multiple="multiple"
    :placeholder="placeholder"
    :field-names="{ label: 'label', value: 'value', children: 'children' }"
    style="width: 100%"
  />
</template>
