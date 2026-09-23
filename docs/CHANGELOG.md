# Cambios

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).

## [Fase 2 — Pagos en línea] — 2026-09-23 (sin validación en sandbox)

### Agregado
- **Modelo de pagos** (`fase-2-diseno-pagos.md` v3, ADR-011):
  - `subscriptions`, `payments` (un cobro; cada mensualidad es uno), `payment_attempts`, `refunds`, `payment_disputes`, `webhook_events`, `payment_incidents` y `payment_incident_notes`;
  - restricciones en PostgreSQL: llaves únicas, CHECKs, el trigger `refunds_within_payment_amount` y notas de solo inserción.
- **Donativos en línea:**
  - `donations.origin` (`manual` / `online`) y `donations.payment_id` único;
  - CHECKs de origen; sin usuario "sistema";
  - un pago exitoso crea como máximo un donativo, ya confirmado.
- **Pasarelas por capacidad** (`app/Payments`):
  - `GatewayRegistry`, contratos, snapshots y `FakeGateway` determinista;
  - adaptador de **Stripe** con `stripe/stripe-php` v21.3.2 (Checkout embebido, Smart Retries, reembolsos, disputas);
  - adaptador de **Mercado Pago** con el cliente HTTP de Laravel (Orders, `/preapproval` sin plan, reembolsos, contracargos, firma `x-signature`).
- **Bandeja de webhooks** (`POST /webhooks/payments/{proveedor}`):
  - firma y guardado único con lista permitida;
  - procesamiento en cola que consulta el estado actual;
  - protección ante eventos duplicados, simultáneos, fuera de orden y reintentos.
- **Reembolsos:** motivo obligatorio (catálogo más comentario) y defensa en profundidad (Action, bloqueo, idempotencia y trigger). Nunca cancelan el donativo.
- **Incidencias (RF-01):**
  - estados Nueva → En revisión → Resuelta, con `dedupe_key` por hecho y notas de solo inserción;
  - alertas en la campana: Administradores, y Coordinadores y Contadores con la preferencia activa.
- **Conciliación programada** (`ReconcilePayments`, cada 15 minutos).
- **Límites de negocio** de donativos en línea (mínimo y máximo configurables); se aplica el más restrictivo junto con el técnico del proveedor.
- **Bitácora:** `audit_logs.source` (usuario, webhook, proceso automático, sincronización, consola) y eventos de pausa, reanudación e incidencias.
- **Sanitización central** (`SensitiveData`): lista permitida para webhooks y redacción en todos los canales de log.
- **Pantallas de Filament:**
  - Pagos en línea (con intentos, reembolsos y disputas), Donativos mensuales (pausar, reanudar, cancelar), Incidencias, Reembolsos, Disputas, Bandeja de webhooks y Pasarelas de pago;
  - preferencia de alertas en Usuarios y límites en Organización.
- **Permisos de la Fase 2** en la matriz (ADR-002).
- **Pruebas de concurrencia** con procesos reales contra PostgreSQL (`tests/Concurrency`).
- **Documentación:** `docs/tecnico/integraciones-pagos.md` (con instrucciones de sandbox), ADR-011 y manual de usuario (páginas 09 a 11).

### Cambiado
- `donations.payment_method` → `manual_payment_method` (enum `ManualPaymentMethod`); los donativos existentes quedan `origin = manual` sin perder su forma de pago.
- `donations.registered_by_id` acepta nulo solo en donativos en línea.
- `Auditable::auditAs()` acepta un contexto seguro (por ejemplo, el motivo de una pausa).
- La etiqueta de bitácora `type` pasa a "Tipo" (la comparten donantes e incidencias).

## [Fase 1 — Núcleo del CRM] — 2026-09-22

### Agregado
- **Configuración de la organización** (fila única): datos fiscales, autorización como donataria,
  leyenda, logotipo, firma de correo y aviso de privacidad (URL + versión).
- **Donantes** (persona física/moral con reglas en base de datos), datos fiscales 1:1 opcionales,
  etiquetas, consentimientos separados (aviso de privacidad con versión y fecha; comunicaciones),
  aviso de duplicados por correo o RFC, archivar/reactivar. ADR-003.
- **Programas** y **campañas** (`Program 1 → N Campaign`), identificador para enlaces futuros, meta
  y vigencia. Una campaña con donativos no cambia de programa (Action + trigger).
- **Donativos manuales**: efectivo, transferencia, cheque, depósito y especie; destino único
  (campaña, programa o fondo general); flujo `Por confirmar → Confirmado / Cancelado` con
  trazabilidad; importes `numeric(12,2)` con bcmath. ADR-004 y ADR-005.
- **Usuarios** (solo Administrador): alta con rol, cambio de rol, desactivar/reactivar; siempre
  queda un Administrador activo. Cambio de la propia contraseña para todos los roles.
- **Permisos por rol**: matriz única `App\Enums\Permission` + Policies por módulo. Los cuatro roles
  entran al panel. ADR-002 actualizado.
- **Bitácora de auditoría** propia, con lista cerrada de campos por modelo (datos personales y
  fiscales sin valor), eventos de negocio y tabla de solo inserción (trigger). ADR-006.
- **Búsqueda sin acentos** con `unaccent` + `f_unaccent`. ADR-009.
- **Exportación CSV (UTF-8 con BOM) y XLSX** nativa de Filament, en cola, descarga solo para quien
  la generó y purga del archivo a los 7 días. ADR-007.
- **Conservación**: los donativos nunca se eliminan (trigger); donantes y destinos con historial
  tampoco (FK `restrict`). ADR-008.
- Seeder de demostración ficticio (solo local/testing) sin credenciales utilizables.
- Manual de usuario por módulo, guía del modelo de datos, ADR-003 a ADR-009.
- **Restablecimiento de contraseñas** (ADR-010): acción "Restablecer contraseña" para el
  Administrador (sobre otros usuarios) con contraseña temporal generada por el sistema, mostrada
  una sola vez, cambio obligatorio al entrar (middleware persistente `EnsurePasswordIsCurrent`),
  vigencia configurable (`AUTH_TEMPORARY_PASSWORD_TTL_HOURS`, 72 h), cierre de las demás sesiones y
  evento de bitácora sin secretos. Comando `app:reset-user-password` para recuperar el acceso
  desde el servidor. Migración `users.password_change_required_at`.
- `docs/tecnico/requisitos-fases-futuras.md` con el requisito RF-01 (alertas de pagos y donativos
  con problemas) como dependencia de la Fase 2 y de la fase de comunicaciones.
- 223 pruebas (784 aserciones).

### Cambiado
- `Role::canAccessPanel()` se elimina: el acceso lo decide `User::canAccessPanel()` (rol + activo).
- `CreateAdministrator` reutiliza `CreateUser`; la política de contraseñas vive en `Password::defaults()`.
- `notifications.data` en `jsonb` (Filament la consulta con operadores JSON).
- `routes/console.php`: se quita el comando de ejemplo `inspire`; se programa `model:prune` de exportaciones.

### Corregido
- Las funciones de los triggers usan `create or replace` para que `migrate:fresh` funcione más de una vez.

## [Fase 0 — Cimientos] — 2026-09-22

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
