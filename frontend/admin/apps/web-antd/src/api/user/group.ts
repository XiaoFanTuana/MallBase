import { requestClient } from '#/api/request';

export namespace UserGroupApi {
  /** 用户分组信息 */
  export interface GroupItem {
    id: number;
    name: string;
    code: string;
    description?: string;
    color?: string;
    sort: number;
    status: number;
    create_time: string;
    update_time: string;
  }

  /** 列表参数 */
  export interface ListParams {
    name?: string;
    code?: string;
    status?: number;
    page?: number;
    limit?: number;
  }

  /** 创建参数 */
  export interface CreateParams {
    name: string;
    code: string;
    description?: string;
    color?: string;
    sort?: number;
    status?: number;
  }

  /** 更新参数 */
  export interface UpdateParams {
    name?: string;
    code?: string;
    description?: string;
    color?: string;
    sort?: number;
    status?: number;
  }
}

/**
 * 获取用户分组列表
 */
export async function getUserGroupListApi(params?: UserGroupApi.ListParams) {
  return requestClient.get<{
    list: UserGroupApi.GroupItem[];
    total: number;
  }>('/user/group/list', { params });
}

/**
 * 获取用户分组详情
 */
export async function getUserGroupInfoApi(id: number) {
  return requestClient.get<UserGroupApi.GroupItem>('/user/group/info', { params: { id } });
}

/**
 * 创建用户分组
 */
export async function createUserGroupApi(data: UserGroupApi.CreateParams) {
  return requestClient.post<{ id: number }>('/user/group/create', data);
}

/**
 * 更新用户分组
 */
export async function updateUserGroupApi(id: number, data: UserGroupApi.UpdateParams) {
  return requestClient.put('/user/group/update', { id, ...data });
}

/**
 * 删除用户分组
 */
export async function deleteUserGroupApi(id: number) {
  return requestClient.delete('/user/group/delete', { params: { id } });
}

/**
 * 更新用户分组状态
 */
export async function updateUserGroupStatusApi(id: number, status: number) {
  return requestClient.put('/user/group/updateStatus', { id, status });
}

/**
 * 获取分组下的用户数
 */
export async function getUserGroupCountApi(id: number) {
  return requestClient.get<{ count: number }>('/user/group/getUserCount', { params: { id } });
}

/**
 * 批量设置用户分组
 */
export async function batchSetUserGroupApi(group_id: number, user_ids: number[]) {
  return requestClient.post('/user/group/batchSetUsers', { group_id, user_ids });
}

/**
 * 移除用户分组
 */
export async function removeUserGroupApi(group_id: number, user_id: number) {
  return requestClient.delete('/user/group/removeUser', { params: { group_id, user_id } });
}

/**
 * 获取用户的所有分组
 */
export async function getUserGroupsApi(user_id: number) {
  return requestClient.get<UserGroupApi.GroupItem[]>('/user/group/getUserGroups', { params: { user_id } });
}