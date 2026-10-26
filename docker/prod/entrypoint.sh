#!/bin/sh
# Prepara caches do Laravel a cada start (config vem das variáveis de ambiente).
set -e
cd /var/www/backend
if [ "${SERTEC_SKIP_CACHE:-0}" != "1" ]; then
  php artisan config:cache >/dev/null
  php artisan route:cache >/dev/null
  php artisan event:cache >/dev/null
fi
php artisan storage:link >/dev/null 2>&1 || true
exec "$@"
