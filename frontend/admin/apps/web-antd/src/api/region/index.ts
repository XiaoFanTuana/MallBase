import { requestClient } from '#/api/request';

export namespace RegionApi {
  export interface RegionItem {
    id: number;
    parent_id: number;
    code: string;
    name: string;
    level: number;
    path_codes: string;
    status: number;
    sort: number;
  }
}

export async function getRegionChildrenApi(parent_id = 0) {
  return requestClient.get<RegionApi.RegionItem[]>('/region/children', {
    params: { parent_id },
  });
}

export async function getRegionPathApi(id: number) {
  return requestClient.get<RegionApi.RegionItem[]>(`/region/path/${id}`);
}

export async function getRegionListApi(params?: {
  keyword?: string;
  level?: number;
  status?: number;
  parent_id?: number;
  page?: number;
  limit?: number;
}) {
  return requestClient.get<{ list: RegionApi.RegionItem[]; total: number }>('/region/list', { params });
}

export async function getRegionInfoApi(id: number) {
  return requestClient.get<RegionApi.RegionItem & { path?: RegionApi.RegionItem[] }>(`/region/info/${id}`);
}

export async function createRegionApi(data: {
  parent_id: number;
  code: string;
  name: string;
  level: number;
  status?: number;
  sort?: number;
}) {
  return requestClient.post<{ id: number }>('/region/create', data);
}

export async function updateRegionApi(id: number, data: {
  parent_id: number;
  code: string;
  name: string;
  level: number;
  status?: number;
  sort?: number;
}) {
  return requestClient.put(`/region/update/${id}`, data);
}

export async function updateRegionStatusApi(id: number, status: number) {
  return requestClient.put(`/region/updateStatus/${id}`, { status });
}

export async function deleteRegionApi(id: number) {
  return requestClient.delete(`/region/delete/${id}`);
}
