import { requestClient } from '#/api/request';

export namespace GoodsSpecTemplateApi {
  /** 模板规格项 */
  export interface DetailItem {
    spec_name: string;
    values: string[];
  }

  /** 规格模板信息 */
  export interface TemplateItem {
    id: number;
    name: string;
    detail: DetailItem[];
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
    detail: DetailItem[];
    sort?: number;
    status?: number;
  }

  /** 更新参数 */
  export interface UpdateParams {
    name?: string;
    detail?: DetailItem[];
    sort?: number;
    status?: number;
  }
}

/**
 * 获取规格模板列表
 */
export async function getGoodsSpecTemplateListApi(
  params?: GoodsSpecTemplateApi.ListParams,
) {
  return requestClient.get<{
    list: GoodsSpecTemplateApi.TemplateItem[];
    total: number;
  }>('/goods/spec-template/list', { params });
}

/**
 * 获取规格模板详情
 */
export async function getGoodsSpecTemplateInfoApi(id: number) {
  return requestClient.get<GoodsSpecTemplateApi.TemplateItem>(
    `/goods/spec-template/info/${id}`,
  );
}

/**
 * 获取所有规格模板（不分页，用于下拉选择）
 */
export async function getAllGoodsSpecTemplatesApi() {
  return requestClient.get<GoodsSpecTemplateApi.TemplateItem[]>(
    '/goods/spec-template/all',
  );
}

/**
 * 创建规格模板
 */
export async function createGoodsSpecTemplateApi(
  data: GoodsSpecTemplateApi.CreateParams,
) {
  return requestClient.post<{ id: number }>(
    '/goods/spec-template/create',
    data,
  );
}

/**
 * 更新规格模板
 */
export async function updateGoodsSpecTemplateApi(
  id: number,
  data: GoodsSpecTemplateApi.UpdateParams,
) {
  return requestClient.put(`/goods/spec-template/update/${id}`, data);
}

/**
 * 删除规格模板
 */
export async function deleteGoodsSpecTemplateApi(id: number) {
  return requestClient.delete(`/goods/spec-template/delete/${id}`);
}

/**
 * 更新规格模板状态
 */
export async function updateGoodsSpecTemplateStatusApi(
  id: number,
  status: number,
) {
  return requestClient.put(`/goods/spec-template/updateStatus/${id}`, {
    status,
  });
}
