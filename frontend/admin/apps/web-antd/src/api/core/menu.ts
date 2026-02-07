import type { RouteRecordStringComponent } from '@vben/types';

import { requestClient } from '#/api/request';
import type { PermissionApi } from './permission';

/**
 * 获取用户所有菜单
 * 从后端权限树接口获取，后端返回的权限数据需要转换为前端路由格式
 */
export async function getAllMenusApi() {
  const permissions = await requestClient.get<PermissionApi.PermissionItem[]>(
    '/auth/permission/tree',
  );

  // 过滤出菜单类型的权限，并转换为前端路由格式
  const menus = transformPermissionsToRoutes(permissions);
  return menus;
}

/**
 * 将后端权限数据转换为前端路由格式
 */
function transformPermissionsToRoutes(
  permissions: PermissionApi.PermissionItem[],
): RouteRecordStringComponent[] {
  const routes: RouteRecordStringComponent[] = [];

  for (const permission of permissions) {
    // 只处理菜单类型的权限
    if (permission.type === 'menu' && permission.is_show === 1) {
      const route: RouteRecordStringComponent = {
        name: permission.code,
        path: permission.path,
        component: permission.component || 'LAYOUT',
        meta: {
          title: permission.name,
          icon: permission.icon,
          order: permission.sort,
          hideTab: false,
        },
      };

      // 如果有子菜单，递归处理
      if (permission.children && permission.children.length > 0) {
        route.children = transformPermissionsToRoutes(permission.children);
      }

      routes.push(route);
    }
  }

  return routes;
}
