import { defineConfig } from '@vben/vite-config';

export default defineConfig(async () => {
  const e2e = process.env.VITE_E2E === 'true';
  const backendOrigin =
    process.env.MALLBASE_E2E_BACKEND_ORIGIN || 'http://127.0.0.1:8080';

  return {
    application: {},
    vite: {
      server: {
        proxy: {
          '/api': {
            changeOrigin: true,
            rewrite: (path) => path.replace(/^\/api/, ''),
            // mock代理目标地址
            target: 'http://localhost:5320/api',
            ws: true,
          },
          ...(e2e
            ? {
                '/admin/api': {
                  changeOrigin: true,
                  target: backendOrigin,
                },
                '/upgrade': {
                  changeOrigin: true,
                  target: backendOrigin,
                },
              }
            : {}),
        },
      },
    },
  };
});
