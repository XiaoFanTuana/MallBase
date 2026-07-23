# syntax=docker/dockerfile:1.7

FROM --platform=$BUILDPLATFORM node:22.22.0-alpine AS admin-builder

ENV PNPM_HOME=/pnpm \
    PATH=/pnpm:$PATH \
    NODE_OPTIONS=--max-old-space-size=8192 \
    CI=true

WORKDIR /build/admin

RUN corepack enable \
    && corepack prepare pnpm@10.28.2 --activate

COPY frontend/admin/package.json \
     frontend/admin/pnpm-lock.yaml \
     frontend/admin/pnpm-workspace.yaml \
     frontend/admin/.npmrc \
     ./

# Keep the fetched store in the image layer: remote BuildKit cache restores
# layers, but not cache-mount contents required by the offline install.
RUN pnpm fetch --frozen-lockfile

COPY frontend/admin/ ./

RUN pnpm install --offline --frozen-lockfile \
    && pnpm run build:antd \
    && test -s apps/web-antd/dist/index.html


FROM --platform=$BUILDPLATFORM node:20.19.0-alpine AS uniapp-builder

ENV NODE_OPTIONS=--max-old-space-size=4096 \
    CI=true

WORKDIR /build/uniapp

COPY frontend/uniapp/package.json frontend/uniapp/package-lock.json ./

RUN --mount=type=cache,id=mallbase-uniapp-npm,target=/root/.npm,sharing=locked \
    npm ci

COPY frontend/uniapp/ ./

RUN npm run build:h5 \
    && test -s dist/build/h5/index.html


FROM nginx:stable-alpine AS runtime

ENV BACKEND_HOST=backend \
    BACKEND_PORT=8080 \
    NGINX_ENVSUBST_FILTER="^(BACKEND_HOST|BACKEND_PORT)$"

RUN rm -f /etc/nginx/conf.d/default.conf \
    && mkdir -p /etc/nginx/snippets

COPY deploy/docker/web/default.conf.template /etc/nginx/templates/default.conf.template
COPY deploy/docker/web/proxy-headers.conf /etc/nginx/snippets/mallbase-proxy-headers.conf
COPY --from=admin-builder /build/admin/apps/web-antd/dist/ /usr/share/nginx/html/admin/
COPY --from=uniapp-builder /build/uniapp/dist/build/h5/ /usr/share/nginx/html/client/

RUN test -s /usr/share/nginx/html/admin/index.html \
    && test -s /usr/share/nginx/html/client/index.html \
    && BACKEND_HOST=127.0.0.1 BACKEND_PORT=8080 \
       envsubst '${BACKEND_HOST} ${BACKEND_PORT}' \
       < /etc/nginx/templates/default.conf.template \
       > /etc/nginx/conf.d/default.conf \
    && nginx -t \
    && rm /etc/nginx/conf.d/default.conf

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/healthz || exit 1
