import type { RouteRecordRaw } from 'vue-router';

const routes: RouteRecordRaw[] = [
  {
    name: 'RegionManagement',
    path: '/settings/region',
    component: () => import('#/views/settings/region/index.vue'),
    meta: {
      icon: 'lucide:map-pinned',
      title: '地区管理',
      order: 10,
    },
  },
  {
    name: 'FreightTemplateManagement',
    path: '/settings/freight-template',
    component: () => import('#/views/settings/freight-template/index.vue'),
    meta: {
      icon: 'lucide:truck',
      title: '运费模板',
      order: 11,
    },
  },
];

export default routes;
