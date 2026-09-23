# Modelo de datos (Fases 1 y 2)

Código, tablas y columnas en inglés; la interfaz muestra etiquetas en español (enums con
`getLabel()`). Decisiones en `docs/tecnico/decisiones/ADR-002` a `ADR-011`. Pagos en línea:
`fase-2-diseno-pagos.md` e `integraciones-pagos.md`.

## Relaciones

```
OrganizationSetting (una sola fila, id = 1)

User 1 ─── * Donor (registered_by_id)
User 1 ─── * Donation (registered_by_id, confirmed_by_id, cancelled_by_id)   [nulos en donativos en línea]

Donor 1 ─── 0..1 DonorTaxProfile
Donor * ─── * Tag                    (donor_tag)
Donor 1 ─── * Donation
Donor 1 ─── * Payment, * Subscription

Program 1 ─── * Campaign             (campaign.program_id opcional)
Donation / Payment / Subscription * ─── 0..1 Campaign | Program   (destino único, CHECK)

Subscription 1 ─── * Payment          (una mensualidad por periodo)
Payment 1 ─── * PaymentAttempt
Payment 1 ─── * Refund
Payment 1 ─── * PaymentDispute
Payment 1 ─── 0..1 Donation           (donations.payment_id único; solo si el pago quedó succeeded)
WebhookEvent ─── 0..1 Payment | Subscription | Refund | PaymentDispute (referencias resueltas)
PaymentIncident ─── Payment | PaymentAttempt | Subscription | Refund | PaymentDispute | WebhookEvent | proveedor
PaymentIncident 1 ─── * PaymentIncidentNote (solo inserción)

AuditLog * ─── 1 (cualquier modelo auditado)  auditable_type + auditable_id; source = procedencia

Futuro (tablas propias que apuntarán a donations; no existen aún):
  donation_receipts.donation_id · cfdis.donation_id
```

## Tablas

| Tabla | Propósito | Reglas en base de datos |
|---|---|---|
| `users` | Personal del CRM | `role` (4 valores), `deactivated_at`, `password_change_required_at` (ADR-010), `receives_payment_alerts` |
| `organization_settings` | Datos de la organización | `CHECK id = 1`; aviso de privacidad (URL y versión juntas); límites en línea > 0 y mínimo ≤ máximo |
| `programs` | Destinos permanentes | `slug` único; `name` único sin distinguir mayúsculas |
| `campaigns` | Esfuerzos de procuración | `slug` único; fin ≥ inicio; meta > 0; FK `program_id` restrict; trigger `campaigns_program_locked` |
| `donors` | Personas físicas y morales | `CHECK` por tipo; `display_name` generada; evidencia del aviso completa o nula |
| `donor_tax_profiles` | Datos fiscales 1:1 | `donor_id` único; se borra con el donante |
| `tags`, `donor_tag` | Etiquetas | nombre único sin distinguir mayúsculas |
| `donations` | Donativos | ver abajo |
| `subscriptions` | Donativos mensuales | proveedor válido; `(provider, external_id)` único; `idempotency_key` única; importe > 0; MXN; destino único; `interval = monthly`; cancelada con fecha y origen; cancelación del CRM con actor |
| `payments` | Cobros (único o mensualidad) | `(provider, external_id)` único; `idempotency_key` única; `(subscription_id, billing_period_start)` único; mensualidad ⇔ suscripción ⇔ periodo; importe > 0; MXN; exitoso con `succeeded_at` |
| `payment_attempts` | Intentos de cobro | `(payment_id, attempt_number)` único; `(provider, external_id)` único; `card_last4` = 4 dígitos; rechazado con categoría |
| `refunds` | Reembolsos | `idempotency_key` única; importe > 0; `other` exige comentario; del CRM ⇔ con actor; **trigger `refunds_within_payment_amount`** |
| `payment_disputes` | Disputas y contracargos | `(provider, external_id)` único |
| `webhook_events` | Bandeja de notificaciones | `(provider, external_event_id)` único; `payload` solo con la lista permitida |
| `payment_incidents` | Incidencias (RF-01) | `dedupe_key` única (hecho concreto); al menos una referencia; evidencia de revisión y resolución |
| `payment_incident_notes` | Notas de seguimiento | trigger `payment_incident_notes_append_only` |
| `audit_logs` | Bitácora | trigger `audit_logs_append_only`; `source` (user, webhook, job, synchronization, console; nulo antes de la Fase 2) |
| `exports`, `notifications` | Exportaciones de Filament y avisos | `notifications.data` en `jsonb` |

Todas las FK de pagos son `restrict`: nada se borra en cascada. Los importes son `numeric(12,2)`.

### `donations`

| Columna | Tipo | Regla |
|---|---|---|
| `origin` | texto | `manual` (lo registra una persona) / `online` (nace de un pago exitoso) |
| `payment_id` | FK | solo `online`; única (como máximo un donativo por pago) |
| `donor_id` | FK | obligatoria, `restrict` |
| `program_id`, `campaign_id` | FK | opcionales, `restrict`; no ambas a la vez |
| `kind` | texto | `monetary` / `in_kind` (en línea: solo `monetary`) |
| `manual_payment_method` | texto | `cash`, `bank_transfer`, `check`, `bank_deposit`; obligatoria solo en dinero **manual**; nunca `card` (antes `payment_method`) |
| `amount` | `numeric(12,2)` | > 0; en especie es el valor asignado |
| `currency` | `char(3)` | `MXN` |
| `received_on` | fecha | en línea: fecha del cobro en la zona horaria de la organización |
| `status` | texto | `pending`, `confirmed`, `cancelled` con evidencia coherente (CHECK); en línea nace `confirmed` |
| `in_kind_description` | texto | obligatoria solo si es especie |
| `tax_receipt_requested` | bool | solicitó recibo deducible (informativo; no decide la emisión de CFDI) |
| `registered_by_id`, `confirmed_*`, `cancelled_*`, `cancellation_reason` | — | trazabilidad; en línea sin actor humano (la evidencia es el pago) |

CHECKs de origen:
- `donations_origin_manual`: `manual` ⇒ `registered_by_id` y sin `payment_id`.
- `donations_origin_online`: `online` ⇒ `payment_id`, sin actor humano ni forma de pago manual, en dinero y nunca `pending`.

Trigger `donations_no_delete`: ningún donativo se elimina. No existe usuario "sistema".

### Reserva de saldo en reembolsos

Reservan importe los reembolsos `pending` (pueden completarse en el proveedor) y `succeeded`. `failed` y `cancelled` lo liberan.

La suma de los que reservan nunca supera `payments.amount`. Lo garantizan dos barreras:
- la Action `RequestRefund`, con el Payment bloqueado;
- el trigger `refunds_within_payment_amount`, que también bloquea el Payment.

`RefundState` (sin reembolso / parcial / total) se calcula con la suma de reembolsos `succeeded`; no se guarda.

## Extensiones y funciones de PostgreSQL

| Objeto | Para qué |
|---|---|
| Extensión `unaccent` | Búsqueda sin acentos (ADR-009) |
| `f_unaccent(text)` | Envoltura `IMMUTABLE` de `unaccent` |
| `donations_prevent_delete()` | Trigger de `donations` |
| `campaigns_lock_program()` | Trigger de `campaigns` |
| `audit_logs_prevent_changes()` | Trigger de `audit_logs` |
| `refunds_within_payment_amount()` | Trigger de `refunds` (suma ≤ importe del pago) |
| `payment_incident_notes_prevent_changes()` | Trigger de `payment_incident_notes` |

Las funciones usan `create or replace`, porque `migrate:fresh` borra tablas pero no funciones.

## Dónde vive la lógica

| Área | Actions (`app/Actions`) |
|---|---|
| Usuarios | `CreateUser`, `CreateAdministrator`, `UpdateUser`, `SetUserActive`, `EnsureActiveAdministratorRemains`, `ResetUserPassword`, `SetPaymentAlertPreference` |
| Donantes | `SaveDonor`, `SaveDonorTaxProfile`, `FindDonorDuplicates`, `SetDonorArchived`, `DeleteDonor` |
| Programas y campañas | `SaveProgram`, `DeleteProgram`, `SaveCampaign`, `DeleteCampaign` |
| Donativos | `RegisterDonation`, `UpdatePendingDonation`, `ConfirmDonation`, `CancelDonation` |
| Organización | `UpdateOrganizationSettings` (incluye límites en línea) |
| Pagos | `StartOneTimeDonation`, `ValidateOnlineDonationAmount`, `ApplyProviderSnapshot`, `SyncPayment`, `CreateDonationFromPayment` |
| Suscripciones | `StartMonthlyDonation`, `SyncSubscription`, `PauseSubscription`, `ResumeSubscription`, `CancelSubscription` |
| Reembolsos | `RequestRefund`, `SubmitRefundToProvider`, `SyncRefund` |
| Disputas | `SyncDispute` |
| Incidencias | `OpenPaymentIncident`, `TakeIncidentForReview`, `AddIncidentNote`, `ResolveIncident` |
| Webhooks | `RecordWebhookEvent`, `RetryWebhookEvent` |

Jobs (`app/Jobs`):
- `ProcessWebhookEvent`: consulta el proveedor sin transacción abierta y luego aplica con bloqueo;
- `SendPaymentIncidentAlert`: aviso en la campana, idempotente;
- `ReconcilePayments`: cada 15 minutos.

Integraciones en `app/Payments` (contratos, snapshots, registro de pasarelas, `FakeGateway`, Stripe y Mercado Pago).

Los Resources de Filament solo arman pantallas y llaman a estas Actions. Las operaciones que tocan
varias tablas usan `DB::transaction`, y las de cambio de estado bloquean la fila
(`lockForUpdate`) para evitar confirmaciones dobles.
