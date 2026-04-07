import type { RouteRecordRaw } from 'vue-router';

const routes: RouteRecordRaw[] = [
  {
    name: 'ClientUserManagement',
    path: '/user',
    component: () => import('#/views/user/index.vue'),
    meta: {
      icon: 'lucide:users',
      title: '用户管理',
      order: 1,
    },
  },
  {
    name: 'UserGroupManagement',
    path: '/user/group',
    component: () => import('#/views/user/group/index.vue'),
    meta: {
      icon: 'lucide:layers',
      title: '用户分组',
      order: 2,
    },
  },
  {
    name: 'UserTagManagement',
    path: '/user/tag',
    component: () => import('#/views/user/tag/index.vue'),
    meta: {
      icon: 'lucide:tags',
      title: '用户标签',
      order: 3,
    },
  },
];

export default routes;