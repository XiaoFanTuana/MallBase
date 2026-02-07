import { requestClient } from '#/api/request';

export namespace RoleApi {
  /** 角色列表项 */
  export interface RoleItem {
    id: number;
    name: string;
    code: string;
    status: number;
    sort: number;
    remark: string;
    permission_ids?: number[];
    create_time?: string;
    update_time?: string;
  }

  /** 获取列表参数 */
  export interface ListParams {
    name?: string;
    code?: string;
    status?: number;
    page?: number;
    limit?: number;
  }

  /** 创建角色参数 */
  export interface CreateParams {
    name: string;
    code: string;
    status?: number;
    sort?: number;
    remark?: string;
    permission_ids?: number[];
  }

  /** 更新角色参数 */
  export interface UpdateParams extends CreateParams {
    id: number;
  }
}

/**
 * 获取角色列表
 */
export async function getRoleListApi(params?: RoleApi.ListParams) {
  return requestClient.get<RoleApi.RoleItem[]>('/auth/role/list', { params });
}

/**
 * 获取所有角色
 */
export async function getAllRolesApi() {
  return requestClient.get<RoleApi.RoleItem[]>('/auth/role/all');
}

/**
 * 获取角色详情
 */
export async function getRoleInfoApi(id: number) {
  return requestClient.get<RoleApi.RoleItem>('/auth/role/info', { params: { id } });
}

/**
 * 创建角色
 */
export async function createRoleApi(data: RoleApi.CreateParams) {
  return requestClient.post<{ id: number }>('/auth/role/create', data);
}

/**
 * 更新角色
 */
export async function updateRoleApi(data: RoleApi.UpdateParams) {
  return requestClient.post('/auth/role/update', data);
}

/**
 * 删除角色
 */
export async function deleteRoleApi(id: number) {
  return requestClient.post('/auth/role/delete', { id });
}
