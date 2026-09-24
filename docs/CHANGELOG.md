# Cambios

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).

## [Correo saliente SMTP administrable] — 2026-09-23

### Agregado
- **Administración → Correo saliente** (solo Administrador):
  - SMTP estándar de cualquier proveedor: servidor, puerto, seguridad (STARTTLS, SSL/TLS o ninguna), usuario, contraseña, remitente, Reply-To y tiempo de espera;
  - estado Configurado/Habilitado y última prueba aceptada;
  - guía informativa de SPF, DKIM y DMARC.
- Contraseña SMTP cifrada con Laravel (`encrypted`):
  - nunca vuelve al navegador ni entra en la bitácora;
  - vacía = conservar, con texto = reemplazar, casilla para eliminarla.
- **Resolución central** (`OutgoingMailConfig`): el SMTP del panel tiene prioridad y `MAIL_*` queda como respaldo.
  - Web, worker y scheduler usan la configuración vigente.
  - El worker la vuelve a comprobar antes de cada Job, así que no hace falta redeploy.
- **Correo de prueba**:
  - usa la configuración guardada, sin datos de donantes;
  - límite de 5 cada 10 minutos;
  - errores SMTP traducidos a un mensaje administrativo (conexión, tiempo, autenticación, TLS, remitente, destinatario), sin credenciales;
  - queda en la bitácora.
- **Recuperación de contraseña por correo** (Filament). Al restablecerla se anula la contraseña temporal pendiente.
- Permiso `mail.manage` (Administrador), evento de bitácora `mail_test` y migración reversible `mail_settings`.

### Cambiado
- Los errores de envío guardados en comunicaciones y avisos contables quitan el usuario y la contraseña SMTP.

## [CFDI externo y flujo contable] — 2026-09-23 (ADR-012)

### Cambiado
- **El CRM ya no emite, timbra, cancela ni sustituye CFDI** ni llama a ningún PAC. La contadora emite todos los CFDI fuera del sistema.
- Al confirmar cada donativo (manual, en línea o mensualidad), `RunConfirmedDonationSteps` corre tres pasos independientes e idempotentes:
  - recibo simple;
  - agradecimiento con el recibo, que sale de inmediato y nunca espera un CFDI;
  - aviso a Contabilidad.
- El "Reporte CFDI" se convirtió en **Control contable** (`/admin/control-contable`).
- Permisos:
  - se retiraron `cfdi.issue`, `cfdi.cancel` y `cfdi.view_technical`;
  - `cfdi.manage` (A y Co) adjunta, reemplaza y retira CFDI externos;
  - `accounting.process` (A y Co) recibe avisos y marca el procesamiento contable.
- La página pública guarda "Quiero mi comprobante fiscal" en el pago o la suscripción, y cada mensualidad lo hereda. Ya no promete que el CRM enviará el CFDI.

### Agregado
- **Aviso a Contabilidad** por correo, uno por donativo:
  - folio del recibo, donante, fecha, importe, destino y forma de pago;
  - **CFDI solicitado: SÍ/NO**;
  - si es SÍ, los datos fiscales ya capturados.
  - Solo lo reciben Administradores y Contadores con la preferencia "Recibe avisos a Contabilidad".
  - Es reintentable sin duplicados y queda en la bitácora.
  - Si no hay destinatarios configurados, se envía una alerta a los Administradores.
- **Control contable**:
  - filtros por CFDI solicitado o no, CFDI externo adjunto, procesamiento pendiente o procesado, estado del aviso, fechas y donante;
  - acciones marcar procesado (también en lote), reabrir con motivo y reenviar aviso;
  - exportación sin datos fiscales.
- **CFDI externo como antecedente del donativo**:
  - XML obligatorio y PDF opcional;
  - UUID, fechas y total leídos del XML con un lector seguro (rechaza DOCTYPE y ENTITY, sin red);
  - validación del MIME real, del tamaño y del RFC emisor;
  - disco privado con nombre generado y descarga autorizada con `no-store`;
  - reemplazar y retirar con motivo y bitácora.
- Pantalla del donativo con las secciones "Recibo de donación", "Contabilidad" y "CFDI externo / antecedentes fiscales".
- Migraciones reversibles:
  - `external_cfdis`, que copia los CFDI ya timbrados por el CRM como antecedentes `crm_legacy`;
  - `accounting_notices` y `users.receives_accounting_notices`;
  - `payments` y `subscriptions.tax_receipt_requested`.

### Retirado
- Del código:
  - Facturapi (`FacturapiCfdiProvider`), `FakeCfdiProvider` y `config/cfdi.php`;
  - las variables `CFDI_*` y `FACTURAPI_KEY`;
  - los Jobs de timbrado, cancelación y conciliación, la factura global y su cierre programado;
  - la cobertura y ruta fiscal, las incidencias fiscales, la pantalla CFDI y sus acciones;
  - el envío de CFDI por correo y la espera del agradecimiento (`COMMUNICATIONS_THANK_YOU_DELAY`).
- En la base de datos se conservan como historial `cfdis`, `global_cfdis`, `donation_global_cfdi`, `fiscal_incidents` y las columnas fiscales.

### Pendiente
- Activar en producción a la contadora como destinataria de los avisos (#40).
- Redactar el aviso de privacidad (#41).
- Decidir qué hacer con los donativos confirmados antes del aviso (#42).

## [Fiscal — cobertura CFDI] — 2026-09-23 (sustituido por ADR-012; nunca pasó a Facturapi Test)

### Agregado
- Factura global configurable (`daily`, `weekly`, `monthly`; default operativo `daily`) con cierre idempotente, folio por operación y asociaciones Donation↔global.
- CFDI individual de especie con forma de pago `12` y datos SAT del bien.
- Residencia extranjera explícita con RFC genérico `XEXX010101000`.
- Incidencias fiscales por emisión tardía, reembolso o contracargo con CFDI.
- Configuración obligatoria de leyenda de donataria y visibilidad de ruta/periodo en Filament.

### Cambiado
- Todo donativo confirmado conserva cobertura `individual`, `public_general` o `blocked`; `tax_receipt_requested` sigue siendo informativo.
- Facturapi y FakeCfdiProvider aceptan múltiples conceptos para la factura global.

## [Fase 7 — Operación, seguridad y rendimiento] — 2026-09-23 (cerrada localmente)

### Agregado
- Hardening de seguridad con CSP, HSTS, trusted proxies y protección de comandos destructivos.
- Alertas operativas RF-01 para incidencias de pagos, con notificación CRM, correo idempotente y preferencias por responsable.
- Índices PostgreSQL respaldados por consultas reales.
- Validación documentada de migraciones, backup/restore PostgreSQL local, build frontend e imagen de producción.

### Corregido
- El test de rollback/reapply ahora retrocede las 16 migraciones reales de la ventana Fase 2 a Fase 7.

### Pendiente [S]
- Stripe, Mercado Pago, SMTP, rebotes, S3, dominio HTTPS, Coolify y credenciales productivas. (Facturapi Test quedó retirado por ADR-012.)

## [Fase 6 — Página pública de donativos] — 2026-09-23 (cerrada; Stripe y Mercado Pago sin sandbox)

### Corregido
- Prueba intermitente de `WebhookInboxTest`: el worker de pruebas se detenía por el límite de memoria por defecto (128 MB) al final de la suite. `runQueueWorker()` usa ahora `--memory=4096`.
- Las pruebas de concurrencia muestran la excepción del proceso hijo si fallan.

### Agregado
- **Páginas `/donar` y `/donar/campana/{identificador}`:**
  - Blade + Tailwind, mobile-first y accesibles;
  - identidad de la organización, cantidades sugeridas u otra cantidad, donativo único o mensual;
  - datos del donante, datos fiscales opcionales, aviso de privacidad y consentimiento separado;
  - resumen, estados de carga y página de estado real (confirmado, en proceso, rechazado o fallido).
- **Pagos con las acciones de la Fase 2:**
  - FakeGateway de punta a punta: Payment → Donation → recibo → agradecimiento → CFDI;
  - Checkout embebido de Stripe y Brick de Mercado Pago preparados [S].
- **Donantes desde la página pública:**
  - `donors.origin` y `registered_by_id` nulo con CHECK; procedencia "Donante" en la bitácora;
  - reutilización por correo sin modificar datos y con notas internas; nunca se fusiona.
- **Seguridad:**
  - CSRF, límite por IP, campo trampa y tiempo mínimo;
  - importe, campaña y frecuencia validados en el servidor; tokens de sesión en lugar de IDs.
- **Administración:** enlace público en la ficha de la campaña (con botón para abrirlo) y URL general en Organización.

## [Fase 5 — Tablero y reportes] — 2026-09-23

### Agregado
- **Tablero:**
  - recaudado del mes y comparación contra el mes anterior;
  - donantes nuevos (primer donativo confirmado);
  - donativos mensuales activos;
  - tasa de fallos de pagos;
  - reembolsado del mes;
  - cumpleaños de los próximos 7 días.

  Las definiciones exactas están en `docs/tecnico/fase-5-reportes.md`; los cálculos se hacen en NUMERIC y bcmath.
- **Reporte de pagos:**
  - filtro "Situación" (recuperados y cobros mensuales fallidos) y columna "Recuperado";
  - totales del filtro (importe, cobrado y reembolsado);
  - la exportación incluye "Recuperado tras rechazo" y ya no consulta por fila.
- **Reporte CFDI:**
  - por donativo: CFDI vigente, UUID, ruta fiscal, bloqueo o error y cancelaciones;
  - filtros y exportación, con los permisos de `cfdi.view`.

### Cambiado
- Fase 4: queda aprobada la política de comunicaciones transaccionales (#34). El aviso de privacidad debe reflejarla.

## [Fase 4 — Comunicaciones] — 2026-09-23 (sin servidor de correo real)

### Agregado
- **Recibo simple** (`donation_receipts`):
  - uno por donativo confirmado, con folio `R-000000`;
  - PDF privado que dice que no es factura ni CFDI;
  - descarga con permiso.
- **Agradecimiento automático** al confirmar, idempotente por donativo:
  - adjunta el recibo y, si ya está timbrado, el CFDI;
  - si no, el CFDI se envía una vez al timbrarse, sin repetir el agradecimiento.
- **Felicitación de cumpleaños** diaria a las 09:00 America/Mexico_City:
  - solo con consentimiento, sin archivados;
  - una por donante y año.
- **Plantillas editables** (texto con variables de una lista cerrada; sin Blade), con vista previa y texto predeterminado si una plantilla falla.
- **Historial de envíos** (`communications`):
  - estados en cola, enviando, enviado, fallido, no enviado y rebotado (este último sin integración aún);
  - reintentos de la cola y reenvío manual.
- **Baja sin sesión** con token aleatorio de 256 bits. Queda en la bitácora con la procedencia nueva "Donante".
- Permisos `receipts.view`, `communications.view`, `communications.resend` y `communications.templates`.
- Interruptores de agradecimiento y cumpleaños en Organización.

## [Fase 3 — CFDI] — en curso (Facturapi sin probar en Test)

### Agregado
- Tabla `cfdis` (un CFDI vigente por donativo; evidencia de timbrado y cancelación en CHECKs).
- Contrato `CfdiProvider`, `FakeCfdiProvider` y `CfdiProviderRegistry`.
- **`FacturapiCfdiProvider`**:
  - con el cliente HTTP de Laravel, sin SDK;
  - complemento Donatarias 1.1 como complemento `custom`, con la leyenda en el PDF;
  - reconciliación por `external_id` antes de cada timbrado;
  - cancelación con motivo y sustitución;
  - la llave `sk_live_` solo se acepta en producción.
- Reglas fiscales verificadas en la guía SAT 2026, la RMF 2026 y el esquema de cancelación 2026 (`docs/tecnico/fase-3-cfdi.md`).
- **Cobertura fiscal por donativo** (`ResolveDonationFiscalRoute`): CFDI individual, público en general o bloqueo con motivo; visible en el donativo.
- **Sustitución (motivo 01)**: CFDI nuevo relacionado con 04 y cancelación del original con su UUID. Estado "Descartado" para CFDI rechazados nunca timbrados.
- Forma de pago `04`/`28` en donativos en línea, según `payment_attempts.card_funding`.
- La conciliación emite los donativos confirmados en las últimas 72 h que siguen sin CFDI.
- Timbrado en cola, idempotente, con reintentos y conciliación.
- XML y PDF en disco privado, con descarga solo con permiso.
- Pantalla CFDI (con detalle técnico solo para Administrador y Contador) y acción "Emitir CFDI" en el donativo.
- Permisos `cfdi.view`, `cfdi.view_technical`, `cfdi.issue` y `cfdi.cancel` (aprobados).

### Cambiado
- La emisión ya no depende de que el donante pida comprobante: el SAT obliga a emitir por todo donativo recibido. `CFDI_AUTO_ISSUE` queda en `true` por defecto; solo actúa con un PAC configurado.
- La descripción del concepto registra el propósito del donativo (guía SAT 2026).

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
