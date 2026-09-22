# Entorno local de desarrollo

Todo corre en Docker; en el equipo solo se necesitan Git y Docker Desktop.
No hace falta instalar PHP, Composer ni Node.

## Requisito previo: compartir la carpeta del proyecto

Docker Desktop debe poder montar la carpeta del proyecto en los contenedores:

1. Abrir Docker Desktop → **Settings → Resources → File sharing**.
2. Agregar `C:\Users\carlo\proyectos` (o la carpeta donde viva el repositorio).
3. **Apply & restart**.

Sin este paso, `docker compose up` falla con el error *"the path … is not shared from the host"*.

## Primer arranque

```sh
cp .env.example .env
docker compose build
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose up -d
docker compose exec app php artisan migrate
```

- Panel interno: <http://localhost:8000/admin>
- Página pública: <http://localhost:8000>

## Comandos frecuentes

| Tarea | Comando |
|---|---|
| Levantar / detener | `docker compose up -d` / `docker compose down` |
| Consola en la app | `docker compose exec app bash` |
| Pruebas | `docker compose exec app vendor/bin/pest` |
| Formato | `docker compose exec app vendor/bin/pint` |
| Análisis estático | `docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G` |
| Assets de Vite | `docker run --rm -v "$PWD:/app" -w /app node:24-alpine npm run build` |
| Registros | `docker compose logs -f app worker scheduler` |

## Bases de datos

El contenedor `postgres` crea dos bases la primera vez que arranca:

- `crm`: desarrollo.
- `crm_testing`: la usa Pest (`phpunit.xml` fuerza esa base para que las pruebas nunca toquen `crm`).

Si el volumen ya existía antes de agregar el script `docker/postgres/init/`, se recrea con
`docker compose down -v` (borra los datos locales).
