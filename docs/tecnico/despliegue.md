# Despliegue en Coolify

Una sola imagen (`Dockerfile`, etapa `prod`) sirve para los tres recursos de la aplicación;
solo cambia el comando. PostgreSQL y Redis se crean como bases de datos administradas de Coolify.

| Recurso | Tipo | Comando | Puerto | Health check |
|---|---|---|---|---|
| `app` | Dockerfile, target `prod` | (por defecto) `apache2-foreground` | 8080 | `GET /up` |
| `worker` | Dockerfile, target `prod` | `php artisan queue:work --tries=3 --backoff=10 --max-time=3600` | — | desactivado |
| `scheduler` | Dockerfile, target `prod` | `php artisan schedule:work` | — | desactivado |
| `postgres` | PostgreSQL 18 | — | 5432 | de Coolify |
| `redis` | Redis 8 | — | 6379 | de Coolify |

## Qué hace el arranque

`docker/entrypoint.sh` genera las cachés de configuración, rutas, vistas y eventos.
Solo el recurso `app` ejecuta `migrate --force`, para que las migraciones no corran tres veces.
Por eso `worker` y `scheduler` deben reiniciarse después de cada despliegue de `app`.

## Variables de entorno

Las mismas de `.env.example`, con estos valores en producción:

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://crm.fdonbosco.org`
- `APP_KEY`: generar una vez y guardarla en Coolify (nunca en git).
- `LOG_CHANNEL=stderr`
- `DB_*` y `REDIS_*`: los que entregue Coolify para los recursos `postgres` y `redis`.

## Pendiente

El servidor Coolify y los dominios definitivos aún no están definidos (ver `docs/pendientes.md`).
