# Entorno local de desarrollo

Todo corre en Docker; en el equipo solo se necesitan Git y Docker Desktop.
No hace falta instalar PHP, Composer ni Node.

## Requisito previo: compartir la carpeta del proyecto

Docker Desktop debe poder montar la carpeta del proyecto en los contenedores:

1. Abrir Docker Desktop → **Settings → Resources → File sharing**.
2. Agregar `C:\Users\carlo\proyectos` (o la carpeta donde viva el repositorio).
3. **Apply & restart**.

Sin este paso, `docker compose up` falla con el error *"the path … is not shared from the host"*.

**Si la interfaz no guarda el cambio** (pasó con el motor libkrun): cerrar Docker Desktop
(`docker desktop stop`), agregar en `%APPDATA%\Docker\settings-store.json` la línea
`"FilesharingDirectories": ["C:\\Users\\carlo\\proyectos\\crm-donataria"],` y volver a abrirlo
(`docker desktop start`). Comprobar con:

```powershell
docker run --rm -v "C:\Users\carlo\proyectos\crm-donataria:/w:ro" alpine ls /w
```

## Primer arranque

```sh
cp .env.example .env                                   # y poner APP_KEY (ver abajo)
docker compose build
docker compose run --rm --no-deps app composer install
docker compose run --rm --no-deps app php artisan key:generate
docker compose up -d
docker compose exec app php artisan migrate
docker compose exec app php artisan app:create-admin   # contraseña oculta
```

- Panel interno: <http://localhost:8000/admin>
- Página pública: <http://localhost:8000>

## Servicios

| Servicio | Qué hace | Arranca cuando |
|---|---|---|
| `postgres` | Base de datos (`crm` y `crm_testing`) | Primero; health check `pg_isready` |
| `redis` | Sesiones, caché y colas | Primero; health check `redis-cli ping` |
| `app` | Apache + PHP en el puerto 8000 | `postgres` y `redis` sanos; health check `GET /up` |
| `worker` | `queue:work` (colas en Redis) | `app` sano |
| `scheduler` | `schedule:work` | `app` sano |

En desarrollo nadie migra automáticamente: `migrate` se ejecuta a mano (arriba).
`worker` y `scheduler` nunca migran, ni en desarrollo ni en producción.

## Comandos frecuentes

| Tarea | Comando |
|---|---|
| Levantar / detener | `docker compose up -d` / `docker compose down` |
| Estado y salud | `docker compose ps` |
| Consola en la app | `docker compose exec app bash` |
| Pruebas | `docker compose exec app vendor/bin/pest` |
| Formato | `docker compose exec app vendor/bin/pint` |
| Análisis estático | `docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G` |
| Crear administrador | `docker compose exec app php artisan app:create-admin` |
| Registros | `docker compose logs -f app worker scheduler` |

## Bases de datos

El contenedor `postgres` crea dos bases la primera vez que arranca:

- `crm`: desarrollo.
- `crm_testing`: la usa Pest. `phpunit.xml` la fija con `<env>` y `<server>`, porque Compose
  entrega el `.env` como variables del contenedor (`$_SERVER`), que Laravel lee primero. Además,
  `tests/TestCase.php` detiene las pruebas si la base no es `crm_testing`.

Si el volumen ya existía antes de agregar el script `docker/postgres/init/`, se recrea con
`docker compose down -v` (borra los datos locales).

`migrate:fresh` solo se usa contra estas bases locales. Nunca contra producción.

## Datos de demostración

`docker compose exec app php artisan migrate:fresh --seed` carga datos **ficticios** (programas,
campañas, donantes con correos `@example.*`, sin teléfonos, RFC con prefijo `ZZ`, y donativos en
los tres estados). Los registra un usuario técnico "Datos de demostración", **sin rol, desactivado
y con contraseña aleatoria**: no sirve para entrar.

`migrate:fresh` **borra todos los usuarios**, incluido tu administrador local: vuelve a crearlo con
`app:create-admin`. Para probar migraciones sin perderlo, usa una base desechable:

```sh
docker compose exec postgres psql -U crm -d crm -c "create database crm_validation"
docker compose exec -e DB_DATABASE=crm_validation app php artisan migrate:fresh --seed
```

Faker no incluye `es_MX`: las factories usan `es_ES` para los nombres.

## Logotipo

Para ver el logotipo subido en Organización: `docker compose exec app php artisan storage:link`
(una sola vez). En producción lo hace `docker/entrypoint.sh`.

## Exportaciones

Se procesan en la cola (servicio `worker`) y se guardan en `storage/app/private/filament_exports`.
El servicio `scheduler` las purga a los 7 días.
