<script lang="ts" setup>
import type { GoodsApi } from '#/api/goods';
import type { GoodsCategoryApi } from '#/api/goods';
import type { GoodsBrandApi } from '#/api/goods';
import type { GoodsSpecApi } from '#/api/goods';
import type { GoodsTagApi } from '#/api/goods';

import type { FileInfo } from '#/components/upload';

import { computed, onMounted, reactive, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import Upload from '#/components/upload/index.vue';

import {
  batchCreateSpecValuesApi,
  createGoodsApi,
  createGoodsSpecApi,
  createSpecValueApi,
  deleteSpecValueApi,
  getAllGoodsBrandsApi,
  getAllGoodsCategoriesApi,
  getAllGoodsSpecsApi,
  getAllGoodsTagsApi,
  getGoodsInfoApi,
  updateGoodsApi,
} from '#/api/goods';

interface Props {
  visible: boolean;
  editData?: GoodsApi.GoodsItem | null;
}

interface Emits {
  (e: 'update:visible', value: boolean): void;
  (e: 'success'): void;
}

const props = withDefaults(defineProps<Props>(), {
  visible: false,
  editData: null,
});

const emit = defineEmits<Emits>();

const isEdit = computed(() => !!props.editData);

const formData = reactive({
  name: '',
  subtitle: '',
  category_id: undefined as number | undefined,
  brand_id: undefined as number | undefined,
  unit: '件',
  price: 0,
  market_price: 0,
  stock: 0,
  main_image: undefined as FileInfo | string | undefined,
  images: [] as FileInfo[],
  description: '',
  sort: 0,
  status: 1,
  is_on_sale: 0,
  is_recommend: 0,
  is_new: 0,
  is_hot: 0,
  tag_ids: [] as number[],
});

const rules = {
  name: [{ required: true, message: '请输入商品名称', trigger: 'blur' }],
  category_id: [{ required: true, message: '请选择分类', trigger: 'change' }],
};

const formRef = ref();
const loading = ref(false);
const activeTab = ref('basic');

/* ---------------- 分类树数据 ---------------- */
const categoryTreeData = ref<any[]>([]);

const buildTree = (list: GoodsCategoryApi.CategoryItem[], pid: number = 0): any[] => {
  return list
    .filter((item) => item.pid === pid)
    .map((item) => ({
      title: item.name,
      value: item.id,
      key: item.id,
      children: buildTree(list, item.id),
    }));
};

/* ---------------- 品牌选项 ---------------- */
const brandOptions = ref<GoodsBrandApi.BrandItem[]>([]);

/* ---------------- 规格模式 ---------------- */
const specType = ref<'single' | 'multi'>('single');

/* ---------------- 规格选项 ---------------- */
const specOptions = ref<GoodsSpecApi.SpecItem[]>([]);
/** 已选中的规格 ID 列表（有序） */
const selectedSpecIds = ref<number[]>([]);
/** 每个规格下已选中的规格值 ID */
const selectedSpecValues = reactive<Record<number, number[]>>({});
/** 每个规格新增值的输入框文本 */
const newSpecValueInputs = reactive<Record<number, string>>({});

/* ---------------- 标签选项 ---------------- */
const tagOptions = ref<GoodsTagApi.TagItem[]>([]);

/* ---------------- 新增规格相关 ---------------- */
const newSpecModalVisible = ref(false);
const newSpecForm = reactive({
  name: '',
  values: '',
});
const newSpecCreating = ref(false);

/** 打开新增规格弹窗 */
const openNewSpecModal = () => {
  newSpecForm.name = '';
  newSpecForm.values = '';
  newSpecModalVisible.value = true;
};

/** 提交新增规格 */
const handleCreateSpec = async () => {
  if (!newSpecForm.name.trim()) {
    message.warning('请输入规格名称');
    return;
  }
  const values = newSpecForm.values
    .split(/[,，、\n]/)
    .map((v) => v.trim())
    .filter(Boolean);
  if (values.length === 0) {
    message.warning('请输入至少一个规格值');
    return;
  }

  try {
    newSpecCreating.value = true;
    const res = await createGoodsSpecApi({ name: newSpecForm.name.trim() });
    const specId = res.id;
    await batchCreateSpecValuesApi(specId, values);
    message.success('规格创建成功');
    newSpecModalVisible.value = false;

    const specs = await getAllGoodsSpecsApi();
    specOptions.value = specs.map((s) => ({
      ...s,
      spec_values: s.spec_values || s.specValues || [],
    }));
    if (!selectedSpecIds.value.includes(specId)) {
      selectedSpecIds.value = [...selectedSpecIds.value, specId];
      const newSpec = specOptions.value.find((s) => s.id === specId);
      selectedSpecValues[specId] = (newSpec?.spec_values || []).map((v) => v.id);
      generateSkuCombinations();
    }
  } catch (error: any) {
    message.error(error.message || '创建规格失败');
  } finally {
    newSpecCreating.value = false;
  }
};

/* ---------------- 规格选择操作 ---------------- */

/** 添加一个规格到已选列表 */
const addSpec = (specId: number) => {
  if (selectedSpecIds.value.includes(specId)) return;
  if (selectedSpecIds.value.length >= 4) {
    message.warning('最多选择4个规格');
    return;
  }
  selectedSpecIds.value = [...selectedSpecIds.value, specId];
  const spec = specOptions.value.find((s) => s.id === specId);
  selectedSpecValues[specId] = (spec?.spec_values || []).map((v) => v.id);
  generateSkuCombinations();
};

/** 移除一个规格 */
const removeSpec = (specId: number) => {
  selectedSpecIds.value = selectedSpecIds.value.filter((id) => id !== specId);
  delete selectedSpecValues[specId];
  delete newSpecValueInputs[specId];
  generateSkuCombinations();
};

/** 切换某个规格值的选中状态 */
const toggleSpecValue = (specId: number, valueId: number) => {
  const current = selectedSpecValues[specId] || [];
  const idx = current.indexOf(valueId);
  if (idx >= 0) {
    selectedSpecValues[specId] = current.filter((id) => id !== valueId);
  } else {
    selectedSpecValues[specId] = [...current, valueId];
  }
  generateSkuCombinations();
};

/** 内联添加规格值 */
const handleAddSpecValue = async (specId: number) => {
  const input = (newSpecValueInputs[specId] || '').trim();
  if (!input) {
    message.warning('请输入规格值');
    return;
  }

  try {
    await createSpecValueApi(specId, input);
    const specs = await getAllGoodsSpecsApi();
    specOptions.value = specs.map((s) => ({
      ...s,
      spec_values: s.spec_values || s.specValues || [],
    }));
    newSpecValueInputs[specId] = '';

    if (!selectedSpecValues[specId]) {
      selectedSpecValues[specId] = [];
    }
    const spec = specOptions.value.find((s) => s.id === specId);
    const newValue = spec?.spec_values?.find((v) => v.value === input);
    if (newValue && !selectedSpecValues[specId].includes(newValue.id)) {
      selectedSpecValues[specId] = [...selectedSpecValues[specId], newValue.id];
    }
    generateSkuCombinations();
  } catch (error: any) {
    message.error(error.message || '添加规格值失败');
  }
};

/** 删除规格值 */
const handleDeleteSpecValue = async (specId: number, valueId: number) => {
  try {
    await deleteSpecValueApi(valueId);
    const specs = await getAllGoodsSpecsApi();
    specOptions.value = specs.map((s) => ({
      ...s,
      spec_values: s.spec_values || s.specValues || [],
    }));

    if (selectedSpecValues[specId]) {
      selectedSpecValues[specId] = selectedSpecValues[specId].filter((id) => id !== valueId);
    }
    generateSkuCombinations();
  } catch (error: any) {
    message.error(error.message || '删除规格值失败');
  }
};

/* ---------------- SKU 组合 ---------------- */
interface SkuRow {
  spec_values: string;
  /** 每个规格列的值，key 为规格名 */
  detail: Record<string, string>;
  price: number;
  market_price: number;
  stock: number;
  sku_code: string;
  image: FileInfo | string | undefined;
}

const skuRows = ref<SkuRow[]>([]);

/** 批量设置行 */
const batchRow = reactive({
  price: undefined as number | undefined,
  market_price: undefined as number | undefined,
  stock: undefined as number | undefined,
  sku_code: '',
});

/** 批量应用 */
const applyBatch = () => {
  const fields: (keyof typeof batchRow)[] = ['price', 'market_price', 'stock'];
  for (const row of skuRows.value) {
    for (const field of fields) {
      if (batchRow[field] !== undefined && batchRow[field] !== '') {
        (row as any)[field] = batchRow[field];
      }
    }
  }
  message.success('批量设置成功');
};

/** 清空批量设置 */
const clearBatch = () => {
  batchRow.price = undefined;
  batchRow.market_price = undefined;
  batchRow.stock = undefined;
  batchRow.sku_code = '';
};

/** 动态表头：根据已选规格生成 */
const skuColumns = computed(() => {
  const selectedSpecs = specOptions.value.filter((s) =>
    selectedSpecIds.value.includes(s.id),
  );
  const specColumns = selectedSpecs.map((spec) => ({
    title: spec.name,
    dataIndex: `spec_${spec.id}`,
    width: 120,
  }));
  return [
    ...specColumns,
    { title: '价格', dataIndex: 'price', width: 120 },
    { title: '市场价', dataIndex: 'market_price', width: 120 },
    { title: '库存', dataIndex: 'stock', width: 100 },
    { title: 'SKU编码', dataIndex: 'sku_code', width: 140 },
  ];
});

const generateSkuCombinations = () => {
  if (selectedSpecIds.value.length === 0) {
    skuRows.value = [];
    return;
  }

  const selectedSpecs = specOptions.value.filter((s) =>
    selectedSpecIds.value.includes(s.id),
  );

  const specValueGroups = selectedSpecs
    .map((spec) => {
      const selectedValueIds = selectedSpecValues[spec.id] || [];
      const allValues = spec.spec_values || [];
      const filteredValues = allValues.filter((v) => selectedValueIds.includes(v.id));
      return { spec, values: filteredValues };
    })
    .filter((group) => group.values.length > 0);

  if (specValueGroups.length === 0) {
    skuRows.value = [];
    return;
  }

  const cartesian = (...arrays: any[][]): any[][] => {
    if (arrays.length === 0) return [[]];
    const [first, ...rest] = arrays;
    const restProduct = cartesian(...rest);
    return first.flatMap((item) =>
      restProduct.map((product) => [item, ...product]),
    );
  };

  const valueArrays = specValueGroups.map((g) =>
    g.values.map((v) => ({ specName: g.spec.name, specId: g.spec.id, value: v.value })),
  );
  const combinations = cartesian(...valueArrays);

  const existingSkuMap = new Map<string, SkuRow>();
  for (const row of skuRows.value) {
    existingSkuMap.set(row.spec_values, row);
  }

  skuRows.value = combinations.map((combo) => {
    const specValuesStr = combo.map((c: any) => c.value).join(',');
    const existing = existingSkuMap.get(specValuesStr);
    if (existing) return existing;

    const detail: Record<string, string> = {};
    const detailById: Record<string, string> = {};
    for (const c of combo) {
      detail[c.specName] = c.value;
      detailById[`spec_${c.specId}`] = c.value;
    }
    return {
      spec_values: specValuesStr,
      detail,
      ...detailById,
      price: formData.price,
      market_price: formData.market_price,
      stock: formData.stock,
      sku_code: '',
      image: undefined,
    };
  });
};

/** 切换规格模式时清空 */
const handleSpecTypeChange = (val: 'single' | 'multi') => {
  specType.value = val;
  if (val === 'single') {
    selectedSpecIds.value = [];
    Object.keys(selectedSpecValues).forEach((key) => {
      delete selectedSpecValues[Number(key)];
    });
    skuRows.value = [];
    clearBatch();
  }
};

/* ---------------- 加载选项数据 ---------------- */
const loadOptions = async () => {
  try {
    const [categories, brands, specs, tags] = await Promise.all([
      getAllGoodsCategoriesApi(),
      getAllGoodsBrandsApi(),
      getAllGoodsSpecsApi(),
      getAllGoodsTagsApi(),
    ]);

    categoryTreeData.value = buildTree(categories);
    brandOptions.value = brands;
    specOptions.value = specs.map((spec) => ({
      ...spec,
      spec_values: spec.spec_values || spec.specValues || [],
    }));
    tagOptions.value = tags;
  } catch (error) {
    console.error('加载选项数据失败:', error);
  }
};

/* ---------------- 监听 visible 变化 ---------------- */
watch(
  () => props.visible,
  async (val) => {
    if (val) {
      resetForm();
      activeTab.value = 'basic';
      await loadOptions();

      if (props.editData) {
        loadEditData(props.editData.id);
      }
    }
  },
);

const loadEditData = async (id: number) => {
  try {
    loading.value = true;
    const detail = await getGoodsInfoApi(id);
    Object.assign(formData, {
      name: detail.name || '',
      subtitle: detail.subtitle || '',
      category_id: detail.category_id || undefined,
      brand_id: detail.brand_id || undefined,
      unit: detail.unit || '件',
      price: detail.price || 0,
      market_price: detail.market_price || 0,
      stock: detail.stock || 0,
      main_image: detail.main_image || undefined,
      images: (detail.images || []).map((img) => ({
        url: img.url,
        name: img.url.split('/').pop() || '',
      })),
      description: detail.description || '',
      sort: detail.sort || 0,
      status: detail.status ?? 1,
      is_on_sale: detail.is_on_sale ?? 0,
      is_recommend: detail.is_recommend ?? 0,
      is_new: detail.is_new ?? 0,
      is_hot: detail.is_hot ?? 0,
      tag_ids: (detail.tags || []).map((t) => t.id),
    });

    // 回填 SKU 数据并恢复选中的规格
    if (detail.skus && detail.skus.length > 0) {
      specType.value = 'multi';
      activeTab.value = 'spec';

      // 从 SKU 的 spec_values 反推选中的规格
      const specValueSet = new Set<string>();
      for (const sku of detail.skus) {
        const values = (sku.spec_values || '').split(',').map((v) => v.trim()).filter(Boolean);
        values.forEach((v) => specValueSet.add(v));
      }

      const matchedSpecIds: number[] = [];
      for (const spec of specOptions.value) {
        const matchedValueIds: number[] = [];
        for (const sv of (spec.spec_values || [])) {
          if (specValueSet.has(sv.value)) {
            matchedValueIds.push(sv.id);
          }
        }
        if (matchedValueIds.length > 0) {
          matchedSpecIds.push(spec.id);
          selectedSpecValues[spec.id] = matchedValueIds;
        }
      }
      selectedSpecIds.value = matchedSpecIds;

      // 先生成空的 SKU 行（建立结构）
      generateSkuCombinations();

      // 用已有 SKU 数据回填
      const existingSkuMap = new Map<string, any>();
      for (const sku of detail.skus) {
        existingSkuMap.set(sku.spec_values || '', sku);
      }
      for (const row of skuRows.value) {
        const existing = existingSkuMap.get(row.spec_values);
        if (existing) {
          row.price = existing.price;
          row.market_price = existing.market_price || 0;
          row.stock = existing.stock;
          row.sku_code = existing.sku_code || '';
          row.image = existing.image || undefined;
        }
      }
    } else {
      specType.value = 'single';
    }
  } catch (error) {
    console.error('加载商品详情失败:', error);
    message.error('加载商品详情失败');
  } finally {
    loading.value = false;
  }
};

/* ---------------- 重置表单 ---------------- */
const resetForm = () => {
  formRef.value?.resetFields();
  Object.assign(formData, {
    name: '',
    subtitle: '',
    category_id: undefined,
    brand_id: undefined,
    unit: '件',
    price: 0,
    market_price: 0,
    stock: 0,
    main_image: undefined,
    images: [],
    description: '',
    sort: 0,
    status: 1,
    is_on_sale: 0,
    is_recommend: 0,
    is_new: 0,
    is_hot: 0,
    tag_ids: [],
  });
  specType.value = 'single';
  selectedSpecIds.value = [];
  Object.keys(selectedSpecValues).forEach((key) => {
    delete selectedSpecValues[Number(key)];
  });
  Object.keys(newSpecValueInputs).forEach((key) => {
    delete newSpecValueInputs[Number(key)];
  });
  skuRows.value = [];
  clearBatch();
};

/* ---------------- 提交表单 ---------------- */
const handleSubmit = async () => {
  try {
    await formRef.value?.validate();
    loading.value = true;

    const submitData: any = {
      ...formData,
      main_image: typeof formData.main_image === 'object' ? formData.main_image?.url || '' : formData.main_image || '',
      images: formData.images.map((img, index) => ({
        url: typeof img === 'object' ? img.url : img,
        sort: index,
      })),
    };

    // 多规格模式下提交 SKU 数据
    if (specType.value === 'multi' && skuRows.value.length > 0) {
      submitData.skus = skuRows.value.map((sku) => ({
        spec_values: sku.spec_values,
        price: sku.price,
        market_price: sku.market_price,
        stock: sku.stock,
        sku_code: sku.sku_code || '',
        image: typeof sku.image === 'object' ? sku.image?.url || '' : sku.image || '',
      }));
    } else {
      submitData.skus = undefined;
    }

    if (isEdit.value) {
      await updateGoodsApi(props.editData!.id, submitData);
      message.success('更新成功');
    } else {
      await createGoodsApi(submitData);
      message.success('创建成功');
    }

    emit('success');
    emit('update:visible', false);
  } catch (error: any) {
    if (error.errorFields) {
      return;
    } else {
      console.error('提交失败:', error);
      message.error(error.message || '操作失败');
    }
  } finally {
    loading.value = false;
  }
};

/* ---------------- 取消 ---------------- */
const handleCancel = () => {
  emit('update:visible', false);
};

onMounted(() => {
  loadOptions();
});
</script>

<template>
  <a-modal
    :title="isEdit ? '编辑商品' : '新增商品'"
    :open="visible"
    :confirm-loading="loading"
    :width="1000"
    @ok="handleSubmit"
    @cancel="handleCancel"
  >
    <a-form
      ref="formRef"
      :model="formData"
      :rules="rules"
      :label-col="{ style: { width: '100px' } }"
      class="pt-4"
    >
      <a-tabs v-model:activeKey="activeTab">
        <!-- ==================== 基本信息 ==================== -->
        <a-tab-pane key="basic" tab="基本信息">
          <a-row :gutter="16">
            <a-col :span="12">
              <a-form-item label="商品名称" name="name">
                <a-input
                  v-model:value="formData.name"
                  placeholder="请输入商品名称"
                  allow-clear
                />
              </a-form-item>
            </a-col>
            <a-col :span="12">
              <a-form-item label="副标题" name="subtitle">
                <a-input
                  v-model:value="formData.subtitle"
                  placeholder="请输入副标题"
                  allow-clear
                />
              </a-form-item>
            </a-col>
          </a-row>

          <a-row :gutter="16">
            <a-col :span="12">
              <a-form-item label="分类" name="category_id">
                <a-tree-select
                  v-model:value="formData.category_id"
                  :tree-data="categoryTreeData"
                  placeholder="请选择分类"
                  tree-default-expand-all
                  allow-clear
                />
              </a-form-item>
            </a-col>
            <a-col :span="12">
              <a-form-item label="品牌" name="brand_id">
                <a-select
                  v-model:value="formData.brand_id"
                  placeholder="请选择品牌"
                  allow-clear
                >
                  <a-select-option
                    v-for="brand in brandOptions"
                    :key="brand.id"
                    :value="brand.id"
                  >
                    {{ brand.name }}
                  </a-select-option>
                </a-select>
              </a-form-item>
            </a-col>
          </a-row>

          <a-row :gutter="16">
            <a-col :span="8">
              <a-form-item label="单位" name="unit">
                <a-input
                  v-model:value="formData.unit"
                  placeholder="如：件、kg"
                  allow-clear
                />
              </a-form-item>
            </a-col>
            <a-col :span="8">
              <a-form-item label="排序" name="sort">
                <a-input-number
                  v-model:value="formData.sort"
                  :min="0"
                  :max="9999"
                  placeholder="数字越小越靠前"
                  class="w-full"
                />
              </a-form-item>
            </a-col>
            <a-col :span="8">
              <a-form-item label="状态" name="status">
                <a-radio-group v-model:value="formData.status">
                  <a-radio :value="1">启用</a-radio>
                  <a-radio :value="0">禁用</a-radio>
                </a-radio-group>
              </a-form-item>
            </a-col>
          </a-row>

          <a-row :gutter="16">
            <a-col :span="6">
              <a-form-item label="上架" name="is_on_sale">
                <a-switch
                  v-model:checked="formData.is_on_sale"
                  :checked-value="1"
                  :un-checked-value="0"
                />
              </a-form-item>
            </a-col>
            <a-col :span="6">
              <a-form-item label="推荐" name="is_recommend">
                <a-switch
                  v-model:checked="formData.is_recommend"
                  :checked-value="1"
                  :un-checked-value="0"
                />
              </a-form-item>
            </a-col>
            <a-col :span="6">
              <a-form-item label="新品" name="is_new">
                <a-switch
                  v-model:checked="formData.is_new"
                  :checked-value="1"
                  :un-checked-value="0"
                />
              </a-form-item>
            </a-col>
            <a-col :span="6">
              <a-form-item label="热卖" name="is_hot">
                <a-switch
                  v-model:checked="formData.is_hot"
                  :checked-value="1"
                  :un-checked-value="0"
                />
              </a-form-item>
            </a-col>
          </a-row>

          <a-form-item label="商品描述" name="description" :label-col="{ style: { width: '100px' } }">
            <a-textarea
              v-model:value="formData.description"
              placeholder="请输入商品详情描述"
              :rows="4"
              allow-clear
            />
          </a-form-item>

          <a-row :gutter="16">
            <a-col :span="12">
              <a-form-item label="主图" name="main_image">
                <Upload
                  v-model:value="formData.main_image"
                  type="image"
                  module="goods"
                />
              </a-form-item>
            </a-col>
            <a-col :span="12">
              <a-form-item label="商品图片">
                <Upload
                  v-model:value="formData.images"
                  type="images"
                  module="goods"
                  :max-count="10"
                />
              </a-form-item>
            </a-col>
          </a-row>
        </a-tab-pane>

        <!-- ==================== 规格与价格 ==================== -->
        <a-tab-pane key="spec" tab="规格与价格">
          <!-- 规格模式切换 -->
          <a-form-item label="规格类型">
            <a-radio-group
              :value="specType"
              @change="(e: any) => handleSpecTypeChange(e.target.value)"
            >
              <a-radio-button value="single">单规格</a-radio-button>
              <a-radio-button value="multi">多规格</a-radio-button>
            </a-radio-group>
          </a-form-item>

          <a-divider style="margin: 8px 0 16px;" />

          <!-- ===== 单规格模式 ===== -->
          <template v-if="specType === 'single'">
            <a-row :gutter="16">
              <a-col :span="8">
                <a-form-item label="价格" name="price">
                  <a-input-number
                    v-model:value="formData.price"
                    :min="0"
                    :precision="2"
                    placeholder="请输入价格"
                    class="w-full"
                  />
                </a-form-item>
              </a-col>
              <a-col :span="8">
                <a-form-item label="市场价" name="market_price">
                  <a-input-number
                    v-model:value="formData.market_price"
                    :min="0"
                    :precision="2"
                    placeholder="请输入市场价"
                    class="w-full"
                  />
                </a-form-item>
              </a-col>
              <a-col :span="8">
                <a-form-item label="库存" name="stock">
                  <a-input-number
                    v-model:value="formData.stock"
                    :min="0"
                    placeholder="请输入库存"
                    class="w-full"
                  />
                </a-form-item>
              </a-col>
            </a-row>
          </template>

          <!-- ===== 多规格模式 ===== -->
          <template v-else>
            <!-- 规格选择下拉 -->
            <a-form-item label="商品规格">
              <div class="flex items-center gap-2 flex-wrap">
                <a-select
                  placeholder="请选择规格"
                  style="width: 180px;"
                  :value="undefined"
                  allow-clear
                  @change="(val: number) => val && addSpec(val)"
                >
                  <a-select-option
                    v-for="spec in specOptions.filter((s) => !selectedSpecIds.includes(s.id))"
                    :key="spec.id"
                    :value="spec.id"
                  >
                    {{ spec.name }}
                  </a-select-option>
                </a-select>
                <a-button type="dashed" size="small" @click="openNewSpecModal">
                  + 新增规格
                </a-button>
                <span class="text-gray-400 text-xs">（最多4个规格）</span>
              </div>
            </a-form-item>

            <!-- 已选规格卡片 -->
            <div
              v-for="specId in selectedSpecIds"
              :key="specId"
              class="spec-card mb-4"
            >
              <div
                v-for="spec in [specOptions.find((s) => s.id === specId)]"
                :key="`card-${specId}`"
              >
                <div class="spec-card-header">
                  <span class="spec-card-title">{{ spec?.name }}</span>
                  <a-button
                    type="text"
                    size="small"
                    danger
                    @click="removeSpec(specId)"
                  >
                    删除规格
                  </a-button>
                </div>
                <div class="spec-card-body">
                  <div class="flex flex-wrap gap-2 mb-2">
                    <a-tag
                      v-for="sv in (spec?.spec_values || [])"
                      :key="sv.id"
                      :color="(selectedSpecValues[specId] || []).includes(sv.id) ? 'blue' : ''"
                      :class="{ 'spec-tag-unchecked': !(selectedSpecValues[specId] || []).includes(sv.id) }"
                      class="spec-tag"
                      @click="toggleSpecValue(specId, sv.id)"
                    >
                      {{ sv.value }}
                      <span
                        class="spec-tag-close"
                        @click.stop="handleDeleteSpecValue(specId, sv.id)"
                      >
                        &times;
                      </span>
                    </a-tag>
                  </div>
                  <div class="flex gap-2 items-center">
                    <a-input
                      v-model:value="newSpecValueInputs[specId]"
                      placeholder="输入新规格值，回车添加"
                      size="small"
                      style="width: 200px;"
                      allow-clear
                      @press-enter="handleAddSpecValue(specId)"
                    />
                    <a-button
                      size="small"
                      type="primary"
                      ghost
                      @click="handleAddSpecValue(specId)"
                    >
                      添加
                    </a-button>
                  </div>
                </div>
              </div>
            </div>

            <div
              v-if="selectedSpecIds.length === 0"
              class="text-gray-400 py-8 text-center"
            >
              请从上方下拉框选择规格
            </div>
            <div
              v-else-if="Object.values(selectedSpecValues).every((v) => v.length === 0)"
              class="text-gray-400 py-4 text-center"
            >
              请点击规格值标签选择参与 SKU 组合的规格值
            </div>

            <!-- 商品属性（SKU 组合表格） -->
            <div v-if="skuRows.length > 0">
              <a-divider orientation="left" style="font-size: 14px;">
                商品属性（{{ skuRows.length }} 个SKU）
              </a-divider>

              <!-- 批量设置行 -->
              <div class="batch-row mb-3">
                <span class="batch-label">批量设置：</span>
                <a-input-number
                  v-model:value="batchRow.price"
                  placeholder="价格"
                  :min="0"
                  :precision="2"
                  size="small"
                  style="width: 100px;"
                />
                <a-input-number
                  v-model:value="batchRow.market_price"
                  placeholder="市场价"
                  :min="0"
                  :precision="2"
                  size="small"
                  style="width: 100px;"
                />
                <a-input-number
                  v-model:value="batchRow.stock"
                  placeholder="库存"
                  :min="0"
                  size="small"
                  style="width: 100px;"
                />
                <a-button type="primary" size="small" @click="applyBatch">应用</a-button>
                <a-button size="small" @click="clearBatch">清空</a-button>
              </div>

              <!-- SKU 表格（动态列） -->
              <a-table
                :columns="skuColumns"
                :data-source="skuRows"
                :pagination="false"
                :scroll="{ x: 800 }"
                size="small"
                row-key="spec_values"
                bordered
              >
                <template #bodyCell="{ column, record }">
                  <!-- 规格列：只显示文本 -->
                  <template v-if="column.dataIndex && column.dataIndex.startsWith('spec_')">
                    {{ record[column.dataIndex] }}
                  </template>
                  <template v-else-if="column.dataIndex === 'price'">
                    <a-input-number
                      v-model:value="record.price"
                      :min="0"
                      :precision="2"
                      size="small"
                      style="width: 100%"
                    />
                  </template>
                  <template v-else-if="column.dataIndex === 'market_price'">
                    <a-input-number
                      v-model:value="record.market_price"
                      :min="0"
                      :precision="2"
                      size="small"
                      style="width: 100%"
                    />
                  </template>
                  <template v-else-if="column.dataIndex === 'stock'">
                    <a-input-number
                      v-model:value="record.stock"
                      :min="0"
                      size="small"
                      style="width: 100%"
                    />
                  </template>
                  <template v-else-if="column.dataIndex === 'sku_code'">
                    <a-input
                      v-model:value="record.sku_code"
                      size="small"
                      placeholder="SKU编码"
                      allow-clear
                    />
                  </template>
                </template>
              </a-table>
            </div>
          </template>
        </a-tab-pane>

        <!-- ==================== 其它 ==================== -->
        <a-tab-pane key="other" tab="其它">
          <a-form-item label="商品标签" name="tag_ids">
            <a-select
              v-model:value="formData.tag_ids"
              mode="multiple"
              placeholder="请选择标签"
              allow-clear
            >
              <a-select-option
                v-for="tag in tagOptions"
                :key="tag.id"
                :value="tag.id"
              >
                <a-tag v-if="tag.color" :color="tag.color">{{ tag.name }}</a-tag>
                <span v-else>{{ tag.name }}</span>
              </a-select-option>
            </a-select>
          </a-form-item>
        </a-tab-pane>
      </a-tabs>
    </a-form>

    <!-- ==================== 新增规格弹窗 ==================== -->
    <a-modal
      v-model:open="newSpecModalVisible"
      title="新增规格"
      :confirm-loading="newSpecCreating"
      :width="460"
      @ok="handleCreateSpec"
    >
      <a-form :label-col="{ style: { width: '80px' } }" class="pt-4">
        <a-form-item label="规格名称" required>
          <a-input
            v-model:value="newSpecForm.name"
            placeholder="如：颜色、尺码、版本"
            allow-clear
          />
        </a-form-item>
        <a-form-item label="规格值" required>
          <a-textarea
            v-model:value="newSpecForm.values"
            placeholder="多个规格值用逗号分隔，如：红色,蓝色,黑色"
            :rows="3"
            allow-clear
          />
        </a-form-item>
      </a-form>
    </a-modal>
  </a-modal>
</template>

<style scoped>
.spec-card {
  border: 1px solid #e8e8e8;
  border-radius: 6px;
  overflow: hidden;
}

.spec-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 16px;
  background: #fafafa;
  border-bottom: 1px solid #e8e8e8;
}

.spec-card-title {
  font-weight: 500;
  font-size: 14px;
  color: #333;
}

.spec-card-body {
  padding: 12px 16px;
}

.spec-tag {
  cursor: pointer;
  user-select: none;
  transition: all 0.2s ease;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 13px;
}

.spec-tag:hover {
  opacity: 0.85;
}

.spec-tag-unchecked {
  background: #fff;
  border: 1px dashed #d9d9d9;
  color: #999;
}

.spec-tag-close {
  margin-left: 4px;
  font-size: 12px;
  color: #999;
  cursor: pointer;
  opacity: 0;
  transition: opacity 0.2s ease;
}

.spec-tag:hover .spec-tag-close {
  opacity: 1;
}

.spec-tag-close:hover {
  color: #ff4d4f;
}

.batch-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  background: #f5f7fa;
  border-radius: 4px;
  border: 1px dashed #d9d9d9;
}

.batch-label {
  font-size: 13px;
  color: #666;
  white-space: nowrap;
}
</style>
