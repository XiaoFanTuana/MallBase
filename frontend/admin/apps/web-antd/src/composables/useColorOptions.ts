import { computed } from 'vue';

import { getColorOptionsApi } from '#/api/core';

interface ColorOption {
  value: string;
  label: string;
  color: string;
}

let colorOptionsCache: ColorOption[] | null = null;

/**
 * 获取颜色选项
 */
export async function useColorOptions() {
  if (colorOptionsCache) {
    return colorOptionsCache;
  }

  try {
    const result = await getColorOptionsApi();
    colorOptionsCache = result.options;
    return colorOptionsCache;
  } catch (error) {
    console.error('获取颜色选项失败:', error);
    // 返回默认颜色选项
    const defaultOptions: ColorOption[] = [
      { value: 'gold', label: '金色', color: 'gold' },
      { value: 'blue', label: '蓝色', color: 'blue' },
      { value: 'green', label: '绿色', color: 'green' },
      { value: 'red', label: '红色', color: 'red' },
      { value: 'orange', label: '橙色', color: 'orange' },
      { value: 'purple', label: '紫色', color: 'purple' },
      { value: 'cyan', label: '青色', color: 'cyan' },
      { value: 'volcano', label: '火山红', color: 'volcano' },
      { value: 'magenta', label: '洋红', color: 'magenta' },
      { value: 'lime', label: '青柠', color: 'lime' },
    ];
    colorOptionsCache = defaultOptions;
    return defaultOptions;
  }
}

/**
 * 获取颜色映射（用于表格显示）
 */
export function useColorMap() {
  const options = colorOptionsCache || [
    { value: 'gold', label: '金色', color: 'gold' },
    { value: 'blue', label: '蓝色', color: 'blue' },
    { value: 'green', label: '绿色', color: 'green' },
    { value: 'red', label: '红色', color: 'red' },
    { value: 'orange', label: '橙色', color: 'orange' },
    { value: 'purple', label: '紫色', color: 'purple' },
    { value: 'cyan', label: '青色', color: 'cyan' },
    { value: 'volcano', label: '火山红', color: 'volcano' },
    { value: 'magenta', label: '洋红', color: 'magenta' },
    { value: 'lime', label: '青柠', color: 'lime' },
  ];

  return computed(() => {
    const map: Record<string, { color: string; label: string }> = {};
    options.forEach((option) => {
      map[option.value] = {
        color: option.color,
        label: option.label,
      };
    });
    return map;
  });
}
