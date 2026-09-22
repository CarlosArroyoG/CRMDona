# Fase 00 — Cimientos

> **Estado: cerrada** (2026-09-22). Toda la validación local obligatoria pasó con Docker Compose
> real (bind mount). Lo que depende de infraestructura externa sigue como **PENDIENTE EXTERNO** y
> no es una falla de la implementación. La Fase 1 empieza solo con la aprobación del responsable.

## Qué se construyó

**Terminado y verificado localmente**

- Proyecto Laravel 13.33 sobre PHP 8.5, con Filament 5.8 (panel en `/admin`, color `#162562`),
  Pest 5.2, Larastan 3.12 (nivel 8) y Pint (con `declare(strict_types=1)`).
- Docker Compose de desarrollo con `app`, `worker`, `scheduler`, `postgres` y `redis`. Health
  checks: `app` sano solo si `/up` responde "Application up"; `worker` y `scheduler` esperan a `app`.
- `Dockerfile` multietapa: `dev` y `prod`. `prod` corre como `www-data`, Apache en el puerto 8080,
  y solo `app` migra. Construcción limpia (`--no-cache`) verificada.
- Roles oficiales (`Administrator`, `FundraisingCoordinator`, `Accountant`, `ReadOnly`) en
  `users.role` con el enum `App\Enums\Role`. Solo el Administrador entra al panel (`FilamentUser`).
- `php artisan app:create-admin`: el administrador local `licarroyogarfias@gmail.com` se creó con
  este comando e inició sesión en el panel.
- Español en autenticación, validación, contraseñas y paginación (`lang/es`), formato `es_MX`,
  moneda `MXN` y zona horaria `America/Mexico_City`.
- Confianza en el proxy HTTPS de Coolify (solo `X-Forwarded-For` y `X-Forwarded-Proto`).
- Pruebas aisladas en `crm_testing`, con una protección que las detiene si apuntan a otra base.
- Workflow de GitHub Actions `CI` (Pint, Larastan, Pest con PostgreSQL e imagen `prod`).

## Decisiones tomadas

- **ADR-001 (stack):** se conservan `laravel/tinker` y `laravel/pail`; se eliminaron `laravel/pao`,
  `concurrently`, `@laravel/multiplex` y los scripts `composer setup`/`dev`, tras comprobar que
  nada los usaba.
- **ADR-002 (roles):** un rol por usuario en `users.role` + enum propio + Policies de Laravel, sin
  paquetes. Las Policies se crean con cada módulo. Hoy solo el Administrador entra al panel.
- Los roles oficiales son los del prompt maestro; "Gestores" y "Voluntario recolector" quedan sin efecto.
- `app:create-admin` rechaza correos existentes y no modifica usuarios. Contraseña de mínimo 12
  caracteres con letras y números, sin `uncompromised()`.
- `APP_FALLBACK_LOCALE=en` solo como último recurso; una prueba exige que `lang/es` cubra todas
  las claves de Laravel.
- Proxies de confianza: por defecto solo redes privadas; no se confía en `X-Forwarded-Host`/`Port`.
- El seeder no crea usuarios. Los montos futuros serán `decimal`/`numeric`, nunca `float`.
- `phpunit.xml` fija cada variable de pruebas como `<env>` **y** `<server>`: bajo Compose, las
  variables del contenedor llegan a `$_SERVER` y Laravel las lee antes que `$_ENV`.

## Hallazgos de la validación final (corregidos)

| Hallazgo | Impacto | Corrección |
|---|---|---|
| Bajo Compose, Pest usaba la base de desarrollo `crm` (y Redis en vez de `array`/`sync`) porque `<env force>` no sobrescribe `$_SERVER` | Las pruebas podían borrar datos locales. Sin pérdida: `crm` estaba vacía. Causó 3 fallas | `<server force>` en `phpunit.xml` + protección en `tests/TestCase.php` (probada: detiene las pruebas si la base no es `crm_testing`) |
| El health check de `app` daba "healthy" con la aplicación rota (un error fatal de PHP con `display_errors=On` responde 200) | `worker`/`scheduler` arrancaban sin dependencias y se reiniciaban en bucle | El chequeo exige el texto "Application up"; probado con y sin `vendor/` |
| Docker Desktop (motor libkrun) no guardaba File sharing desde la interfaz | Bloqueaba Compose | `FilesharingDirectories` en `%APPDATA%\Docker\settings-store.json` con la carpeta del proyecto |

## Archivos y módulos principales

| Área | Archivos |
|---|---|
| Autorización | `app/Enums/Role.php`, `app/Models/User.php`, `database/migrations/2026_09_22_000000_add_role_to_users_table.php` |
| Administrador inicial | `app/Actions/Users/CreateAdministrator.php`, `app/Console/Commands/CreateAdminCommand.php` |
| Idioma y formato | `lang/es/*.php`, `config/app.php`, `app/Providers/AppServiceProvider.php`, `docker/php/app.ini` |
| Proxy HTTPS | `app/Providers/AppServiceProvider.php`, `config/app.php` (`trusted_proxies`) |
| Panel | `app/Providers/Filament/AdminPanelProvider.php` |
| Pruebas | `phpunit.xml`, `tests/TestCase.php`, `tests/Pest.php` |
| Docker | `Dockerfile`, `docker-compose.yml`, `docker/entrypoint.sh`, `docker/postgres/init/01-testing-database.sh` |
| CI | `.github/workflows/ci.yml` |

## Pruebas

**40 pruebas, 101 aserciones**, ejecutadas con `docker compose exec app vendor/bin/pest` contra
`crm_testing`. Una fila testigo en `crm` sobrevivió a la corrida.

| Archivo | Qué cubre |
|---|---|
| `tests/Unit/Enums/RoleTest.php` | Valores en inglés, etiquetas en español, acceso al panel por rol |
| `tests/Feature/ApplicationBootTest.php` | `/`, `/admin/login`, redirección de `/admin`, configuración regional, PostgreSQL |
| `tests/Feature/Auth/PanelAccessTest.php` | Administrador entra; sin rol y roles sin módulos → 403 (también en producción); visitante → login |
| `tests/Feature/Auth/LoginFormTest.php` | Formulario de Filament: errores en español sin claves crudas, credenciales incorrectas, login correcto |
| `tests/Feature/Users/CreateAdministratorTest.php` | Creación, rol, hash, minúsculas, correo inválido, duplicado, política de contraseña, confirmación |
| `tests/Feature/Console/CreateAdminCommandTest.php` | Flujo interactivo y errores; la contraseña nunca aparece en la salida |
| `tests/Feature/LocalizationTest.php` | Mensajes en español, cobertura completa de claves, `es_MX`/`MXN` |
| `tests/Feature/TrustedProxiesTest.php` | HTTPS detrás del proxy, `X-Forwarded-Host` ignorado, IP pública no confiable |
| `tests/Feature/DatabaseSeederTest.php` | El seeder no crea usuarios |

Las 37 pruebas iniciales pasaron a 40 por las 3 de `LoginFormTest.php`, agregadas para comprobar
el formulario de login real.

## Validación final (2026-09-22)

| Verificación | Resultado |
|---|---|
| `docker compose down` + `up -d --build` | PASS: 5 servicios arriba, 0 reinicios tras un minuto |
| `app` health check | PASS (`healthy`) |
| PostgreSQL | PASS: 18.6, bases `crm` y `crm_testing` |
| Redis | PASS (`PONG`) |
| worker | PASS: procesó un trabajo de la cola Redis (`inspire` → DONE); no migra |
| scheduler | PASS: "No scheduled commands are ready to run"; no migra |
| `migrate:fresh --seed` (base local `crm`, vacía, `APP_ENV=local`) | PASS: 4 migraciones, 0 usuarios creados |
| Migración `role` reversible | PASS: rollback la quita, `migrate` la restaura |
| Pint / Larastan nivel 8 / Pest | PASS / PASS / PASS (40 pruebas, 101 aserciones) |
| `/up`, `/`, `/admin/login` | 200, 200, 200 |
| `/admin` sin sesión | 302 → `/admin/login` |
| Login sin claves crudas (`validation.*`, `auth.failed`) | PASS |
| Administrador real local | PASS: creado con `app:create-admin`, rol `administrator`, hash bcrypt, `GET /admin` → 200 con sesión |
| Build `prod` con `--no-cache` | PASS: `www-data`, `es_MX`, sin `tests/`, Apache válido |

## Documentación generada

- `docs/tecnico/decisiones/ADR-001-stack.md` (actualizado) y `ADR-002-roles-y-autorizacion.md` (nuevo).
- `docs/tecnico/despliegue-coolify.md`, `docs/tecnico/administrador-inicial.md`, `docs/tecnico/entorno-local.md`.
- `docs/manual-usuario/01-acceso-al-panel.md`.
- `CLAUDE.md`, `README.md`, `docs/CHANGELOG.md`, `docs/pendientes.md`.

## Pendientes y riesgos

**PENDIENTE EXTERNO** (no son fallas de la implementación)

- Resultado del CI en GitHub Actions después del push (lo revisa el responsable).
- Servidor Coolify, DNS, dominios y HTTPS.
- Primer despliegue, volumen `crm-storage`, administrador en producción y prueba de rollback.
- Respaldos de PostgreSQL y del volumen (recomendado antes de la Fase 2).

**Riesgos abiertos**

- La imagen `prod` pesa ~1.1 GB por las herramientas de compilación de la etapa `base`; se puede reducir más adelante.
- La imagen `prod` hereda `EXPOSE 80` de la imagen oficial de PHP; en Coolify hay que fijar el puerto 8080.
- Orden de despliegue en Coolify: `app` antes que `worker`/`scheduler` (documentado).
- Docker Desktop usa el motor libkrun, cuya interfaz no guardó File sharing; si se reinstala o se
  cambia de equipo, revisar `docs/tecnico/entorno-local.md`.

## Qué necesita la siguiente fase

- Aprobación del responsable para iniciar la Fase 1.
- La matriz de permisos por módulo de cada rol (del prompt maestro) para los módulos que se
  construyan; cada módulo trae su Policy y, cuando corresponda, habilita el panel a sus roles.

## Cómo probarlo manualmente

```sh
docker compose down
docker compose run --rm --no-deps app composer install      # solo si falta vendor/
docker compose up -d --build
docker compose ps                                           # app "healthy"; worker y scheduler "running"
docker compose exec postgres pg_isready -U crm -d crm
docker compose exec redis redis-cli ping                    # PONG
docker compose exec app php artisan migrate:fresh --seed    # solo la base local `crm`
docker compose exec app php artisan app:create-admin
docker compose exec app vendor/bin/pint --test
docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec app vendor/bin/pest
docker build --no-cache --target prod -t crm-donataria:prod .
```

| Verificación | Esperado |
|---|---|
| <http://localhost:8000/up> | 200 |
| <http://localhost:8000/> | 200 |
| <http://localhost:8000/admin/login> | 200, pantalla "Entre a su cuenta" |
| <http://localhost:8000/admin> sin sesión | Redirige a `/admin/login` |
| Login con el administrador | Entra al tablero de Filament |
| Login con contraseña incorrecta | "Estas credenciales no coinciden con nuestros registros." |

**GitHub Actions** (después del push): workflow **CI**, jobs **"Pint, Larastan y Pest"** y
**"Construir imagen de producción"**, en el commit de cierre de la Fase 0.
