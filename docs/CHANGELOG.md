# Cambios

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).

## [Sin publicar]

### Agregado
- Estructura de documentación, `CLAUDE.md` y ADR-001 (stack).
- Proyecto Laravel con las versiones iniciales:

  | Componente | Versión |
  |---|---|
  | PHP | 8.5.10 |
  | Laravel | 13.33.0 |
  | Filament | 5.8.4 |
  | Pest | 5.2.1 (plugin Laravel 5.0.1) |
  | Larastan | 3.12.2 (nivel 8) |
  | Laravel Pint | 1.32.1 |
  | PostgreSQL | 18.6 |
  | Redis | 8 |
  | Node (solo compilación de assets) | 24 |

- `Dockerfile` multietapa (`dev` y `prod`), `docker-compose.yml` con `app`, `worker`, `scheduler`,
  `postgres` y `redis`, y `docker/entrypoint.sh` para producción.
- Panel de Filament en `/admin` con los colores de la Fundación.
- Configuración regional `es` / `es_MX` y zona horaria `America/Mexico_City`.
- Pruebas en PostgreSQL (`crm_testing`), Pint con `declare(strict_types=1)` y Larastan nivel 8.
- CI de GitHub Actions: Pint, Larastan, Pest y construcción de la imagen de producción.
- Guías `docs/tecnico/entorno-local.md` y `docs/tecnico/despliegue-coolify.md`.
- Roles `Administrator`, `FundraisingCoordinator`, `Accountant` y `ReadOnly` (`App\Enums\Role`),
  columna `users.role` y acceso al panel por rol (`User` implementa `FilamentUser`). ADR-002.
- Comando `php artisan app:create-admin` y acción `App\Actions\Users\CreateAdministrator`
  (contraseña oculta, mínimo 12 caracteres con letras y números, rechaza correos duplicados).
- Traducciones propias en `lang/es` (autenticación, validación, contraseñas y paginación).
- Formato regional `es_MX` y moneda `MXN` para `Illuminate\Support\Number`; `intl.default_locale=es_MX`.
- Proxies de confianza para el HTTPS de Coolify (`TRUSTED_PROXIES`; solo `X-Forwarded-For` y `X-Forwarded-Proto`).
- Health check de `app` en Docker Compose; `worker` y `scheduler` esperan a que `app` esté sano.
- Manual de usuario: acceso al panel. Guía técnica del administrador inicial.

- Prueba del formulario de login de Filament (`tests/Feature/Auth/LoginFormTest.php`).
- Protección en `tests/TestCase.php`: las pruebas se detienen si la base no es `crm_testing`.

### Corregido
- Bajo Docker Compose, Pest usaba la base de desarrollo porque las variables del contenedor
  (`$_SERVER`) ganaban a `<env>` de `phpunit.xml`; ahora cada variable también se fija con `<server>`.
- El health check de `app` en Compose daba "healthy" con la aplicación rota; ahora exige el
  texto "Application up" de `/up`.

### Cambiado
- `APP_FALLBACK_LOCALE` pasa a `en` como último recurso técnico; la interfaz usa `lang/es`.
- `docs/tecnico/despliegue.md` se renombra a `despliegue-coolify.md` y se completa.
- `CLAUDE.md`: roles oficiales según el prompt maestro (se descartan "Gestores" y "Voluntario recolector").

### Eliminado
- `laravel/pao`, `concurrently`, `@laravel/multiplex` y los scripts `composer setup`/`composer dev`.
- Usuario de prueba `test@example.com` del seeder.
