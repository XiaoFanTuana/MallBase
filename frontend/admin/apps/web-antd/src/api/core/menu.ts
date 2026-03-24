import type { RouteRecordStringComponent } from '@vben/types';

import { requestClient } from '#/api/request';

export namespace MenuApi {
  /** 菜单响应数据 */
  export interface MenuResponse {
    /** 首页路径 */
    home_path: string;
    /** 路由列表 */
    routes: RouteRecordStringComponent[];
  }
}

/**
 * 获取用户所有菜单
 */
export async function getAllMenusApi() {
  return requestClient.get<MenuApi.MenuResponse>('/auth/permission/menu');
}
