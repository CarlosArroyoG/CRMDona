# Fase 2 — Diseño de pagos (Stripe y Mercado Pago)

> **Estado: DISEÑO FINAL v3, APROBADO el 2026-09-22 e IMPLEMENTADO el 2026-09-23 sin sandbox.**
> Los puntos [S] siguen pendientes de confirmar con cuentas de prueba. Ajustes aprobados en la
> autorización de implementación y diferencias encontradas al implementar: §25.
>
> Marcas:
> - **[V]** VERIFICADO EN DOCUMENTACIÓN: confirmado en la documentación oficial vigente (fuentes al final).
> - **[S]** POR CONFIRMAR EN SANDBOX: se valida con cuentas de prueba antes de dar por terminado el adaptador.
> - **[D]** DECISIÓN DE NEGOCIO pendiente.
> - **[APROBADO]** decisión tomada por el responsable.

Separación obligatoria:
`Donation ≠ Payment ≠ PaymentAttempt ≠ Subscription ≠ Refund ≠ PaymentDispute ≠ DonationReceipt ≠ Cfdi`

---

## 0. Decisiones cerradas

| # | Tema | Decisión [APROBADO] |
|---|---|---|
| 1 | Proveedores | Stripe y Mercado Pago, habilitables por separado por configuración. Cada Payment y Subscription guarda su proveedor para siempre. No hay pasarela global fija en el código |
| 2 | Recurrencia de Mercado Pago | **Opción A: Suscripciones `/preapproval` sin plan asociado.** La recurrencia y sus reintentos los gestiona Mercado Pago. Queda condicionada al checklist de sandbox (§16.3). La opción B (cobros iniciados por el comercio) solo se documenta como alternativa futura (§23) |
| 3 | Métodos de pago | Solo tarjeta. OXXO, SPEI y meses sin intereses son ampliaciones futuras (§23) |
| 4 | Frecuencias | `one_time` y `monthly`, con un modelo extensible |
| 5 | Límites de importe | Se separan el límite técnico del proveedor y los mínimos y máximos de negocio configurables. No hay valores predeterminados hasta verificar ambos proveedores (§5.1) |
| 6 | Stripe al agotar reintentos | No cancelar automáticamente; el CRM refleja `past_due` (configuración en §16.1) |
| 7 | Stripe Smart Retries | Política predeterminada de Stripe. No hay scheduler propio ni constantes de intentos en el CRM |
| 8 | Payment y PaymentAttempt | `Payment 1 → N PaymentAttempt`. Cada mensualidad es un Payment distinto y cada intento de cobrarla es un Attempt. Un Payment exitoso origina como máximo un Donation |
| 9 | Reembolsos | Entidad `Refund`. Se quitan `partially_refunded` y `refunded` de `PaymentStatus`. `RefundState` se **calcula**, no se guarda. El motivo es obligatorio y viene de un catálogo interno (§9) |
| 10 | Disputas | Entidad `PaymentDispute`. Una disputa nueva abre una incidencia crítica sin duplicados. No altera Donation, CFDI ni recibo |
| 11 | Incidencias | Estados `new → reviewing → resolved`, notas de solo inserción y `dedupe_key` por **hecho concreto** |
| 12 | Alertas | Todos los Administradores; Coordinadores y Contadores con `receives_payment_alerts`; Solo lectura nunca. Campana de Filament en la Fase 2; correo preparado y activado en la fase de comunicaciones |
| 13 | Donation | `origin`, `payment_id` único, `payment_method → manual_payment_method`, actores nulos según las reglas, CHECKs manual/online y ningún usuario ficticio |
| 14 | SDK | `stripe/stripe-php ^21.3` aprobado (ADR-011), sujeto a las verificaciones de §20. Mercado Pago usa el cliente HTTP de Laravel; su SDK no está autorizado |
| 15 | Credenciales | Solo en `.env` o en la configuración segura del servidor. `.env.example` lleva nombres vacíos. Nunca se piden llaves por chat |
| 16 | Orden de implementación | Por capas, con los adaptadores reales al final (§22) |

---

## 1. Modelo ER

```
Donor 1 ──< Subscription (provider fijo)
Subscription 1 ──< Payment              (kind = recurring_charge; una por periodo)
Donor 1 ──< Payment                     (kind = one_time | recurring_charge)
Payment 1 ──< PaymentAttempt
Payment 1 ──< Refund
Payment 1 ──< PaymentDispute
Payment 1 ── 0..1 Donation              (donations.payment_id ÚNICO)
WebhookEvent ──> 0..1 Payment | Subscription | Refund | PaymentDispute (referencias resueltas)
PaymentIncident ──> Payment | PaymentAttempt | Subscription | Refund | PaymentDispute | WebhookEvent
PaymentIncident 1 ──< PaymentIncidentNote (solo inserción)
Program / Campaign ── destino único en Subscription, Payment y Donation
```

Ejemplo aprobado:
```
Subscription
  └─ Payment enero    ├─ Attempt 1 failed
                      └─ Attempt 2 succeeded ──► Donation
  └─ Payment febrero  └─ Attempt 1 succeeded ──► Donation
```

---

## 2. Tablas y columnas

Todas las tablas tienen PK propia, `timestamps` y el trait `Auditable` con lista cerrada de campos.
Los importes son `numeric(12,2)` y la moneda es `char(3)` con `CHECK = 'MXN'`.

### `subscriptions`
| Columna | Nota |
|---|---|
| `provider` | Proveedor fijo de la suscripción |
| `external_id` | Nulo hasta que el proveedor la crea |
| `donor_id`, `program_id`, `campaign_id` | Donante y destino único |
| `amount`, `currency`, `interval` | `interval` solo acepta `monthly` por ahora |
| `status`, `provider_status`, `provider_updated_at` | Estado normalizado y estado del proveedor |
| `retry_owner` | `provider` / `crm`; en esta fase siempre `provider` |
| `next_charge_at`, `started_at` | |
| `paused_at`, `paused_by_id`, `resumed_at`, `resumed_by_id` | |
| `cancelled_at`, `cancelled_by_id`, `cancellation_source`, `cancellation_reason` | `cancellation_source`: `crm_user` / `provider` / `donor` |
| `idempotency_key` | |

### `payments`
| Columna | Nota |
|---|---|
| `provider` | |
| `external_id` | Nulo si el proveedor no tiene un objeto de cobro estable; ver §8 |
| `kind` | `one_time` / `recurring_charge` |
| `subscription_id`, `billing_period_start` | Solo en recurrentes |
| `donor_id`, `program_id`, `campaign_id` | |
| `amount`, `currency`, `status`, `provider_status`, `provider_updated_at` | |
| `next_retry_owner`, `next_retry_at` | `next_retry_owner`: `provider` / `crm` / nulo; solo informativos |
| `succeeded_at`, `failed_at`, `idempotency_key` | |

`payments` **no** guarda `amount_refunded` ni `refund_state`: se calculan desde `refunds` (§9).

### `payment_attempts`
- `payment_id`, `provider`, `external_id`, `attempt_number`.
- `status`, `initiated_by` (`donor` / `provider` / `crm`).
- `failure_category`, `provider_code`, `provider_message` (sanitizado).
- `card_brand`, `card_last4`, `provider_created_at`.

### `refunds`
- `payment_id`, `provider`, `external_id`, `amount`, `status`.
- `reason` (catálogo interno), `reason_comment`, `provider_reason` (valor que se envió al proveedor, si aplica), `failure_reason`.
- `source` (`crm` / `provider`), `requested_by_id`, `requested_at`, `processed_at`, `idempotency_key`.

### `payment_disputes`
- `payment_id`, `provider`, `external_id`, `amount`.
- `status`, `provider_status`, `provider_reason`.
- `opened_at`, `evidence_due_at`, `closed_at`, `provider_updated_at`.

### `webhook_events`
- `provider`, `external_event_id`, `event_type`, `resource_type`, `resource_external_id`, `provider_created_at`.
- `payload` (`jsonb` según la allowlist de §18.1), `received_at`, `status` (`pending` / `processed` / `ignored` / `failed`).
- `attempts`, `last_error` (sanitizado), `processed_at`.
- Referencias resueltas: `payment_id`, `subscription_id`, `refund_id`, `dispute_id`.

### `payment_incidents` y `payment_incident_notes`
- **Incidencia:**
  - `type`, `severity` (`warning` / `critical`), `failure_category`;
  - referencias (§13);
  - `status`, `detected_at`, `reviewing_by_id`, `reviewing_started_at`;
  - `resolved_by_id`, `resolved_at`, `resolution`, `dedupe_key`.
- **Nota:** `incident_id`, `user_id`, `body`, `created_at`. Solo inserción.

### Cambios en tablas existentes
- `users.receives_payment_alerts`: booleano, predeterminado `false`.
- `organization_settings.online_donation_min_amount`: `numeric(12,2)`, nulo.
- `organization_settings.online_donation_max_amount`: `numeric(12,2)`, nulo.
- `audit_logs.source` y `audit_logs.webhook_event_id`: ver §19.
- `donations`: ver §14.

---

## 3. Restricciones de base de datos

| Tabla | Restricción |
|---|---|
| Tablas de proveedor | `CHECK provider IN ('stripe','mercado_pago','fake')`. El registro de gateways solo acepta `fake` en `local` y `testing` |
| `subscriptions`, `payments`, `payment_attempts`, `refunds`, `payment_disputes` | **ÚNICA `(provider, external_id)`**, índice parcial con `external_id IS NOT NULL` |
| `webhook_events` | **ÚNICA `(provider, external_event_id)`** |
| `subscriptions`, `payments`, `refunds` | **ÚNICA `idempotency_key`** |
| `payments` | `CHECK (kind = 'recurring_charge') = (subscription_id IS NOT NULL)` |
| `payments` | `CHECK (kind = 'recurring_charge') = (billing_period_start IS NOT NULL)` |
| `payments` | **ÚNICA `(subscription_id, billing_period_start)`** |
| `payments` | `CHECK amount > 0` y destino único |
| `payment_attempts` | **ÚNICA `(payment_id, attempt_number)`** |
| `payment_attempts` | `CHECK card_last4 ~ '^[0-9]{4}$'` |
| `refunds` | `CHECK amount > 0` |
| `refunds` | `CHECK reason <> 'other' OR reason_comment IS NOT NULL` |
| `refunds` | **Trigger `refunds_within_payment_amount`**: al insertar o actualizar, bloquea la fila del Payment (`FOR UPDATE`) y rechaza la operación si la suma de reembolsos `pending` + `succeeded` supera `payments.amount`. Es la segunda barrera; la Action es la primera (§9) |
| `payment_incidents` | Al menos una referencia |
| `payment_incidents` | `reviewing` exige `reviewing_by_id` y `reviewing_started_at` |
| `payment_incidents` | `resolved` exige `resolved_by_id`, `resolved_at` y `resolution` |
| `payment_incidents` | **ÚNICA `dedupe_key`** (§13) |
| `payment_incident_notes` | Trigger de solo inserción |
| Todas | FK `restrict`; nada se borra en cascada |
| `donations` | **ÚNICA `payment_id`** y CHECKs de origen (§14) |

---

## 4. Estados

| Enum | Valores |
|---|---|
| `PaymentStatus` | `pending`, `processing`, `succeeded`, `failed`, `cancelled` |
| `RefundState` (calculado) | `none`, `partial`, `full` |
| `PaymentAttemptStatus` | `pending`, `succeeded`, `failed` |
| `SubscriptionStatus` | `pending`, `active`, `past_due`, `paused`, `cancelled`, `expired` |
| `RefundStatus` | `pending`, `succeeded`, `failed`, `cancelled` |
| `RefundReason` | `donor_request`, `duplicate_charge`, `incorrect_amount`, `administrative_error`, `other` |
| `DisputeStatus` | `open`, `under_review`, `won`, `lost`, `closed` |
| `IncidentStatus` | `new`, `reviewing`, `resolved` |
| `IncidentSeverity` | `warning`, `critical` (fija por tipo; sin máquina de severidades) |
| `FailureCategory` | `expired_card`, `insufficient_funds`, `invalid_payment_data`, `authentication_failed`, `declined`, `suspected_fraud`, `duplicate`, `too_many_attempts`, `processing_error`, `provider_unavailable`, `unknown` |
| `PaymentKind` / `SubscriptionInterval` | `one_time`, `recurring_charge` / `monthly` |

Etiquetas en español para `RefundReason`: Solicitud del donante, Cobro duplicado, Importe incorrecto,
Error administrativo, Otro.

---

## 5. Máquinas de estados

**Regla general.** La transición se decide con el estado actual del proveedor, no con el orden en
que llegan los webhooks. Se guardan `provider_status` y `provider_updated_at`, o la versión o marca
de tiempo que exponga el recurso. Un evento con marca anterior a la guardada dispara una nueva
consulta del recurso, y se aplica lo que el proveedor diga en ese momento. Nunca se descarta un
evento solo porque su estado parezca "anterior". Una transición no permitida no se aplica: abre la
incidencia `state_inconsistency`.

**Payment**
```
pending ──► processing ──► succeeded
   │            │
   ├──► failed ◄┘       failed ──► succeeded  (el proveedor confirma una recuperación)
   └──► cancelled       failed ──► cancelled
```
`succeeded` es final para el ciclo de cobro; reembolsos y disputas son dimensiones aparte.
[V] En Stripe, una factura impaga se marca pagada si el cobro se recupera después.

**PaymentAttempt:** `pending → succeeded | failed`. Ambos son finales.

**Subscription**
```
pending ──► active ◄──► past_due
   │          │  ▲          │
   │          ▼  │          ▼
   │        paused ───────► cancelled
   └──► expired
```
Un Payment fallido **nunca** cancela la suscripción. Solo la cancelan el estado real del proveedor
o una persona autorizada.

**Refund:** `pending → succeeded | failed | cancelled`. También se permite `succeeded → failed`
([V] en Stripe un reembolso puede fallar después).

**PaymentDispute:** `open → under_review → won | lost`; también `open → won | lost | closed` y
`lost → won` ([V] "late win" en Stripe).

**PaymentIncident:** `new → reviewing → resolved`, o `new → resolved` directo, quedando registrado
quién la resolvió. La incidencia resuelta conserva su resolución. Leer la notificación no cambia
su estado.

### 5.1 Límites de importe

| Tipo de límite | Stripe (MX, MXN) | Mercado Pago (MX, tarjeta) |
|---|---|---|
| Técnico mínimo | **10.00 MXN** por cargo [V] | [S] (la página de ayuda no fue accesible; no se inventa) |
| Técnico máximo | Hasta 12 dígitos en unidades menores en la API; la red de tarjeta puede limitar menos [V] | [S] |

- Los límites técnicos viven en la configuración de cada adaptador (`config/payments.php`), documentados con su fuente. **No** son reglas de negocio.
- `online_donation_min_amount` (nulo) es el mínimo de negocio configurable. Mientras sea nulo solo aplica el límite técnico del proveedor.
- `online_donation_max_amount` (nulo) es el máximo de negocio configurable. Nulo significa "sin máximo adicional del CRM".
- Validación efectiva: `max(mínimo_negocio, mínimo_técnico_del_proveedor) ≤ importe ≤ min(máximo_negocio, máximo_técnico)`.
- Los valores predeterminados de negocio [D] se definen después de verificar Mercado Pago en sandbox.

---

## 6. Mapping de Stripe → interno [V]

| Stripe | Interno |
|---|---|
| Checkout Session o PaymentIntent `requires_payment_method` inicial, `requires_confirmation`, `requires_action` | Payment `pending` |
| PaymentIntent regresa a `requires_payment_method` después de un rechazo | Attempt `failed`; el Payment sigue `pending` |
| `processing` | `processing` |
| `succeeded` | Attempt y Payment `succeeded` → Donation |
| `canceled` o `checkout.session.expired` | `cancelled`, o `failed` si hubo intentos fallidos |
| Invoice del ciclo `open` con intentos | Payment `pending` (recurring_charge); cada Charge es un Attempt |
| Invoice `paid` | `succeeded` → Donation |
| Invoice `uncollectible` / `void` | `failed` / `cancelled` |
| Subscription `incomplete` / `incomplete_expired` | `pending` / `expired` |
| `active` sin o con `pause_collection` | `active` / `paused` |
| `past_due` / `unpaid` | `past_due` |
| `canceled` | `cancelled` |
| Refund `pending` o `requires_action` / `succeeded` / `failed` / `canceled` | `pending` / `succeeded` / `failed` / `cancelled` |
| Dispute `warning_needs_response`, `needs_response` | `open` |
| Dispute `warning_under_review`, `under_review` | `under_review` |
| Dispute `won` / `lost` / `warning_closed` | `won` / `lost` / `closed` |

`external_id`: el Payment único usa el PaymentIntent; el Payment recurrente usa la Invoice; el
Attempt usa el Charge.

## 7. Mapping de Mercado Pago (opción A) → interno

| Mercado Pago | Interno | Marca |
|---|---|---|
| payment `pending` / `in_process` / `authorized` | Attempt `pending`; Payment `pending` / `processing` | [V] |
| payment `approved` | Attempt y Payment `succeeded` → Donation | [V] |
| payment `rejected` + `cc_rejected_*` | Attempt `failed` + `FailureCategory` | [V] |
| payment `cancelled` | `cancelled` | [V] |
| payment `refunded` o `partially_refunded` | Sincroniza los Refunds | [V] |
| payment `charged_back` / `in_mediation` | PaymentDispute | [V] |
| preapproval `pending` / `authorized` / `paused` / `cancelled` | Subscription `pending` / `active` / `paused` / `cancelled` | [V] |
| `authorized_payment` del periodo | Payment `recurring_charge` (`external_id` = id del authorized_payment) | [S] |
| Cada payment cobrado dentro de un authorized_payment | Attempt (`external_id` = id del payment) | [S] |
| authorized_payment en `recycling` | Attempt `failed` + Subscription `past_due` | [S] |
| Contracargo (`GET /v1/chargebacks/{id}`) | PaymentDispute; mapping de estados | [S] |

## 8. Identificadores por proveedor

| Caso | `payments.external_id` | `payment_attempts.external_id` |
|---|---|---|
| Stripe, único | PaymentIntent | Charge [V] |
| Stripe, mensual | Invoice | Charge [V] (cada reintento ejecutado crea un Charge) |
| Mercado Pago, único (Checkout API) | Order, o nulo si se crea una Order por intento [S] | payment [S] |
| Mercado Pago, mensual (opción A) | authorized_payment [S] | payment [S] |

Un reintento **nunca** crea un Payment nuevo; cada mensualidad sí.

## 9. Refund

- `Payment 1 → N Refund`.
- `RefundState` se calcula así:
  - `none`: suma de `succeeded` = 0;
  - `partial`: 0 < suma < importe;
  - `full`: suma = importe.
- Un índice `(payment_id, status)` lo hace eficiente. Para las listas de Filament se usa la subconsulta `withSum`. Materializarlo sería otra decisión.
- **Motivo obligatorio:** `RefundReason` más `reason_comment`, que es obligatorio con `other` (CHECK y validación).
- **Traducción al proveedor.** El adaptador traduce el motivo; nunca envía texto libre:

  | Interno | Stripe `reason` [V] | Mercado Pago |
  |---|---|---|
  | `donor_request` | `requested_by_customer` | Sin campo de motivo en la API de reembolsos, según la referencia [S] |
  | `duplicate_charge` | `duplicate` | [S] |
  | `incorrect_amount`, `administrative_error`, `other` | Se omite (no hay equivalente) | [S] |

  `refunds.provider_reason` guarda lo que se envió.
- **Flujo de `RequestRefund`:**
  1. Bloquea el Payment con `lockForUpdate`.
  2. Valida que el Payment esté `succeeded`, que no tenga disputa abierta ([V] Stripe no permite reembolsar con disputa abierta) y el saldo disponible (importe − `pending` − `succeeded`).
  3. Inserta el Refund `pending` con su `idempotency_key` y confirma la transacción.
  4. Llama al proveedor con esa misma llave (Stripe `Idempotency-Key`, Mercado Pago `X-Idempotency-Key` [V]).
  5. Aplica el resultado.
- **Doble clic:** la llave se genera al abrir el formulario y viaja con él. Un segundo envío choca con la llave ÚNICA y devuelve el Refund existente.
- **Timeout:** el Job de conciliación reintenta la llamada con la llave guardada, así que el proveedor no duplica el reembolso.
- La Donation no cambia.
- La auditoría registra quién, cuándo, importe, motivo, proveedor y resultado.

## 10. PaymentDispute

- `Payment 1 → N PaymentDispute`. Stripe permite varias disputas por cargo [V]; Mercado Pago tiene una API de contracargos propia [V].
- Una disputa nueva abre la incidencia crítica `dispute_opened` (dedupe `dispute:{id}:opened`).
- No altera Donation, CFDI ni recibo.
- El detalle lo ven Administrador y Contador. El Coordinador solo ve la incidencia operativa, por ejemplo: "Existe un contracargo que requiere revisión", con donante, importe, fecha y acción requerida.

## 11. Subscription

- `interval = monthly` por ahora.
- Pausar, reactivar y cancelar según la capacidad del proveedor (§21). Cada acción se audita con actor y motivo.
- El CRM nunca cancela por un intento fallido. Sincroniza el estado real y muestra `past_due`.

## 12. WebhookEvent (bandeja de entrada)

1. Ruta por proveedor:
   - verifica la firma: Stripe con el SDK sobre `Stripe-Signature`; Mercado Pago con HMAC-SHA256 del manifiesto `id:…;request-id:…;ts:…;` y `hash_equals` [V];
   - si la firma es inválida responde 400, no guarda nada y solo registra un conteo en el log.
2. `INSERT … ON CONFLICT (provider, external_event_id) DO NOTHING` con el payload filtrado por la allowlist (§18.1).
3. Si el evento es nuevo, despacha `ProcessWebhookEvent` en Redis.
4. Responde **2xx de inmediato**. [V] Mercado Pago exige 200/201 en 22 s; Stripe pide responder antes de ejecutar lógica.
5. El Job:
   - consulta el recurso actual del proveedor ([V] Mercado Pago lo recomienda; Stripe no garantiza orden y puede duplicar eventos);
   - bloquea el Payment o la Subscription;
   - aplica la transición;
   - guarda las referencias resueltas y `processed_at`.
6. Si el Job agota sus intentos: el evento queda `failed` y se abre la incidencia `webhook_unprocessable`.
7. [S] Confirmar en Mercado Pago qué campo de la notificación sirve como `external_event_id` único.

## 13. PaymentIncident

**`dedupe_key` = hecho concreto.** Nunca solo `payment_id`. Un hecho nuevo sobre el mismo Payment
crea una incidencia nueva, aunque la anterior siga resuelta.

| `type` | Cuándo | Severidad | `dedupe_key` | Coordinador |
|---|---|---|---|---|
| `one_time_payment_failed` | Pago único que termina `failed` definitivamente con algo que revisar (no por cada rechazo corregible) | warning | `payment:{id}:final_failed` | Sí |
| `recurring_attempt_failed` | **Cada** Attempt fallido de una mensualidad (alerta temprana) | warning | `attempt:{id}:failed` | Sí |
| `recurring_payment_failed` | La mensualidad termina `failed` | critical | `payment:{id}:final_failed` | Sí |
| `subscription_cancelled_by_provider` | El proveedor cancela | critical | `subscription:{id}:cancelled:{provider_updated_at}` | Sí |
| `dispute_opened` | Nueva disputa | critical | `dispute:{id}:opened` | Sí (operativa) |
| `refund_failed` | Un reembolso termina `failed` | critical | `refund:{id}:failed` | No |
| `succeeded_without_donation` | Payment `succeeded` sin Donation tras el Job (detectado por la conciliación) | critical | `payment:{id}:missing_donation` | No |
| `state_inconsistency` | Transición no permitida o estado desconocido | warning | `{recurso}:{id}:inconsistent:{provider_status}` | No |
| `provider_unavailable` | Fallo técnico persistente tras los reintentos técnicos | critical | `provider:{provider}:unavailable:{fecha-hora truncada a la hora}` | No |
| `webhook_unprocessable` | Job agotado | critical | `webhook:{id}:unprocessable` | No |

- **Pagos únicos:** cada rechazo se guarda como PaymentAttempt y el donante ve un mensaje seguro. No se abre incidencia por cada Attempt.
- **Pagos recurrentes:** alerta desde el primer Attempt fallido, porque el donante no está presente.
- La columna "Coordinador" indica qué tipos son operativos (visibles y gestionables por el Coordinador). Los demás son técnicos: solo Administrador y Contador.
- La creación usa `INSERT … ON CONFLICT (dedupe_key) DO NOTHING`. La alerta solo sale si la fila se insertó.

---

## 14. Modificaciones exactas a `donations` (una migración reversible)

| Cambio | Detalle |
|---|---|
| `origin` | `varchar(20) NOT NULL DEFAULT 'manual'` con `CHECK origin IN ('manual','online')` |
| `payment_id` | FK a `payments`, `restrict`, nulo, **ÚNICA** |
| Renombrar | `payment_method` → `manual_payment_method`; enum `PaymentMethod` → `ManualPaymentMethod`; el CHECK se renombra. Nunca guarda `card` |
| `registered_by_id` | Pasa a aceptar nulo |
| `confirmed_by_id` | Ya acepta nulo; cambian sus CHECKs |
| CHECK `donations_origin_manual` | `origin <> 'manual' OR (registered_by_id IS NOT NULL AND payment_id IS NULL)` |
| CHECK `donations_origin_online` | `origin <> 'online' OR (payment_id IS NOT NULL AND registered_by_id IS NULL AND manual_payment_method IS NULL AND kind = 'monetary')` |
| CHECK de campos por tipo | El dinero manual exige `manual_payment_method`; el dinero en línea no |
| CHECK `donations_confirmation_evidence` | `status <> 'confirmed' OR (confirmed_at IS NOT NULL AND (confirmed_by_id IS NOT NULL OR origin = 'online'))` |
| CHECK del par de confirmación | Solo aplica a `origin = 'manual'` |

**Creación idempotente.** `CreateDonationFromPayment` solo se ejecuta cuando el Payment quedó
`succeeded` según el recurso consultado al proveedor:
1. bloquea el Payment;
2. si ya hay Donation, la devuelve;
3. si no, la inserta como `confirmed` con `confirmed_at = payments.succeeded_at`.

La restricción ÚNICA `payment_id` es la última barrera. La Donation en línea no se edita; una
cancelación administrativa sigue exigiendo actor humano. No existe usuario "sistema".

---

## 15. Matriz de permisos de la Fase 2

A = Administrador · C = Coordinador de procuración de fondos · Co = Contador · L = Solo lectura

| Permiso | A | C | Co | L |
|---|---|---|---|---|
| `payments.view`: lista y detalle operativo (donante, importe, fecha, proveedor, estado normalizado, único/recurrente, campaña o programa, `RefundState`) | ✔ | ✔ | ✔ | ✔ |
| `payments.view_technical`: códigos, IDs externos, historial de intentos, tarjeta (marca y últimos 4), datos sanitizados | ✔ | — | ✔ | — |
| `payments.export` | ✔ | ✔ | ✔ | — |
| `refunds.request` | ✔ | — | ✔ | — |
| `subscriptions.view` | ✔ | ✔ | ✔ | ✔ |
| `subscriptions.manage`: pausar, reactivar, cancelar | ✔ | ✔ | — | — |
| `disputes.view` (detalle) | ✔ | — | ✔ | — |
| `incidents.view` | ✔ (todas) | ✔ (tipos operativos) | ✔ (todas) | — |
| `incidents.manage`: tomar, anotar, pasar a En revisión, resolver | ✔ (todas) | ✔ (tipos operativos) | ✔ (todas) | — |
| Recibir alertas | Siempre | Si tiene `receives_payment_alerts` | Si tiene `receives_payment_alerts` | Nunca |
| `webhooks.view` (bandeja técnica) | ✔ | — | — | — |
| `payment_settings.view` (pasarelas habilitadas y modo) | ✔ | — | — | — |

- Solo lectura no ejecuta ninguna acción.
- La preferencia de alertas la activa el Administrador en Usuarios.
- Todas las transiciones y notas de incidencias se auditan.

---

## 16. Reintentos por proveedor

**Regla del dominio.** `subscriptions.retry_owner` indica quién reintenta. En esta fase siempre es
`provider`. El CRM **no** tiene scheduler de reintentos de cobro: solo sincroniza y muestra los
intentos que ocurren. `next_retry_owner` y `next_retry_at` son informativos, y se llenan si el
proveedor los expone (Stripe `next_payment_attempt` [V]). No hay números de intentos en el código.

### 16.1 Stripe
- **Pago único:** el donante reintenta en la misma sesión [V].
- **Mensual:** reintenta Stripe con Smart Retries en su política predeterminada [V]. Hoy son 8 intentos en 2 semanas, pero es un dato informativo, no una constante del CRM.
- **Configuración obligatoria en producción y en prueba** [V] (etiquetas exactas de la pantalla [S]):
  - Dashboard → Billing → *Revenue recovery* → *Retries* → acción de la suscripción cuando fallan todos los reintentos: **"leave the subscription past-due"**. No usar "cancel" ni "mark as unpaid".
  - Las facturas se dejan como están (sin marcarlas incobrables).
- Si aun así llegara `unpaid`, se mapea a `past_due` y se abre `state_inconsistency` para revisar la configuración.

### 16.2 Mercado Pago (opción A, `/preapproval` sin plan)
- Reintenta Mercado Pago.
- La documentación del producto describe `recycling` con hasta 4 reintentos en 10 días y cancelación automática tras 3 cuotas rechazadas [V]. No se codifica: el CRM sincroniza el estado real y lo confirma en sandbox [S].

### 16.3 Checklist de sandbox para Mercado Pago (condición para cerrar el adaptador)

| # | Verificación | Estado |
|---|---|---|
| 1 | Crear una suscripción sin plan con el importe elegido por el donante | [S] |
| 2 | Frecuencia mensual (`frequency = 1`, `frequency_type = months`) | [S] |
| 3 | Primer cobro | [S] |
| 4 | Siguiente cobro (authorized_payment); cómo se simula el avance de tiempo | [S] |
| 5 | Relación `preapproval` ↔ `authorized_payment` ↔ `payment` | [S] |
| 6 | Tópicos de notificación (`subscription_preapproval`, `subscription_authorized_payment`, `payment`) y campo único del evento | [S] |
| 7 | Cuota rechazada | [S] |
| 8 | Comportamiento real de los reintentos (`recycling`) | [S] |
| 9 | Pausa | [S] |
| 10 | Reactivación (parámetro exacto) | [S] |
| 11 | Cancelación | [S] |
| 12 | Identificador externo del Payment | [S] |
| 13 | Identificador externo del PaymentAttempt | [S] |
| 14 | Límites mínimo y máximo con tarjeta | [S] |
| 15 | Campo de motivo en reembolsos | [S] |
| 16 | Estados de contracargos | [S] |

Si algún punto contradice la opción A, me detengo y te consulto antes de cambiar el diseño.

---

## 17. Notificaciones

1. La incidencia se inserta en la misma transacción que el hecho (`ON CONFLICT (dedupe_key) DO NOTHING`).
2. Si se insertó, después del commit se encola `PaymentIncidentOpened`:
   - canal `database`: campana de Filament, **Fase 2**;
   - canal `mail`: preparado, detrás de `payments.alerts.mail_enabled` (apagado hasta la fase de comunicaciones).
3. **Destinatarios:**
   - todos los Administradores activos;
   - Coordinadores activos con la preferencia, solo en tipos operativos;
   - Contadores activos con la preferencia;
   - nunca Solo lectura;
   - ninguna dirección fija en el código.
4. El contenido es operativo. El detalle técnico solo se ve en el CRM con permiso.
5. Leer la notificación no resuelve la incidencia.

## 18. Sanitización

### 18.1 Allowlist de `webhook_events.payload`

Se guarda la evidencia necesaria para reproducir el procesamiento, investigar una incidencia,
identificar el recurso, conocer el tipo de evento y sus fechas, y comprobar la idempotencia. Todo
lo que no esté en la lista se descarta.

**Stripe (evento):**
- evento: `id`, `type`, `created`, `livemode`, `api_version`, `request.id`, `request.idempotency_key`, `pending_webhooks`;
- recurso: `data.object.id`, `data.object.object`;
- estado: `status`, `amount`, `amount_received`, `amount_refunded`, `amount_paid`, `amount_due`, `currency`, `created`;
- referencias: `payment_intent`, `invoice`, `charge`, `subscription`, `customer` (id);
- metadatos propios: `metadata.crm_payment_id`, `metadata.crm_subscription_id`;
- rechazo: `last_payment_error.code`, `last_payment_error.decline_code`, `failure_code`, `outcome.type`, `outcome.reason`, `outcome.network_status`;
- tarjeta: `payment_method_details.card.brand`, `payment_method_details.card.last4`;
- ciclo (Invoice): `attempt_count`, `next_payment_attempt`, `billing_reason`, `period_start`, `period_end`;
- Subscription: `cancel_at_period_end`, `canceled_at`, `pause_collection.behavior`;
- Refund y Dispute: `reason`, `evidence_details.due_by`, `is_charge_refundable`;
- cambios: `data.previous_attributes` (solo las **claves**, sin valores).

**Mercado Pago (notificación + recurso consultado):**
- notificación: `id`, `type` o `topic`, `action`, `api_version`, `date_created`, `live_mode`, `data.id`, `user_id` (id de la cuenta vendedora);
- encabezados: `x-request-id` y el `ts` de `x-signature` (**nunca** `v1`);
- payment: `id`, `status`, `status_detail`, `transaction_amount`, `currency_id`, `date_created`, `date_approved`, `date_last_updated`, `external_reference`, `preapproval_id`, `payment_method_id`, `payment_type_id`, `card.last_four_digits`, `transaction_amount_refunded`;
- preapproval: `id`, `status`, `auto_recurring.frequency`, `auto_recurring.frequency_type`, `auto_recurring.transaction_amount`, `auto_recurring.currency_id`, `next_payment_date`, `last_modified`, `external_reference`;
- authorized_payment: `id`, `preapproval_id`, `status`, `transaction_amount`, `retry_attempt`, `next_retry_date`, `date_created`, `last_modified`, `payment.id`, `payment.status`, `payment.status_detail` (campos exactos [S]);
- chargeback: `id`, `payments`, `amount`, `currency`, `stage`, `status`, `coverage_eligible`, `documentation_required`, `documentation_status`, `date_documentation_deadline`, `date_created`, `last_modified` (campos exactos [S]).

**Siempre se descartan:** nombres, correos, teléfonos, direcciones, IP, `billing_details`,
`payer.*` (salvo el id), `client_secret`, tokens (`card_token_id`, `token`), huella de la tarjeta,
fecha de vencimiento, BIN y cualquier metadato no listado.

### 18.2 Resto de destinos

| Destino | Regla |
|---|---|
| Logs | Solo proveedor, ids, tipo de evento, resultado y categoría. Un procesador borra las llaves que coincidan con `secret\|token\|signature\|authorization\|card\|cvc\|cvv\|pan\|password` |
| Errores | `PaymentProviderException` con código y mensaje sanitizado; nunca el cuerpo de la respuesta |
| AuditLog | Lista cerrada de campos, sin payloads |
| Exportaciones | Columnas técnicas solo con `payments.view_technical`; purga a 7 días |
| Secretos | Solo en variables de entorno. Filament muestra "habilitada o no" y "prueba o producción" |

## 19. Trazabilidad de operaciones automáticas

- No existe usuario "sistema". La procedencia se obtiene sin duplicar datos:
  - `webhook_events` guarda el proveedor, el evento, el recurso, sus fechas y las referencias resueltas (Payment, Subscription, Refund, Dispute);
  - los Attempts, Refunds y Disputes llevan su `external_id` y `provider_created_at`.
- Cambio mínimo en `audit_logs`: dos columnas nulas.
  - `source`: `user` / `webhook` / `job` / `sync` / `console`. Nulo en los registros existentes.
  - `webhook_event_id`: FK nula.
- Un cambio automático queda con `user_id = null`, más su `source` y, si aplica, el evento que lo originó. El Job se identifica por su `source` y su clase. No se guardan payloads en la bitácora.

---

## 20. ADR-011 (a registrar al implementar)

- **Decisión:**
  - contratos por capacidad y `GatewayRegistry` por proveedor;
  - `FakeGateway` obligatorio;
  - **`stripe/stripe-php ^21.3`** [APROBADO];
  - Mercado Pago con el cliente HTTP de Laravel.
- **Condiciones de aceptación del SDK:**
  - compatible con PHP 8.5;
  - instalación limpia y Composer sin conflictos;
  - Pint, Larastan nivel 8 y Pest en verde;
  - CI en verde;
  - build `prod` correcto.

  Si alguna falla, me detengo y te consulto.
- **Mercado Pago:** su SDK no está autorizado. Si el sandbox muestra una razón concreta para usarlo, me detengo y pido autorización.

## 21. Capacidades e interfaces

| Capacidad | Stripe | Mercado Pago (A) |
|---|---|---|
| `OneTimePayments` | Sí | Sí |
| `RecurringPayments` | Sí (Billing) | Sí (`/preapproval` sin plan) |
| `PausableSubscriptions` | Sí (`pause_collection`) | Sí (reactivación [S]) |
| `Refunds` (parcial y total) | Sí | Sí (180 días [V]) |
| `Disputes` (consulta) | Sí | Sí |
| `WebhookVerifier`, `EventNormalizer`, `ResourceFetcher` | Sí | Sí |
| `AmountLimits` | 10 MXN [V] | [S] |

**Actions:**
- pagos: `StartOneTimeDonation`, `StartMonthlyDonation`, `ApplyProviderState`, `RecordPaymentAttempt`, `CreateDonationFromPayment`, `SyncPaymentFromProvider`;
- reembolsos y disputas: `RequestRefund`, `ApplyRefundState`, `ApplyDisputeState`;
- suscripciones: `PauseSubscription`, `ResumeSubscription`, `CancelSubscription`;
- incidencias: `OpenPaymentIncident`, `TakeIncidentForReview`, `AddIncidentNote`, `ResolveIncident`.

**Jobs:** `ProcessWebhookEvent`, `ReconcilePendingPayments` (programado: Payments atascados, Refunds `pending` tras un timeout y `succeeded` sin Donation) y `SendPaymentIncidentAlert`.

**FakeGateway**, con escenarios deterministas:
- éxito, pendiente, fallo;
- `expired_card`, `insufficient_funds`, `declined`, proveedor no disponible;
- webhook duplicado y fuera de orden;
- reembolso parcial y total;
- suscripción, cobro mensual, fallo mensual y recuperación;
- disputa;
- timeout después de que el proveedor procesó.

## 22. Orden de implementación [APROBADO]

1. Migraciones y modelo interno.
2. Enums.
3. Contratos y capacidades.
4. Actions.
5. FakeGateway.
6. Pruebas exhaustivas del dominio.
7. Filament.
8. Webhook inbox genérico.
9. Adaptador de Stripe.
10. Adaptador de Mercado Pago.
11. Sandbox de cada proveedor.
12. Documentación y manual.

Antes del punto 11 te indicaré:
- qué cuenta crear y qué modo de prueba activar;
- qué variables obtener y dónde ponerlas en tu `.env` local;
- cómo registrar el webhook de prueba (Stripe CLI; URL de prueba de Mercado Pago).

Nunca pediré llaves por chat.

## 23. Ampliaciones futuras (fuera del alcance)

| Tema | Nota |
|---|---|
| Mercado Pago opción B: cobros iniciados por el comercio con tarjeta guardada | El CRM controlaría los reintentos (`retry_owner = crm`). Existencia y disponibilidad en México [S]; página oficial no accesible |
| OXXO | Stripe: 10 a 10,000 MXN, sin recurrentes ni reembolsos [V] |
| SPEI | Stripe: transferencia al saldo del cliente [V] |
| Meses sin intereses | Solo cuentas Stripe México con tarjetas de crédito mexicanas [V] |
| Política de reintentos personalizada | Decisión separada |

## 24. Pruebas de idempotencia y concurrencia

| Caso | Técnica |
|---|---|
| Doble clic al iniciar el pago | Dos llamadas a `StartOneTimeDonation` con la misma llave → un Payment |
| Mismo webhook dos veces | Dos POST con el mismo evento → una fila en `webhook_events` y un Job |
| Mismo webhook concurrente | Dos procesos en paralelo (`Process::pool` con un comando de prueba contra `crm_testing`) → una sola fila y un solo Donation |
| Job reintentado | Ejecutar `ProcessWebhookEvent` dos veces → sin cambios la segunda vez |
| Webhook fuera de orden | FakeGateway entrega `succeeded` antes que `pending` → el estado final es `succeeded` |
| Timeout después de que el proveedor procesó | FakeGateway procesa y lanza timeout; la conciliación reutiliza la llave → un solo cobro o reembolso |
| Reembolso solicitado dos veces | Misma llave → un Refund |
| Reembolsos concurrentes | (a) Dos procesos en paralelo que reembolsan cada uno el 60 % → uno falla y la suma ≤ importe. (b) Prueba directa del trigger `refunds_within_payment_amount` desde una segunda conexión |
| Mismo Payment en paralelo | Dos procesos aplican `succeeded` → un Donation (ÚNICA `payment_id`) y una incidencia (ÚNICA `dedupe_key`) |
| Incidencia repetida y hecho nuevo | Mismo hecho → sin duplicado; hecho distinto sobre el mismo Payment → incidencia nueva |

La concurrencia real se prueba con procesos separados porque una sola conexión PHP no puede
competir consigo misma. Las transacciones de prueba no aplican a esos casos: limpian sus datos al
terminar.

---

## 25. Ajustes de la autorización e implementación (2026-09-23)

### 25.1 Decisiones de la autorización de implementación [APROBADO]

- **`audit_logs.source`** con los valores `user`, `webhook`, `job`, `synchronization` y `console`. **Sin** `audit_logs.webhook_event_id`: la trazabilidad técnica vive en `webhook_events` y en las tablas de pagos.
- El Coordinador gestiona solo las incidencias operativas; la pantalla no le muestra códigos ni payloads.
- Defensa en profundidad en reembolsos: Action + transacción + bloqueo del Payment + idempotencia + trigger. Reservan saldo los reembolsos `pending` y `succeeded` (el pendiente puede completarse en el proveedor en cualquier momento).
- La concurrencia se prueba con procesos reales, usando un script solo de pruebas (`tests/Support/race.php`) en lugar de un comando de Artisan.
- `online_donation_min_amount` / `online_donation_max_amount` nulos significan que no hay límite propio; se aplica el más restrictivo junto con el técnico.

### 25.2 Diferencias entre la documentación previa y lo implementable

| # | Tema | Lo que dice el diseño | Lo que se encontró | Marca |
|---|---|---|---|---|
| 1 | Stripe Checkout embebido | "modo embebido" | En la API `2026-08-26.dahlia` (la que fija el SDK v21.3.2) el valor es `ui_mode: embedded_page` | [V] tipos del SDK |
| 2 | Estados de disputa de Stripe | 7 estados | Existe además `prevented`; se mapea a `closed` | [V] tipos del SDK |
| 3 | Factura de Stripe → cobro | Invoice + su PaymentIntent | En `dahlia` la factura ya no tiene `payment_intent`: los cobros están en `invoice.payments` (InvoicePayment) y la suscripción en `parent.subscription_details.subscription` | [V] tipos del SDK |
| 4 | `payments.external_id` en Stripe único | PaymentIntent | Checkout crea el PaymentIntent al pagar: antes el Payment tiene `external_id` nulo y se concilia por `metadata.crm_payment_id` | [V] tipos del SDK; [S] |
| 5 | Protección contra orden inverso en Stripe | `provider_updated_at` | Los objetos de Stripe no tienen fecha de actualización: la protección es la reconsulta del estado actual y las transiciones permitidas | [V] |
| 6 | Reembolsos hechos en el panel del proveedor | — | Se registran con `source = provider` y el motivo interno `provider_initiated` ("Hecho en el panel del proveedor"), que no se puede elegir en el CRM | Detalle técnico |
| 7 | Reintento de un pago único en Mercado Pago | Una Order con varios intentos | Una Order rechazada no se reintenta: el reintento del donante sería otra Order (otro Payment) | [S] |
| 8 | Pausa en Stripe | `pause_collection` | Se usa `behavior: void` (el periodo pausado no genera adeudo) | **[D] confirmar** |
| 9 | "Pago único fallido con algo que revisar" | Criterio general | Se abre incidencia si el último rechazo no es corregible por el donante (fraude, error de procesamiento, desconocido…); no se abre por tarjeta vencida, fondos, datos mal escritos, rechazo del banco o demasiados intentos | Detalle técnico, reversible |
| 10 | Proveedor caído | Incidencia `provider_unavailable` | Una por proveedor y por hora (`dedupe_key` con fecha y hora) | Detalle técnico |

## Fuentes consultadas (documentación oficial, 2026-09-22)

**Stripe:**
- https://docs.stripe.com/payments/paymentintents/lifecycle
- https://docs.stripe.com/billing/subscriptions/overview
- https://docs.stripe.com/billing/revenue-recovery/smart-retries
- https://docs.stripe.com/billing/subscriptions/pause-payment
- https://docs.stripe.com/webhooks
- https://docs.stripe.com/api/idempotent_requests
- https://docs.stripe.com/refunds
- https://docs.stripe.com/api/refunds/create
- https://docs.stripe.com/declines/codes
- https://docs.stripe.com/disputes/how-disputes-work
- https://docs.stripe.com/currencies
- https://docs.stripe.com/testing
- https://docs.stripe.com/payments/oxxo
- https://docs.stripe.com/payments/mx-bank-transfers
- https://docs.stripe.com/payments/mx-installments
- https://packagist.org/packages/stripe/stripe-php

**Mercado Pago (México):**
- https://www.mercadopago.com.mx/developers/es/docs/subscriptions/overview
- https://www.mercadopago.com.mx/developers/es/docs/subscriptions/integration-configuration/subscription-no-associated-plan/authorized-payments
- https://www.mercadopago.com.mx/developers/es/docs/subscriptions/subscription-management
- https://www.mercadopago.com.mx/developers/es/docs/subscriptions/additional-content/payment-management
- https://www.mercadopago.com.mx/developers/es/reference/online-payments/subscriptions/update-preapproval/put
- https://www.mercadopago.com.mx/developers/es/docs/checkout-api-orders/notifications
- https://www.mercadopago.com.mx/developers/es/docs/checkout-api-orders/payment-management/refunds-cancellations
- https://www.mercadopago.com.mx/developers/es/docs/checkout-api-orders/chargebacks-notifications
- https://www.mercadopago.com.mx/developers/es/docs/checkout-api/additional-content/chargebacks
- https://www.mercadopago.com.mx/developers/es/docs/checkout-api-payments/response-handling/query-results
- https://www.mercadopago.com.mx/developers/es/news/2023/01/04/Idempotency-key-usage-will-be-mandatory
- https://www.mercadopago.com.mx/developers/es/docs/your-integrations/test/accounts

**No accesibles (se revisan en sandbox o con sesión en el portal):**
- https://developers.mercadopago.com/documentacion/pagos-recurrentes
- https://www.mercadopago.com.mx/ayuda/21660 (límites con tarjeta)
- referencia `GET /v1/chargebacks/{id}`
