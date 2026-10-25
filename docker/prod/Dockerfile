# Imagens de PRODUÇÃO do SerTec Food (multi-stage).
#   docker compose -f docker-compose.prod.yml build
# Alvos: app (PHP-FPM + código + build do cardápio) e web (Caddy + SPAs).

# ---------- 1. Frontends (Vue) ----------
FROM node:22-bookworm-slim AS frontend
WORKDIR /src/frontend
RUN corepack enable
COPY frontend/package.json frontend/pnpm-lock.yaml frontend/pnpm-workspace.yaml ./
COPY frontend/apps/storefront/package.json apps/storefront/
COPY frontend/apps/merchant/package.json apps/merchant/
COPY frontend/apps/admin/package.json apps/admin/
COPY frontend/packages/ui/package.json packages/ui/
COPY frontend/packages/api-client/package.json packages/api-client/
RUN pnpm install --frozen-lockfile
COPY frontend/ ./
RUN pnpm build

# ---------- 2. Dependências PHP (sem dev) ----------
FROM composer:2 AS vendor
WORKDIR /src
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY backend/ ./
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# ---------- 3. App: PHP-FPM ----------
FROM php:8.4-fpm-bookworm AS app
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql redis intl bcmath pcntl zip opcache gd \
    && apt-get update && apt-get install -y --no-install-recommends mariadb-client \
    && rm -rf /var/lib/apt/lists/*
COPY docker/prod/php.ini /usr/local/etc/php/conf.d/zz-sertec.ini
COPY docker/prod/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-sertec.conf
WORKDIR /var/www/backend
COPY --chown=www-data:www-data --from=vendor /src /var/www/backend
# HTML do cardápio para as meta tags por restaurante (StorefrontShellController).
COPY --from=frontend /src/frontend/apps/storefront/dist/index.html /srv/www/storefront/index.html
COPY docker/prod/entrypoint.sh /usr/local/bin/sertec-entrypoint
RUN chmod +x /usr/local/bin/sertec-entrypoint \
    && rm -f .env && mkdir -p storage/framework/{cache,sessions,views} storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
ENTRYPOINT ["sertec-entrypoint"]
CMD ["php-fpm"]

# ---------- 4. Web: Caddy (HTTPS automático) + SPAs ----------
FROM caddy:2.10-alpine AS web
COPY docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY --from=frontend /src/frontend/apps/storefront/dist /srv/www/storefront
COPY --from=frontend /src/frontend/apps/merchant/dist /srv/www/merchant
COPY --from=frontend /src/frontend/apps/admin/dist /srv/www/admin
