FROM node:22-slim AS builder

ENV PNPM_HOME="/pnpm"
ENV PATH="$PNPM_HOME:$PATH"
ENV NODE_OPTIONS=--max-old-space-size=8192
ENV TZ=Asia/Shanghai

ARG VITE_GLOB_API_URL=/api

RUN npm i -g corepack

WORKDIR /app

COPY frontend/admin/ /app/

RUN --mount=type=cache,id=pnpm,target=/pnpm/store pnpm install --frozen-lockfile

RUN echo "VITE_BASE=/" > /app/apps/web-antd/.env.production \
    && echo "VITE_GLOB_API_URL=${VITE_GLOB_API_URL}" >> /app/apps/web-antd/.env.production \
    && echo "VITE_COMPRESS=gzip" >> /app/apps/web-antd/.env.production \
    && echo "VITE_PWA=false" >> /app/apps/web-antd/.env.production \
    && echo "VITE_ROUTER_HISTORY=hash" >> /app/apps/web-antd/.env.production \
    && echo "VITE_INJECT_APP_LOADING=true" >> /app/apps/web-antd/.env.production \
    && echo "VITE_ARCHIVER=false" >> /app/apps/web-antd/.env.production

RUN pnpm run build --filter=@vben/web-antd

FROM nginx:stable-alpine AS production

RUN rm -rf /etc/nginx/conf.d/default.conf

COPY --from=builder /app/apps/web-antd/dist /usr/share/nginx/html
COPY deploy/docker/frontend/nginx.conf /etc/nginx/nginx.conf

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
