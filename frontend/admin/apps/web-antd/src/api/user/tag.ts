import { requestClient } from '#/api/request';

export namespace UserTagApi {
  /** 用户标签信息 */
  export interface TagItem {
    id: number;
    name: string;
    color?: string;
    sort: number;
    status: number;
    create_time: string;
    update_time: string;
  }

  /** 列表参数 */
  export interface ListParams {
    name?: string;
    status?: number;
    page?: number;
    limit?: number;
  }

  /** 创建参数 */
  export interface CreateParams {
    name: string;
    color?: string;
    sort?: number;
    status?: number;
  }

  /** 更新参数 */
  export interface UpdateParams {
    name?: string;
    color?: string;
    sort?: number;
    status?: number;
  }
}

/**
 * 获取用户标签列表
 */
export async function getUserTagListApi(params?: UserTagApi.ListParams) {
  return requestClient.get<{
    list: UserTagApi.TagItem[];
    total: number;
  }>('/user/tag/list', { params });
}

/**
 * 获取用户标签详情
 */
export async function getUserTagInfoApi(id: number) {
  return requestClient.get<UserTagApi.TagItem>('/user/tag/info', { params: { id } });
}

/**
 * 创建用户标签
 */
export async function createUserTagApi(data: UserTagApi.CreateParams) {
  return requestClient.post<{ id: number }>('/user/tag/create', data);
}

/**
 * 更新用户标签
 */
export async function updateUserTagApi(id: number, data: UserTagApi.UpdateParams) {
  return requestClient.put('/user/tag/update', { id, ...data });
}

/**
 * 删除用户标签
 */
export async function deleteUserTagApi(id: number) {
  return requestClient.delete('/user/tag/delete', { params: { id } });
}

/**
 * 更新用户标签状态
 */
export async function updateUserTagStatusApi(id: number, status: number) {
  return requestClient.put('/user/tag/updateStatus', { id, status });
}

/**
 * 获取标签下的用户数
 */
export async function getUserTagCountApi(id: number) {
  return requestClient.get<{ count: number }>('/user/tag/getUserCount', { params: { id } });
}

/**
 * 批量给用户打标签
 */
export async function batchSetUserTagApi(tag_id: number, user_ids: number[]) {
  return requestClient.post('/user/tag/batchSetUsers', { tag_id, user_ids });
}

/**
 * 移除用户标签
 */
export async function removeUserTagApi(tag_id: number, user_id: number) {
  return requestClient.delete('/user/tag/removeUser', { params: { tag_id, user_id } });
}

/**
 * 获取用户的所有标签
 */
export async function getUserTagsApi(user_id: number) {
  return requestClient.get<UserTagApi.TagItem[]>('/user/tag/getUserTags', { params: { user_id } });
}