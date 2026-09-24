# CRM Donataria — Fundación Don Bosco

CRM para una donataria autorizada en México (Fundación Don Bosco, Cuernavaca, Morelos).
Cubre: felicitación de cumpleaños, pagos en línea únicos y recurrentes, reporte de pagos,
recibo simple y agradecimiento automático, aviso a Contabilidad y CFDI externo como antecedente.
**El CRM no emite CFDI** (ADR-012): la contadora los emite fuera del sistema.

El documento de requisitos completo lo entregó el usuario al iniciar el proyecto; este archivo es la memoria operativa.

## Estado actual

- **Fase 0 — Cimientos: cerrada** (2026-09-22). Ver `docs/fases/FASE-00-resumen.md`.
- **Fase 1 — Núcleo del CRM: cerrada** (2026-09-22). Ver `docs/fases/FASE-01-resumen.md`.
- **Fase 2 — Pagos en línea (Stripe y Mercado Pago): implementada y validada con FakeGateway**
  (2026-09-23). **Falta el sandbox real** de ambos proveedores (puntos [S]) y la aprobación del
  usuario. Ver `docs/fases/FASE-02-resumen.md`, `docs/tecnico/fase-2-diseno-pagos.md` (fuente de verdad)
  e `integraciones-pagos.md`. No marcar un [S] como [V] solo porque el código compile.
- **Fase 2: bloque implementable cerrado administrativamente (2026-09-23)**. Sandbox abierto. Pausa `void` aprobada provisionalmente y límites en `null`.
- **CFDI — cambio de alcance (2026-09-23, ADR-012): el CRM NO emite, timbra, cancela ni sustituye CFDI** y no llama a ningún PAC.
  - Facturapi quedó retirado y sin uso: no hay código, configuración ni variables. `fase-3-cfdi.md` es solo historial.
  - Fuente de verdad: `docs/tecnico/cfdi-externo.md`.
  - Al confirmar cada donativo, `RunConfirmedDonationSteps` corre tres pasos independientes e idempotentes: recibo simple → agradecimiento con el recibo → aviso a Contabilidad ("CFDI solicitado: SÍ/NO", con los datos fiscales si es SÍ).
  - Control contable (`/admin/control-contable`): cola de Contabilidad (solicitado, adjunto, procesado).
  - CFDI externo: XML obligatorio y PDF opcional, adjuntos al donativo como antecedente (`external_cfdis`). La lectura del XML es segura (sin DOCTYPE ni entidades).
  - El CRM no decide la factura global ni clasifica donativos. No inventar reglas fiscales.
  - Historial conservado en la base: `cfdis`, `global_cfdis`, `fiscal_incidents`, columnas `fiscal_*` e `in_kind_*`. No borrarlos.
- **Fase 4 — Comunicaciones: implementada con `Mail::fake()`** (2026-09-23): recibo simple, agradecimiento, cumpleaños, plantillas, historial y baja. El envío de CFDI quedó como tipo histórico (`CommunicationKind::Cfdi`).
  - **Correo saliente administrable** (2026-09-23): SMTP estándar configurado por el Administrador en el panel (`docs/tecnico/correo-saliente.md`). Falta el proveedor real en producción (#32) y los rebotes (#33).
  - Fuente de verdad: `docs/tecnico/fase-4-comunicaciones.md`.
  - Sin paquetes nuevos: el PDF del recibo usa `App\Support\SimplePdf`, y las plantillas usan `TemplateRenderer` (nunca Blade).
- **Fase 5 — Tablero y reportes: implementada** (2026-09-23), sin migraciones.
  - Los cálculos viven en `app/Reports` (`DashboardMetrics`, `PaymentReport`, `AccountingControl`), nunca en widgets ni pantallas.
  - Definiciones: `docs/tecnico/fase-5-reportes.md`.
- **Fase 6 — Página pública: cerrada** (2026-09-23), validada con FakeGateway.
  - Stripe y Mercado Pago quedan en [S].
  - Cerrada el 2026-09-23 (`docs/fases/FASE-06-resumen.md`); migración de `donors.origin` aplicada a `crm`.
  - Build local de assets en Windows: `node node_modules/vite/bin/vite.js build` (libkrun no guarda enlaces simbólicos).
  - Fuente de verdad: `docs/tecnico/fase-6-pagina-publica.md`.
  - El controlador público solo orquesta (`app/PublicDonations`); pagos y comunicaciones siguen en sus Actions.
  - "Quiero mi comprobante fiscal" se guarda en `payments`/`subscriptions.tax_receipt_requested` y pasa al donativo.
- **Fase 7 — Operación, seguridad y rendimiento:** cerrada localmente (2026-09-23). Ver `docs/fases/FASE-07-resumen.md` y `docs/tecnico/backup-restore-local.md`.
  - Pest 611 pruebas / 2487 aserciones, Pint 421 archivos y Larastan nivel 8 sin errores.
  - Build frontend e imagen `prod` validados con Docker; backup/restore PostgreSQL local probado en bases desechables.
  - No declara producción validada. Stripe, Mercado Pago, SMTP, rebotes, S3, dominio, Coolify y credenciales productivas siguen `[S]`.
- **Base `crm`:** tiene datos persistentes de desarrollo. Se permiten `migrate` normales (con respaldo si hay riesgo).
  Nunca `migrate:fresh`, rollback destructivo ni experimentos contra `crm`; usar `crm_testing` o `crm_validation`.
- Modelo de datos y reglas: `docs/tecnico/modelo-de-datos.md` y ADR-002 a ADR-012.
- Las decisiones fiscales (uso de CFDI, régimen, especie, factura global) las toma Contabilidad fuera del CRM. No codificarlas.
- Docker Desktop con motor libkrun (no WSL2). La carpeta del proyecto se comparte mediante
  `FilesharingDirectories` en `%APPDATA%\Docker\settings-store.json` (la interfaz no lo guardaba).
- Pruebas: siempre en `crm_testing`. `tests/TestCase.php` las detiene si apuntan a otra base.
- Repositorio remoto: https://github.com/CarlosArroyoG/CRMDona (`origin`). Push, merge y rebase solo con autorización explícita.
- Resúmenes de fases: `docs/fases/`.

## Stack

- Laravel (última estable) + PHP (última soportada por Laravel y Filament).
- Filament (última mayor estable) para el CRM interno.
- Blade + Tailwind CSS para la página pública de donación.
- PostgreSQL (datos) y Redis (colas y caché).
- Pest (pruebas), Laravel Pint (formato), Larastan nivel ≥ 8.
- Docker (Dockerfile propio) para Coolify con 5 recursos: `app`, `worker`, `scheduler`, `postgres`, `redis`.
- GitHub Actions: Pint, Larastan, Pest y construcción de la imagen `prod` en cada push.
- Del esqueleto de Laravel se conservan `laravel/tinker` y `laravel/pail` (ADR-001).
- `stripe/stripe-php ^21.3` (ADR-011), usado solo en `app/Payments/Gateways/Stripe`.
- Cualquier paquete fuera de esta lista requiere ADR y autorización del usuario.

## Datos de la organización

- Nombre corto: Fundación Don Bosco — sitio: https://www.fdonbosco.org/
- Colores: primario `#162562` (azul marino), secundario `#FF9D2F` (naranja). Configurables.
- Logo: https://www.fdonbosco.org/theme/img/logo.png (configurable).
- Dominios propuestos: `crm.fdonbosco.org` (panel) y `donar.fdonbosco.org` (página pública). Servidor Coolify: por definir.
- Administrador inicial: `licarroyogarfias@gmail.com`. **La contraseña nunca se escribe en código, seeders, `.env.example` ni git**; se captura con comando interactivo.

## Roles y autorización (ADR-002)

Roles oficiales, según el prompt maestro original, que es la fuente de verdad:

| Rol | Valor en `users.role` | Enum |
|---|---|---|
| Administrador | `administrator` | `Role::Administrator` |
| Coordinador de procuración de fondos | `fundraising_coordinator` | `Role::FundraisingCoordinator` |
| Contador | `accountant` | `Role::Accountant` |
| Solo lectura | `read_only` | `Role::ReadOnly` |

- Un rol por usuario: columna `users.role` + enum `App\Enums\Role`, sin paquetes de permisos.
- **Matriz única** en `App\Enums\Permission` (tabla aprobada en ADR-002 y en
  `tests/Unit/Enums/PermissionMatrixTest.php`); Policies por módulo la consultan y agregan reglas de estado.
- Acceso al panel: `User::canAccessPanel()` = tiene rol y está activo (los cuatro roles entran).
- Módulos nuevos: agregar sus permisos a la matriz y su Policy junto con el módulo.
- Quedan sin efecto los roles "Gestores" y "Voluntario recolector" de versiones anteriores de este archivo.

## Reglas de dominio (Fase 1)

- `Donation` ≠ `Payment` ≠ `DonationReceipt` ≠ `ExternalCfdi` ≠ `AccountingNotice` (ADR-005, ADR-012). Las fases futuras agregan tablas propias que apuntan a `donations`.
- Donativos: siempre nacen `pending`; `confirmed` no se edita; errores → cancelar con motivo. Nunca se eliminan (trigger).
- Destino único del donativo: campaña, programa o fondo general. Programa para reportes = directo o el de la campaña.
- Auditoría: cada modelo declara `auditValueFields()` y `auditNameOnlyFields()`; datos personales/fiscales sin valor (ADR-006).
- Escribir datos de negocio solo mediante `app/Actions` (los `update` masivos no se auditan).
- Búsquedas de texto con `App\Support\Search::unaccent()` (lista cerrada de columnas).
- Contraseñas (ADR-010): el Administrador restablece la de otros con una temporal generada por el sistema (mostrada una vez,
  solo hash). `users.password_change_required_at` obliga a cambiarla (middleware persistente `EnsurePasswordIsCurrent`) y vence a
  las `auth.temporary_password_ttl_hours` (72). Con temporal, `hasPermission()` es falso. Nunca auditar contraseñas ni hashes.

## Reglas de pagos (Fase 2, ADR-011)

- Separación: `Donation ≠ Payment ≠ PaymentAttempt ≠ Subscription ≠ Refund ≠ PaymentDispute ≠ DonationReceipt ≠ Cfdi`.
  Marca, últimos 4 dígitos y códigos de rechazo viven en el intento, nunca en el donativo.
- **Integraciones:**
  - Solo detrás de `app/Payments` (contratos por capacidad y `GatewayRegistry`); ninguna Action ni pantalla usa un SDK.
  - Stripe con `stripe/stripe-php`; Mercado Pago con el cliente HTTP de Laravel (su SDK no está autorizado).
  - `FakeGateway` para todas las pruebas: las pruebas nunca llaman APIs reales (`phpunit.xml` fuerza las llaves vacías).
- **Pagos y donativos:**
  - `Payment 1 → N PaymentAttempt`; cada mensualidad es un Payment. Solo un Payment `succeeded` crea un Donation (`origin = online`, `payment_id` único, sin actor humano).
  - No existe usuario "sistema": la procedencia está en `audit_logs.source` y en `webhook_events`.
- **Webhooks:** firma → guardado único (lista permitida) → cola → consulta del estado actual → aplicación con bloqueo. Nunca mantener una transacción abierta durante una llamada HTTP.
- **Reintentos:** los hace el proveedor (`retry_owner = provider`); el CRM no tiene scheduler de cobros. Un intento fallido nunca cancela una suscripción.
- **Reembolsos:** motivo obligatorio del catálogo; reservan saldo los `pending` y `succeeded` (Action + trigger). Nunca cancelan el donativo.
- **Incidencias:** `dedupe_key` por hecho concreto. Coordinador: solo operativas. Alertas: todo Administrador, más Coordinador o Contador con `receives_payment_alerts`. Leer ≠ resolver.
- **Sanitización:** `App\Support\SensitiveData`. Nunca PAN, CVV, secretos, `Authorization`, firmas ni payloads crudos en base de datos, logs, bitácora o exportaciones.
- **Credenciales:** solo en `.env` o en las variables de Coolify. Nunca pedir llaves por chat.
- **Concurrencia:** `tests/Concurrency` usa procesos reales (`tests/Support/race.php`, excluido de la imagen) y limpia sus tablas; no usa `RefreshDatabase`.

## Reglas de CFDI externo y Contabilidad (ADR-012)

- El CRM nunca genera CFDI ni muestra "Generar CFDI", "Timbrar" o similares. Nada espera un CFDI: el agradecimiento sale con el recibo.
- Aviso a Contabilidad: uno por donativo (`accounting_notices`). Destinatarios: `accounting.process` (A, Co) + `users.receives_accounting_notices`. Los datos fiscales van solo en ese correo, nunca en la tabla, la bitácora, el recibo ni el correo al donante.
- CFDI externo: `AttachExternalCfdi` / `RemoveExternalCfdi` (`cfdi.manage`). XML leído con `CfdiXmlReader` (rechaza DOCTYPE y ENTITY, `LIBXML_NONET`). Disco privado con nombre generado; descarga autorizada (`external-cfdi.files`). Retirar ≠ borrar.
- Adjuntar un CFDI no marca el donativo como procesado; lo marca Contabilidad (`SetAccountingProcessed`).

## Reglas de correo saliente

- Solo `App\Mail\Outgoing\OutgoingMailConfig` decide el mailer: SMTP del panel (`mail_settings`, mailer `crm`) o `MAIL_*` como respaldo. Ningún Mailable ni Notification conoce credenciales.
- La contraseña SMTP es `encrypted`, oculta y nunca se registra (solo el contexto "reemplazada" o "eliminada"). Vacía en el formulario = conservar.
- El worker vuelve a comprobar `mail_settings.version` antes de cada Job; guardar sube la versión. Nada de lógica SMTP dentro de los Mailables.
- En las pruebas, `SmtpTransportFactory` se sustituye por `Tests\Support\RecordingSmtpFactory`: nunca se usa la red.

## Convenciones

- Código, clases, tablas y columnas en **inglés**. Interfaz, textos, correos y documentación en **español de México**.
- Idioma `es` (textos en `lang/es`), formato regional `es_MX`, zona horaria `America/Mexico_City`, moneda `MXN`.
- Montos: nunca `float`/`double`; `numeric(12,2)` en PostgreSQL, strings + bcmath en PHP (`App\Support\Money`, ADR-004).
- Los CHECK de PostgreSQL que deben fallar se prueban dentro de `DB::transaction()` (savepoint).
- Commits en español con prefijos `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `chore:`.
- Repositorio: https://github.com/CarlosArroyoG/CRMDona.

## Reglas contra la deuda técnica

1. Nada de código muerto ni `TODO` sueltos; lo pendiente va en `docs/pendientes.md` con su fase objetivo.
2. Cada funcionalidad lleva pruebas (feature para flujos, unit para dominio). Nada se cierra en rojo.
3. Pint, Larastan y Pest al 100% antes de cerrar una fase.
4. Lógica de negocio en `app/Actions`, servicios y Jobs; nunca en controladores ni Filament Resources.
5. Integraciones externas detrás de interfaces (`PaymentGateway`…) con driver real y driver fake. Las pruebas nunca llaman APIs reales.
6. Idempotencia en todo lo externo (webhooks, timbrado).
7. Secretos solo en variables de entorno; `.env.example` siempre actualizado y documentado.
8. Migraciones reversibles; seeders con datos de demostración realistas y ficticios.
9. Reutilizar lo existente; si se cambia, refactorizar y documentar por qué.
10. Refactorización al final de cada fase.

## Forma de trabajar por fases

- Al iniciar una fase: leer este archivo y el último `docs/fases/FASE-XX-resumen.md`.
- Al cerrarla: cumplir la Definición de terminado, escribir el resumen de fase, actualizar `CHANGELOG.md` y este archivo, mostrar el resumen y **esperar aprobación**.
- Antes de la Fase 2 preguntar la pasarela de pago. No hay PAC: el CRM no emite CFDI (ADR-012).
- Decisiones de negocio ambiguas: preguntar, no asumir.

## Comandos útiles

No hay PHP ni Composer en el equipo: todo corre en Docker. Detalle en `docs/tecnico/entorno-local.md`.

- `docker compose up -d` — levanta app (http://localhost:8000), worker, scheduler, postgres y redis.
- `docker compose exec app php artisan app:create-admin` — crea un administrador (contraseña oculta).
- `docker compose exec app php artisan app:reset-user-password` — recupera el acceso de un usuario existente (temporal, oculta, sin cambiar rol).
- `docker compose exec -e DB_DATABASE=crm_validation app php artisan migrate:fresh --seed` — datos de demostración en una base desechable (`migrate:fresh` en `crm` borra tu administrador local).
- `docker compose exec app vendor/bin/pest` — pruebas (usan la base `crm_testing`; en serie: `--parallel` no aplica por la protección de la base).
- `docker compose exec app vendor/bin/pest tests/Concurrency` — solo las condiciones de carrera (procesos reales).
- `docker compose exec app vendor/bin/pint` — formato.
- `docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G` — Larastan.
- `docker build --target prod -t crm-donataria:prod .` — imagen de producción (Apache en el puerto 8080).
