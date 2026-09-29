#!/bin/sh
# Punto de entrada de producción. Solo el contenedor `app` (apache) migra;
# worker y scheduler esperan a que la configuración esté en caché.
#
# Las migraciones corren con el usuario dueño del esquema (DB_MIGRATION_USERNAME,
# crm_owner) antes de guardar la configuración en caché; la aplicación queda con
# DB_USERNAME (crm_app, sin permisos de crear ni borrar tablas).
# docs/tecnico/proteccion-de-datos.md §2.
set -e

if [ "$1" = "apache2-foreground" ]; then
    php artisan config:clear >/dev/null
    DB_USERNAME="${DB_MIGRATION_USERNAME:-$DB_USERNAME}" \
    DB_PASSWORD="${DB_MIGRATION_PASSWORD:-$DB_PASSWORD}" \
        php artisan migrate --force
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

if [ "$1" = "apache2-foreground" ]; then
    php artisan storage:link --force >/dev/null 2>&1 || true
fi

exec "$@"
