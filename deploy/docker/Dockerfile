# ==========================================
# MallBase 单容器构建（前端 + 后端）
# ==========================================

# --- Stage 1: 前端构建 ---
FROM node:22-slim AS frontend-builder

ENV PNPM_HOME="/pnpm"
ENV PATH="$PNPM_HOME:$PATH"
ENV NODE_OPTIONS=--max-old-space-size=8192

RUN npm i -g corepack

WORKDIR /frontend

COPY frontend/admin/ .

RUN --mount=type=cache,id=pnpm,target=/pnpm/store pnpm install --frozen-lockfile

ARG VITE_GLOB_API_URL=/api
RUN echo "VITE_BASE=/admin/" > apps/web-antd/.env.production \
    && echo "VITE_GLOB_API_URL=${VITE_GLOB_API_URL}" >> apps/web-antd/.env.production \
    && echo "VITE_COMPRESS=gzip" >> apps/web-antd/.env.production \
    && echo "VITE_PWA=false" >> apps/web-antd/.env.production \
    && echo "VITE_ROUTER_HISTORY=hash" >> apps/web-antd/.env.production \
    && echo "VITE_INJECT_APP_LOADING=true" >> apps/web-antd/.env.production \
    && echo "VITE_ARCHIVER=false" >> apps/web-antd/.env.production

RUN pnpm run build --filter=@vben/web-antd

# --- Stage 2: 后端运行 ---
FROM phpswoole/swoole:php8.2-alpine

WORKDIR /app

RUN apk add --no-cache \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    icu-dev \
    oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        gd \
        zip \
        intl \
        mbstring \
        bcmath \
        opcache \
    && rm -rf /var/cache/apk/*

RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.jit_buffer_size=128M" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.jit=1255" >> /usr/local/etc/php/conf.d/opcache.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY backend/ .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p runtime public/admin public/uploads \
    && chmod -R 777 runtime

# 复制前端构建产物到 public/admin/
COPY --from=frontend-builder /frontend/apps/web-antd/dist/ public/admin/

EXPOSE 8080

CMD ["php", "think", "swoole"]
