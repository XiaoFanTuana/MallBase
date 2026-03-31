<script lang="ts" setup>
import type { SettingApi } from '#/api/setting';

import { onMounted, ref } from 'vue';

import { message, Modal } from 'ant-design-vue';

import {
  deleteSettingGroupApi,
  deleteSettingItemApi,
  getSettingGroupTreeApi,
  getSettingItemListApi,
} from '#/api/setting';

import GroupModal from './group-modal.vue';
import ItemModal from './item-modal.vue';

defineOptions({ name: 'SettingManagement' });

// ==================== 分组树相关 ====================
const groupTree = ref<SettingApi.SettingGroup[]>([]);
const groupTreeLoading = ref(false);
const selectedGroupId = ref<number | undefined>();
const expandedKeys = ref<number[]>([]);

/** 加载分组树 */
const loadGroupTree = async () => {
  groupTreeLoading.value = true;
  try {
    groupTree.value = await getSettingGroupTreeApi();
    // 默认展开所有节点
    expandedKeys.value = getAllKeys(groupTree.value);
  } catch (error) {
    console.error('加载分组树失败:', error);
    message.error('加载分组树失败');
  } finally {
    groupTreeLoading.value = false;
  }
};

/** 获取所有树节点的 key */
const getAllKeys = (data: SettingApi.SettingGroup[]): number[] => {
  const keys: number[] = [];
  const traverse = (items: SettingApi.SettingGroup[]) => {
    for (const item of items) {
      keys.push(item.id);
      if (item.children?.length) {
        traverse(item.children);
      }
    }
  };
  traverse(data);
  return keys;
};

/** 树节点选中 */
const handleGroupSelect = (selectedKeys: number[]) => {
  if (selectedKeys.length > 0) {
    selectedGroupId.value = selectedKeys[0];
    loadItems(selectedGroupId.value);
  } else {
    selectedGroupId.value = undefined;
    settingItems.value = [];
  }
};

// ==================== 分组弹窗 ====================
const groupModalVisible = ref(false);
const editingGroup = ref<null | SettingApi.SettingGroup>(null);

const handleCreateGroup = () => {
  editingGroup.value = null;
  groupModalVisible.value = true;
};

const handleEditGroup = (node: SettingApi.SettingGroup) => {
  editingGroup.value = node;
  groupModalVisible.value = true;
};

const handleDeleteGroup = (node: SettingApi.SettingGroup) => {
  Modal.confirm({
    title: '删除确认',
    content: `确定要删除分组「${node.name}」吗？将同时删除子分组和所有设置项。`,
    async onOk() {
      await deleteSettingGroupApi(node.id);
      message.success('删除成功');
      // 如果删除的是当前选中的分组，清空右侧
      if (selectedGroupId.value === node.id) {
        selectedGroupId.value = undefined;
        settingItems.value = [];
      }
      loadGroupTree();
    },
  });
};

const onGroupModalSuccess = () => {
  loadGroupTree();
};

// ==================== 设置项列表 ====================
const settingItems = ref<SettingApi.SettingItem[]>([]);
const itemsLoading = ref(false);

/** 加载设置项 */
const loadItems = async (groupId: number) => {
  itemsLoading.value = true;
  try {
    settingItems.value = await getSettingItemListApi(groupId);
  } catch (error) {
    console.error('加载设置项失败:', error);
    message.error('加载设置项失败');
  } finally {
    itemsLoading.value = false;
  }
};

// ==================== 设置项弹窗 ====================
const itemModalVisible = ref(false);
const editingItem = ref<null | SettingApi.SettingItem>(null);

const handleCreateItem = () => {
  if (!selectedGroupId.value) {
    message.warning('请先选择一个分组');
    return;
  }
  editingItem.value = null;
  itemModalVisible.value = true;
};

const handleEditItem = (item: SettingApi.SettingItem) => {
  editingItem.value = item;
  itemModalVisible.value = true;
};

const handleDeleteItem = (item: SettingApi.SettingItem) => {
  Modal.confirm({
    title: '删除确认',
    content: `确定要删除设置项「${item.name}」吗？`,
    async onOk() {
      await deleteSettingItemApi(item.id);
      message.success('删除成功');
      if (selectedGroupId.value) {
        loadItems(selectedGroupId.value);
      }
    },
  });
};

const onItemModalSuccess = () => {
  if (selectedGroupId.value) {
    loadItems(selectedGroupId.value);
  }
};

// ==================== 类型标签映射 ====================
const typeLabelMap: Record<string, string> = {
  input: '文本',
  textarea: '多行文本',
  number: '数字',
  password: '密码',
  switch: '开关',
  radio: '单选',
  checkbox: '多选',
  select: '下拉',
  image: '图片',
  images: '多图',
  file: '文件',
  files: '多文件',
  editor: '富文本',
  json: 'JSON',
};

// ==================== 初始化 ====================
onMounted(loadGroupTree);
</script>

<template>
  <div class="flex h-full gap-4 p-4">
    <!-- 左侧：分组树 -->
    <div class="w-80 shrink-0 rounded border p-4">
      <div class="mb-3 flex items-center justify-between">
        <h3 class="m-0 text-base font-semibold">设置分组</h3>
        <a-button type="primary" size="small" @click="handleCreateGroup">
          新增分组
        </a-button>
      </div>

      <a-spin :spinning="groupTreeLoading">
        <a-tree
          v-if="groupTree.length > 0"
          :tree-data="groupTree"
          :field-names="{ title: 'name', key: 'id', children: 'children' }"
          :expanded-keys="expandedKeys"
          :selected-keys="selectedGroupId ? [selectedGroupId] : []"
          show-icon
          block-node
          @expand="(keys: any[]) => (expandedKeys = keys)"
          @select="handleGroupSelect"
        >
          <template #icon="{ data }">
            <span v-if="data.icon" class="mr-1 text-sm">
              <span :class="data.icon"></span>
            </span>
          </template>

          <template #title="{ data }">
            <div class="group flex items-center justify-between">
              <span :class="{ 'text-gray-400': data.status === 0 }">
                {{ data.name }}
              </span>
              <span class="hidden items-center gap-1 group-hover:inline-flex">
                <a-button
                  type="link"
                  size="small"
                  @click.stop="handleEditGroup(data)"
                >
                  编辑
                </a-button>
                <a-button
                  type="link"
                  danger
                  size="small"
                  @click.stop="handleDeleteGroup(data)"
                >
                  删除
                </a-button>
              </span>
            </div>
          </template>
        </a-tree>

        <a-empty v-else description="暂无分组" class="mt-8" />
      </a-spin>
    </div>

    <!-- 右侧：设置项列表 -->
    <div class="min-w-0 flex-1 rounded border p-4">
      <template v-if="selectedGroupId">
        <div class="mb-3 flex items-center justify-between">
          <h3 class="m-0 text-base font-semibold">设置项</h3>
          <a-button type="primary" size="small" @click="handleCreateItem">
            新增设置项
          </a-button>
        </div>

        <a-spin :spinning="itemsLoading">
          <a-table
            v-if="settingItems.length > 0"
            :columns="[
              { title: 'ID', dataIndex: 'id', width: 60 },
              { title: '名称', dataIndex: 'name', width: 120 },
              { title: '编码', dataIndex: 'code', width: 150 },
              { title: '类型', dataIndex: 'type', width: 90 },
              { title: '当前值', dataIndex: 'value', ellipsis: true },
              { title: '必填', dataIndex: 'is_required', width: 60 },
              { title: '排序', dataIndex: 'sort', width: 70 },
              { title: '操作', key: 'action', width: 140 },
            ]"
            :data-source="settingItems"
            :pagination="false"
            row-key="id"
            size="small"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.dataIndex === 'type'">
                <a-tag>
                  {{ typeLabelMap[record.type] || record.type }}
                </a-tag>
              </template>

              <template v-if="column.dataIndex === 'value'">
                <span class="text-gray-500">
                  {{ record.value || '-' }}
                </span>
              </template>

              <template v-if="column.dataIndex === 'is_required'">
                {{ record.is_required === 1 ? '是' : '否' }}
              </template>

              <template v-if="column.key === 'action'">
                <a-space>
                  <a-button
                    type="link"
                    size="small"
                    @click="handleEditItem(record)"
                  >
                    编辑
                  </a-button>
                  <a-button
                    type="link"
                    danger
                    size="small"
                    @click="handleDeleteItem(record)"
                  >
                    删除
                  </a-button>
                </a-space>
              </template>
            </template>
          </a-table>

          <a-empty
            v-else
            description="该分组暂无设置项，请点击右上角新增"
            class="mt-8"
          />
        </a-spin>
      </template>

      <template v-else>
        <div class="flex h-64 items-center justify-center text-gray-400">
          请在左侧选择一个分组查看设置项
        </div>
      </template>
    </div>

    <!-- 分组弹窗 -->
    <GroupModal
      v-model:visible="groupModalVisible"
      :edit-data="editingGroup"
      @success="onGroupModalSuccess"
    />

    <!-- 设置项弹窗 -->
    <ItemModal
      v-model:visible="itemModalVisible"
      :group-id="selectedGroupId || 0"
      :edit-data="editingItem"
      @success="onItemModalSuccess"
    />
  </div>
</template>
