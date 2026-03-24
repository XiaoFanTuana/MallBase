<script lang="ts" setup>
import { computed, h, ref } from 'vue';

import { message, Switch, Tag } from 'ant-design-vue';

import { getPermissionTreeApi } from '#/api/system/permission';
import {
  createRoleApi,
  deleteRoleApi,
  getRoleInfoApi,
  getRoleListApi,
  updateRoleApi,
  updateRoleStatusApi,
} from '#/api/system/role';
import { useFormModal, useTableCrud } from '#/composables/useTableCrud';

defineOptions({
  name: 'SystemRole',
});

// 使用表格 CRUD composable
const { tableData, loading, pagination, loadData, refresh, handleDelete } =
  useTableCrud(
    {
      list: getRoleListApi,
      delete: deleteRoleApi,
      getInfo: getRoleInfoApi,
    },
    { immediateLoad: false },
  );

// 使用表单弹窗 composable
const {
  modalVisible,
  modalTitle,
  formData,
  formRef,
  openCreateModal,
  openEditModal,
  handleSubmit,
} = useFormModal();

// 权限树数据（菜单）
const permissionTree = ref<any[]>([]);

// 菜单 ID 到名称的映射
const menuNameMap = ref<Record<number, string>>({});

// 按钮权限列表
const buttonPermissions = ref<any[]>([]);

// 接口权限列表
const apiPermissions = ref<any[]>([]);

// 所有按钮权限 ID（用于全选）
const allButtonPermissionIds = ref<number[]>([]);

// 所有接口权限 ID（用于全选）
const allApiPermissionIds = ref<number[]>([]);

// 按钮权限搜索关键词
const buttonSearchKeyword = ref('');

// 接口权限搜索关键词
const apiSearchKeyword = ref('');

// 加载权限数据
const loadPermissionData = async () => {
  const result = await getPermissionTreeApi();
  // 过滤出菜单节点（用于树形选择）
  permissionTree.value = filterMenuTree(result);
  // 构建菜单 ID 到名称的映射
  menuNameMap.value = buildMenuNameMap(result);
  // 收集按钮权限
  buttonPermissions.value = collectPermissions(result, 2);
  // 收集接口权限
  apiPermissions.value = collectPermissions(result, 3);
  // 收集所有按钮权限 ID
  allButtonPermissionIds.value = buttonPermissions.value.map((p) => p.id);
  // 收集所有接口权限 ID
  allApiPermissionIds.value = apiPermissions.value.map((p) => p.id);
};

/**
 * 过滤权限树，只保留菜单节点（type: 1）
 */
function filterMenuTree(permissions: any[]): any[] {
  return permissions
    .filter((item) => item.type === 1) // 只保留菜单
    .map((item) => ({
      ...item,
      children: item.children ? filterMenuTree(item.children) : [],
    }));
}

/**
 * 收集指定类型的权限
 */
function collectPermissions(permissions: any[], type: number): any[] {
  const result: any[] = [];

  function traverse(nodes: any[]) {
    for (const node of nodes) {
      if (node.type === type) {
        result.push(node);
      }
      if (node.children?.length > 0) {
        traverse(node.children);
      }
    }
  }

  traverse(permissions);
  return result;
}

/**
 * 构建菜单 ID 到名称的映射
 */
function buildMenuNameMap(permissions: any[]): Record<number, string> {
  const map: Record<number, string> = {};

  function traverse(nodes: any[]) {
    for (const node of nodes) {
      if (node.type === 1) {
        // 只映射菜单节点
        map[node.id] = node.name;
      }
      if (node.children?.length > 0) {
        traverse(node.children);
      }
    }
  }

  traverse(permissions);
  return map;
}

/**
 * 根据菜单 ID 查找菜单名称
 */
function findMenuName(menuId: number): string {
  return menuNameMap.value[menuId] || '其他';
}

/**
 * 过滤后的按钮权限（根据搜索关键词）
 */
const filteredButtonPermissions = computed(() => {
  if (!buttonSearchKeyword.value) {
    return buttonPermissions.value;
  }
  const keyword = buttonSearchKeyword.value.toLowerCase();
  return buttonPermissions.value.filter(
    (p) =>
      p.name.toLowerCase().includes(keyword) ||
      p.code.toLowerCase().includes(keyword),
  );
});

/**
 * 按钮权限按菜单分组
 */
const buttonPermissionsGrouped = computed(() => {
  const groups: Record<string, any[]> = {};
  filteredButtonPermissions.value.forEach((btn) => {
    const menuName = findMenuName(btn.parent_id);
    const groupName = menuName || '其他';
    if (!groups[groupName]) {
      groups[groupName] = [];
    }
    groups[groupName].push(btn);
  });
  return groups;
});

/**
 * 过滤后的接口权限（根据搜索关键词）
 */
const filteredApiPermissions = computed(() => {
  if (!apiSearchKeyword.value) {
    return apiPermissions.value;
  }
  const keyword = apiSearchKeyword.value.toLowerCase();
  return apiPermissions.value.filter(
    (p) =>
      p.name.toLowerCase().includes(keyword) ||
      p.code.toLowerCase().includes(keyword),
  );
});

/**
 * 接口权限按菜单分组
 */
const apiPermissionsGrouped = computed(() => {
  const groups: Record<string, any[]> = {};
  filteredApiPermissions.value.forEach((api) => {
    const menuName = findMenuName(api.parent_id);
    const groupName = menuName || '其他';
    if (!groups[groupName]) {
      groups[groupName] = [];
    }
    groups[groupName].push(api);
  });
  return groups;
});

/**
 * 全选所有按钮权限
 */
function selectAllButtonPermissions() {
  formData.value.button_permission_ids = [...allButtonPermissionIds.value];
}

/**
 * 清空所有按钮权限
 */
function clearAllButtonPermissions() {
  formData.value.button_permission_ids = [];
}

/**
 * 全选所有接口权限
 */
function selectAllApiPermissions() {
  formData.value.api_permission_ids = [...allApiPermissionIds.value];
}

/**
 * 清空所有接口权限
 */
function clearAllApiPermissions() {
  formData.value.api_permission_ids = [];
}

// 打开新增弹窗
const handleCreate = async () => {
  await loadPermissionData();
  openCreateModal({
    name: '',
    code: '',
    remark: '',
    status: 1,
    sort: 0,
    menu_permission_ids: [], // 菜单权限 ID
    button_permission_ids: [], // 按钮权限 ID
    api_permission_ids: [], // 接口权限 ID
  });
  // 默认不选任何权限
  formData.value.button_permission_ids = [];
  formData.value.api_permission_ids = [];
};

// 打开编辑弹窗
const handleEdit = async (row: any) => {
  await loadPermissionData();
  await openEditModal(row, getRoleInfoApi);
};

// 提交表单
const handleFormSubmit = async () => {
  await handleSubmit(
    {
      create: createRoleApi,
      update: updateRoleApi,
    },
    () => {
      loadData(searchParams.value);
    },
  );
};

// 搜索参数
const searchParams = ref({
  keyword: '',
  status: undefined as number | undefined,
});

// 重置搜索
const resetSearch = () => {
  searchParams.value = {
    keyword: '',
    status: undefined,
  };
  loadData(searchParams.value);
};

// 表格列定义
const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '角色名称', dataIndex: 'name', width: 150 },
  { title: '角色编码', dataIndex: 'code', width: 150 },
  { title: '备注', dataIndex: 'remark', width: 200 },
  {
    title: '状态',
    dataIndex: 'status',
    width: 80,
    customRender: ({ record }: any) => {
      return h(Switch, {
        checked: record.status === 1,
        onChange: async (checked: any) => {
          await updateRoleStatusApi(record.id, {
            status: checked ? 1 : 0,
          });
          message.success('更新成功');
          await loadData(searchParams.value);
        },
      });
    },
  },
  { title: '排序', dataIndex: 'sort', width: 80 },
  {
    title: '操作',
    key: 'action',
    width: 200,
  },
];

// 初始化加载数据
loadData(searchParams.value);
</script>

<template>
  <div class="p-4">
    <div class="mb-4">
      <a-button type="primary" @click="handleCreate"> 新增角色 </a-button>
      <a-button class="ml-2" @click="refresh"> 刷新 </a-button>
    </div>

    <!-- 搜索表单 -->
    <a-form layout="inline" class="mb-4">
      <a-form-item label="关键词">
        <a-input
          v-model:value="searchParams.keyword"
          placeholder="角色名称/角色编码/备注"
          allow-clear
          style="width: 200px"
        />
      </a-form-item>
      <a-form-item label="状态">
        <a-select
          v-model:value="searchParams.status"
          placeholder="请选择"
          allow-clear
          style="width: 150px"
        >
          <a-select-option :value="1">启用</a-select-option>
          <a-select-option :value="0">禁用</a-select-option>
        </a-select>
      </a-form-item>
      <a-form-item>
        <a-button type="primary" @click="loadData(searchParams)">
          搜索
        </a-button>
        <a-button class="ml-2" @click="resetSearch"> 重置 </a-button>
      </a-form-item>
    </a-form>

    <a-table
      :columns="columns"
      :data-source="tableData"
      :loading="loading"
      :pagination="pagination"
      :scroll="{ x: 900 }"
      @change="loadData(searchParams)"
      row-key="id"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'action'">
          <a-space>
            <a-button type="link" size="small" @click="handleEdit(record)">
              编辑
            </a-button>
            <a-button
              type="link"
              danger
              size="small"
              @click="handleDelete(record, 'name')"
            >
              删除
            </a-button>
          </a-space>
        </template>
      </template>
    </a-table>

    <!-- 新增/编辑弹窗 -->
    <a-modal
      v-model:open="modalVisible"
      :title="modalTitle"
      width="1600px"
      class="role-modal"
      @ok="handleFormSubmit"
    >
      <a-form
        ref="formRef"
        :model="formData"
        :label-col="{ span: 4 }"
        :wrapper-col="{ span: 20 }"
      >
        <a-form-item
          label="角色名称"
          name="name"
          :rules="[{ required: true, message: '请输入角色名称' }]"
        >
          <a-input v-model:value="formData.name" placeholder="请输入角色名称" />
        </a-form-item>
        <a-form-item
          label="角色编码"
          name="code"
          :rules="[{ required: true, message: '请输入角色编码' }]"
        >
          <a-input v-model:value="formData.code" placeholder="请输入角色编码" />
        </a-form-item>
        <a-form-item label="排序" name="sort">
          <a-input-number
            v-model:value="formData.sort"
            :min="0"
            style="width: 100%"
          />
        </a-form-item>
        <a-form-item label="状态" name="status">
          <a-radio-group v-model:value="formData.status">
            <a-radio :value="1">启用</a-radio>
            <a-radio :value="0">禁用</a-radio>
          </a-radio-group>
        </a-form-item>

        <!-- 菜单权限 -->
        <a-form-item label="菜单权限" name="menu_permission_ids">
          <div class="permission-description">选择角色可以访问的菜单</div>
          <a-tree
            v-model:checked-keys="formData.menu_permission_ids"
            checkable
            :tree-data="permissionTree"
            :field-names="{
              title: 'name',
              key: 'id',
              children: 'children',
            }"
            :check-strictly="false"
            class="permission-tree w-full"
          />
        </a-form-item>

        <!-- 按钮权限 -->
        <a-form-item label="按钮权限" name="button_permission_ids">
          <div class="permission-controls">
            <a-input
              v-model:value="buttonSearchKeyword"
              placeholder="搜索按钮权限"
              allow-clear
              style="width: 200px"
            >
              <template #prefix>
                <span class="text-gray-400">🔍</span>
              </template>
            </a-input>
            <a-space>
              <a-button size="small" @click="selectAllButtonPermissions">
                全选
              </a-button>
              <a-button size="small" @click="clearAllButtonPermissions">
                清空
              </a-button>
              <span class="text-sm text-gray-500">
                已选择 {{ formData.button_permission_ids?.length || 0 }} /
                {{ filteredButtonPermissions.length }} 项
              </span>
            </a-space>
          </div>
          <div class="permission-description">选择角色可以使用的按钮功能</div>
          <div class="permission-list">
            <a-checkbox-group
              v-model:value="formData.button_permission_ids"
              class="w-full"
            >
              <div
                v-for="(buttons, menuName) in buttonPermissionsGrouped"
                :key="menuName"
                class="permission-group"
              >
                <div class="permission-group-title">
                  {{ menuName }}
                </div>
                <div class="permission-items">
                  <a-checkbox
                    v-for="btn in buttons"
                    :key="btn.id"
                    :value="btn.id"
                  >
                    <Tag color="blue" class="mr-1">{{ btn.code }}</Tag>
                    {{ btn.name }}
                  </a-checkbox>
                </div>
              </div>
              <div
                v-if="Object.keys(buttonPermissionsGrouped).length === 0"
                class="w-full py-8 text-center text-gray-400"
              >
                暂无按钮权限
              </div>
            </a-checkbox-group>
          </div>
        </a-form-item>

        <!-- 接口权限 -->
        <a-form-item label="接口权限" name="api_permission_ids">
          <div class="permission-controls">
            <a-input
              v-model:value="apiSearchKeyword"
              placeholder="搜索接口权限"
              allow-clear
              style="width: 200px"
            >
              <template #prefix>
                <span class="text-gray-400">🔍</span>
              </template>
            </a-input>
            <a-space>
              <a-button size="small" @click="selectAllApiPermissions">
                全选
              </a-button>
              <a-button size="small" @click="clearAllApiPermissions">
                清空
              </a-button>
              <span class="text-sm text-gray-500">
                已选择 {{ formData.api_permission_ids?.length || 0 }} /
                {{ filteredApiPermissions.length }} 项
              </span>
            </a-space>
          </div>
          <div class="permission-description">选择角色可以调用的 API 接口</div>
          <div class="permission-list">
            <a-checkbox-group
              v-model:value="formData.api_permission_ids"
              class="w-full"
            >
              <div
                v-for="(apis, menuName) in apiPermissionsGrouped"
                :key="menuName"
                class="permission-group"
              >
                <div class="permission-group-title">
                  {{ menuName }}
                </div>
                <div class="permission-items">
                  <a-checkbox v-for="api in apis" :key="api.id" :value="api.id">
                    <Tag color="green" class="mr-1">{{ api.code }}</Tag>
                    {{ api.name }}
                  </a-checkbox>
                </div>
              </div>
              <div
                v-if="Object.keys(apiPermissionsGrouped).length === 0"
                class="w-full py-8 text-center text-gray-400"
              >
                暂无接口权限
              </div>
            </a-checkbox-group>
          </div>
        </a-form-item>

        <a-form-item label="备注" name="remark">
          <a-textarea
            v-model:value="formData.remark"
            :rows="3"
            placeholder="请输入备注"
          />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<style scoped>
.role-modal :deep(.ant-modal-body) {
  max-height: 70vh;
  overflow-y: auto;
  overflow-x: hidden;
}

.permission-controls {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 0.75rem;
}

.permission-description {
  margin-bottom: 0.5rem;
  font-size: 0.875rem;
  color: rgb(107, 114, 128);
}

.permission-tree {
  max-height: 200px;
  overflow-y: auto;
  border: 1px solid rgb(217, 217, 217);
  padding: 8px;
  border-radius: 4px;
}

.permission-list {
  max-height: 300px;
  overflow-y: auto;
  overflow-x: hidden;
}

.permission-group {
  margin-bottom: 1rem;
}

.permission-group-title {
  margin-bottom: 0.5rem;
  padding-bottom: 0.25rem;
  border-bottom: 1px solid rgb(209, 213, 219);
  font-weight: 700;
  color: rgb(75, 85, 99);
}

.permission-items {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
</style>
