#!/bin/sh
# Punto de entrada de producción. Solo el contenedor `app` (apache) migra;
# worker y scheduler esperan a que la configuración esté en caché.
set -e

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

if [ "$1" = "apache2-foreground" ]; then
    php artisan migrate --force
    php artisan storage:link --force >/dev/null 2>&1 || true
fi

exec "$@"
