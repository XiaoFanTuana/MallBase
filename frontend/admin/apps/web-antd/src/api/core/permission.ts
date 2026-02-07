import { requestClient } from '#/api/request';

export namespace PermissionApi {
  /** 权限类型 */
  export type PermissionType = 'menu' | 'button';

  /** 权限列表项 */
  export interface PermissionItem {
    id: number;
    parent_id: number;
    name: string;
    code: string;
    type: PermissionType;
    path: string;
    icon: string;
    component: string;
    sort: number;
    status: number;
    is_show: number;
    remark: string;
    children?: PermissionItem[];
    create_time?: string;
    update_time?: string;
  }

  /** 获取树形列表参数 */
  export interface TreeParams {
    name?: string;
    code?: string;
    type?: PermissionType;
    status?: number;
  }

  /** 获取列表参数 */
  export interface ListParams extends TreeParams {
    page?: number;
    limit?: number;
  }

  /** 创建权限参数 */
  export interface CreateParams {
    parent_id?: number;
    name: string;
    code: string;
    type: PermissionType;
    path: string;
    icon?: string;
    component?: string;
    sort?: number;
    status?: number;
    is_show?: number;
    remark?: string;
  }

  /** 更新权限参数 */
  export interface UpdateParams extends CreateParams {
    id: number;
  }
}

/**
 * 获取权限树形列表
 */
export async function getPermissionTreeApi(params?: PermissionApi.TreeParams) {
  return requestClient.get<PermissionApi.PermissionItem[]>('/auth/permission/tree', {
    params,
  });
}

/**
 * 获取权限列表
 */
export async function getPermissionListApi(params?: PermissionApi.ListParams) {
  return requestClient.get<PermissionApi.PermissionItem[]>('/auth/permission/list', {
    params,
  });
}

/**
 * 获取权限详情
 */
export async function getPermissionInfoApi(id: number) {
  return requestClient.get<PermissionApi.PermissionItem>('/auth/permission/info', {
    params: { id },
  });
}

/**
 * 创建权限
 */
export async function createPermissionApi(data: PermissionApi.CreateParams) {
  return requestClient.post<{ id: number }>('/auth/permission/create', data);
}

/**
 * 更新权限
 */
export async function updatePermissionApi(data: PermissionApi.UpdateParams) {
  return requestClient.post('/auth/permission/update', data);
}

/**
 * 删除权限
 */
export async function deletePermissionApi(id: number) {
  return requestClient.post('/auth/permission/delete', { id });
}
