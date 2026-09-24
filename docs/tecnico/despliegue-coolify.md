# Despliegue en Coolify

> **Estado: PENDIENTE EXTERNO.** Esta guía no se ha probado en un servidor real: todavía no
> existen el servidor Coolify ni los dominios. Lo verificado hasta ahora es que la imagen `prod`
> se construye y responde en local. Cada paso marcado **PENDIENTE EXTERNO** debe confirmarse
> la primera vez que se despliegue.

## Arquitectura

Una sola imagen (`Dockerfile`, etapa `prod`) sirve para los tres recursos de la aplicación;
solo cambia el comando. PostgreSQL y Redis son bases de datos administradas por Coolify.

| Recurso | Tipo en Coolify | Comando | Puerto | Health check |
|---|---|---|---|---|
| `app` | Aplicación, Dockerfile, target `prod` | (por defecto) `apache2-foreground` | 8080 | `GET /up` |
| `worker` | Aplicación, Dockerfile, target `prod` | `php artisan queue:work --tries=3 --backoff=10 --max-time=3600` | ninguno | desactivado |
| `scheduler` | Aplicación, Dockerfile, target `prod` | `php artisan schedule:work` | ninguno | desactivado |
| `postgres` | Base de datos PostgreSQL 18 | — | 5432 (solo red interna) | de Coolify |
| `redis` | Base de datos Redis 8 | — | 6379 (solo red interna) | de Coolify |

La imagen corre como `www-data` (sin root) y Apache escucha en el **8080**.

## 1. Crear los recursos — PENDIENTE EXTERNO

1. En Coolify, crear un **Proyecto** "CRM Fundación Don Bosco" con un entorno `production`.
2. **PostgreSQL:** *New resource → Database → PostgreSQL 18*. Nombre `postgres`.
   Anotar el host interno, usuario, contraseña y base que genera Coolify. No publicar el puerto.
3. **Redis:** *New resource → Database → Redis 8*. Nombre `redis`. Anotar host y contraseña.
   No publicar el puerto.
4. **app:** *New resource → Application → repositorio GitHub `CarlosArroyoG/CRMDona`*, rama `main`.
   - Build pack: **Dockerfile**. Ubicación `/Dockerfile`. **Target: `prod`**.
   - Ports exposes: `8080`.
   - Health check: activado, ruta `/up`, puerto `8080`.
5. **worker:** otra aplicación con el mismo repositorio, rama y target `prod`.
   - Custom start command: `php artisan queue:work --tries=3 --backoff=10 --max-time=3600`.
   - Sin dominio, sin puerto y con el health check desactivado (no sirve HTTP).
6. **scheduler:** igual que `worker`, con start command `php artisan schedule:work`.

Los tres recursos de aplicación deben estar en la misma red que `postgres` y `redis`
(Coolify lo hace dentro del mismo proyecto y entorno).

## 2. Variables de entorno

Se capturan en Coolify (*Environment Variables*) para `app`, `worker` y `scheduler`, con **los
mismos valores** en los tres. Nunca se escriben en git. La referencia es `.env.example`.

| Variable | Valor en producción |
|---|---|
| `APP_NAME` | `"CRM Fundación Don Bosco"` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` (**nunca** `true` en producción) |
| `APP_KEY` | Se genera una sola vez (ver abajo) y se guarda como secreto en Coolify |
| `APP_URL` | `https://crm.fdonbosco.org` (dominio propuesto) |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` | `es` / `en` |
| `APP_REGIONAL_LOCALE` / `APP_CURRENCY` | `es_MX` / `MXN` |
| `APP_TIMEZONE` | `America/Mexico_City` |
| `LOG_CHANNEL` | `stderr` (Coolify muestra los registros) |
| `LOG_LEVEL` | `warning` |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Los del recurso `postgres` |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | Los del recurso `redis` |
| `SESSION_DRIVER` / `CACHE_STORE` / `QUEUE_CONNECTION` | `redis` |
| `SESSION_ENCRYPT` | `true` |
| `SESSION_SECURE_COOKIE` | `true` (la cookie de sesión solo viaja por HTTPS) |
| `AUTH_TEMPORARY_PASSWORD_TTL_HOURS` | `72` (vigencia de una contraseña temporal, ADR-010) |
| `TRUSTED_PROXIES` | `10.0.0.0/8,172.16.0.0/12,192.168.0.0/16` (redes internas de Docker donde vive el proxy de Coolify) |
| `MAIL_*` | Se definen en la fase de comunicaciones |

**Generar `APP_KEY`** (una sola vez, en cualquier equipo con Docker):

```sh
docker run --rm crm-donataria:prod php artisan key:generate --show
```

Si `APP_KEY` cambia, se pierden las sesiones y todo lo cifrado con la clave anterior. Para
rotarla sin perder datos se usa `APP_PREVIOUS_KEYS`.

## 3. HTTPS y dominio — PENDIENTE EXTERNO

1. En el DNS de `fdonbosco.org`, crear un registro `A` de `crm` hacia la IP del servidor Coolify.
2. En el recurso `app`, en *Domains*, poner `https://crm.fdonbosco.org`. Coolify pide el
   certificado de Let's Encrypt automáticamente.
3. Comprobar que `http://` redirige a `https://`.

La aplicación confía en las cabeceras `X-Forwarded-For` y `X-Forwarded-Proto` **solo** si llegan
desde las redes de `TRUSTED_PROXIES`, y así genera URLs `https://`. No confía en
`X-Forwarded-Host` ni `X-Forwarded-Port` (el proxy no cambia el host), para evitar la inyección
de cabecera Host.

## 3.1 Requisitos de PostgreSQL (Fase 1) — PENDIENTE EXTERNO

- Extensión **`unaccent`** (búsqueda sin acentos, ADR-009). Viene en las imágenes oficiales de
  PostgreSQL que usa Coolify. La crea la migración; desde PostgreSQL 13 es *trusted* y basta con que
  el usuario de la aplicación sea dueño de la base.
- Si la extensión no existe, la migración falla con el mensaje *"PostgreSQL no tiene disponible la
  extensión unaccent…"* y el despliegue de `app` no queda sano: usar una imagen oficial de
  PostgreSQL.
- Las migraciones crean funciones y triggers (`f_unaccent`, `donations_no_delete`,
  `campaigns_program_locked`, `audit_logs_append_only`); el usuario necesita permiso para crear
  funciones en su base (lo tiene si es el dueño).

## 4. Almacenamiento persistente — PENDIENTE EXTERNO

El contenedor se reemplaza en cada despliegue. Todo lo que la aplicación escribe en disco y
deba conservarse va en un volumen:

| Volumen | Ruta en el contenedor | Recurso | Para qué |
|---|---|---|---|
| `crm-storage` | `/var/www/html/storage/app` | `app`, `worker` y `scheduler` (el mismo volumen) | Logotipo (`public/`), exportaciones temporales (`private/filament_exports`) recibos simples y los XML/PDF de CFDI externos adjuntos (antecedentes) |

En Coolify: *Persistent Storage → Add volume*. `worker` genera las exportaciones, `app` las entrega
y `scheduler` las purga a los 7 días: los tres deben ver el mismo volumen. El enlace
`public/storage` para el logotipo lo crea `docker/entrypoint.sh` (`storage:link`).
`storage/logs` no necesita volumen porque los registros van a `stderr`.

**`worker` y `scheduler` son obligatorios desde la Fase 1:** sin `worker` las exportaciones se
quedan en espera; sin `scheduler` los archivos exportados no se purgan.

## 5. Orden de despliegue y migraciones

`docker/entrypoint.sh` genera las cachés de configuración, rutas, vistas y eventos en los tres
recursos. **Solo `app` ejecuta `php artisan migrate --force`**; `worker` y `scheduler` nunca migran.

Orden en cada despliegue:

1. Desplegar **`app`** y esperar a que su health check (`/up`) esté en verde. En ese momento las
   migraciones ya corrieron.
2. Desplegar (o reiniciar) **`worker`** y **`scheduler`**, para que usen el código nuevo.

Si `worker` se despliega antes que `app`, puede procesar trabajos con un esquema de base de
datos viejo. Las migraciones deben ser compatibles hacia atrás durante esos segundos.

## 6. Crear el administrador inicial — PENDIENTE EXTERNO

Una sola vez, después del primer despliegue. En Coolify: recurso `app` → *Terminal*:

```sh
php artisan app:create-admin
```

El comando pide nombre, correo (propone `licarroyogarfias@gmail.com`) y la contraseña dos
veces, sin mostrarla. Detalle en [`administrador-inicial.md`](administrador-inicial.md).

Si en el futuro ningún Administrador puede entrar (contraseña olvidada), la recuperación es
`php artisan app:reset-user-password` desde la misma terminal (ver `administrador-inicial.md`).

Después, desde el panel: **Administración → Organización** (datos fiscales, autorización y aviso
de privacidad con URL y versión) y **Administración → Usuarios** para dar de alta al resto del
personal con su rol.

**Nunca** ejecutar `db:seed` en producción: los datos de demostración solo se cargan en `local` y
`testing`, y el seeder no crea usuarios utilizables.

## 7. Verificación después de desplegar — PENDIENTE EXTERNO

| Prueba | Resultado esperado |
|---|---|
| `https://crm.fdonbosco.org/up` | 200 |
| `https://crm.fdonbosco.org/admin` sin sesión | Redirige a `/admin/login` |
| Inicio de sesión del administrador | Entra al panel |
| Registros de `worker` | `Processing jobs from the [default] queue` sin errores |
| Registros de `scheduler` | Sin errores |
| Certificado | Válido, emitido por Let's Encrypt |

## 8. Rollback básico — PENDIENTE EXTERNO

1. En el recurso `app` → *Deployments*, elegir el despliegue anterior que funcionaba y usar
   **Redeploy** (o volver a desplegar el commit anterior de `main`).
2. Hacer lo mismo con `worker` y `scheduler`, con el mismo commit.
3. **Migraciones:** el rollback de código no revierte la base de datos. Si el despliegue fallido
   agregó migraciones incompatibles con el código anterior, desde la terminal de `app`:
   `php artisan migrate:rollback --step=N` (N = número de migraciones nuevas). Todas las
   migraciones del proyecto son reversibles, pero si alguna borra datos, restaurar el respaldo.
4. **Nunca** ejecutar `migrate:fresh` ni `db:wipe` en producción.

## 9. Respaldos — PENDIENTE EXTERNO

Recomendación: dejarlos activos antes de la Fase 2 (pagos), porque desde entonces la base
tendrá datos reales.

- `postgres`: en Coolify → *Backups*, respaldo diario programado con retención de al menos 30
  días, enviado a un almacenamiento S3 externo al servidor.
- Volumen `crm-storage`: respaldo periódico al mismo destino (recibos y CFDI externos adjuntos).
- Probar una restauración completa al menos una vez antes de salir a producción.
