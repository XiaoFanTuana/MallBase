import { requestClient } from '#/api/request';

export namespace AdminApi {
  /** 管理员列表项 */
  export interface AdminItem {
    id: number;
    username: string;
    nickname: string;
    avatar: string;
    email: string;
    mobile: string;
    status: number;
    remark: string;
    role_ids?: number[];
    roles?: RoleInfo[];
    create_time?: string;
    update_time?: string;
  }

  /** 角色信息 */
  export interface RoleInfo {
    id: number;
    name: string;
    code: string;
  }

  /** 获取列表参数 */
  export interface ListParams {
    username?: string;
    status?: number;
    page?: number;
    limit?: number;
  }

  /** 创建管理员参数 */
  export interface CreateParams {
    username: string;
    password: string;
    password_confirm?: string;
    nickname: string;
    avatar?: string;
    email?: string;
    mobile?: string;
    status?: number;
    remark?: string;
    role_ids?: number[];
  }

  /** 更新管理员参数 */
  export interface UpdateParams {
    id: number;
    username?: string;
    nickname?: string;
    avatar?: string;
    email?: string;
    mobile?: string;
    status?: number;
    remark?: string;
    role_ids?: number[];
  }
}

/**
 * 获取管理员列表
 */
export async function getAdminListApi(params?: AdminApi.ListParams) {
  return requestClient.get<AdminApi.AdminItem[]>('/auth/admin/list', {
    params,
  });
}

/**
 * 获取管理员详情
 * 如果不传 id，则获取当前登录管理员的信息
 */
export async function getAdminInfoApi(id?: number) {
  return requestClient.get<AdminApi.AdminItem>('/auth/admin/info', {
    params: id ? { id } : {},
  });
}

/**
 * 创建管理员
 */
export async function createAdminApi(data: AdminApi.CreateParams) {
  return requestClient.post<{ id: number }>('/auth/admin/create', data);
}

/**
 * 更新管理员
 */
export async function updateAdminApi(data: AdminApi.UpdateParams) {
  return requestClient.post('/auth/admin/update', data);
}

/**
 * 删除管理员
 */
export async function deleteAdminApi(id: number) {
  return requestClient.post('/auth/admin/delete', { id });
}
