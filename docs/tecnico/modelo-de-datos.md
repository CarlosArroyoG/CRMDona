# Modelo de datos (Fase 1)

Código, tablas y columnas en inglés; la interfaz muestra etiquetas en español (enums con
`getLabel()`). Decisiones en `docs/tecnico/decisiones/ADR-002` a `ADR-009`.

## Relaciones

```
OrganizationSetting (una sola fila, id = 1)

User 1 ─── * Donor (registered_by_id)
User 1 ─── * Donation (registered_by_id, confirmed_by_id, cancelled_by_id)

Donor 1 ─── 0..1 DonorTaxProfile
Donor * ─── * Tag                    (donor_tag)
Donor 1 ─── * Donation

Program 1 ─── * Campaign             (campaign.program_id opcional)
Donation * ─── 0..1 Campaign   ┐ destino único (CHECK):
Donation * ─── 0..1 Program    ┘ campaña, programa o fondo general

AuditLog * ─── 1 (cualquier modelo auditado)  auditable_type + auditable_id

Futuro (tablas propias que apuntarán a donations; no existen aún):
  payments.donation_id · donation_receipts.donation_id · cfdis.donation_id
```

## Tablas

| Tabla | Propósito | Reglas en base de datos |
|---|---|---|
| `users` | Personal del CRM | `role` (4 valores), `deactivated_at` |
| `organization_settings` | Datos de la organización | `CHECK id = 1`; URL y versión del aviso de privacidad juntas |
| `programs` | Destinos permanentes | `slug` único; `name` único sin distinguir mayúsculas |
| `campaigns` | Esfuerzos de procuración | `slug` único; fin ≥ inicio; meta > 0; FK `program_id` restrict; trigger `campaigns_program_locked` |
| `donors` | Personas físicas y morales | `CHECK` por tipo; `display_name` generada; evidencia del aviso completa o nula |
| `donor_tax_profiles` | Datos fiscales 1:1 | `donor_id` único; se borra con el donante |
| `tags`, `donor_tag` | Etiquetas | nombre único sin distinguir mayúsculas |
| `donations` | Donativos | ver abajo |
| `audit_logs` | Bitácora | trigger `audit_logs_append_only` (sin UPDATE ni DELETE) |
| `exports`, `notifications` | Exportaciones de Filament y sus avisos | `notifications.data` en `jsonb` |

### `donations`

| Columna | Tipo | Regla |
|---|---|---|
| `donor_id` | FK | obligatoria, `restrict` |
| `program_id`, `campaign_id` | FK | opcionales, `restrict`; no ambas a la vez |
| `kind` | texto | `monetary` / `in_kind` |
| `payment_method` | texto | `cash`, `bank_transfer`, `check`, `bank_deposit`; obligatoria solo si es dinero |
| `amount` | `numeric(12,2)` | > 0; en especie es el valor asignado |
| `currency` | `char(3)` | `MXN` |
| `received_on` | fecha | — |
| `status` | texto | `pending`, `confirmed`, `cancelled` con evidencia coherente (CHECK) |
| `in_kind_description` | texto | obligatoria solo si es especie |
| `tax_receipt_requested` | bool | solicitó recibo deducible (para la fase de CFDI) |
| `registered_by_id`, `confirmed_*`, `cancelled_*`, `cancellation_reason` | — | trazabilidad |

Trigger `donations_no_delete`: ningún donativo se elimina.

## Extensiones y funciones de PostgreSQL

| Objeto | Para qué |
|---|---|
| Extensión `unaccent` | Búsqueda sin acentos (ADR-009) |
| `f_unaccent(text)` | Envoltura `IMMUTABLE` de `unaccent` |
| `donations_prevent_delete()` | Trigger de `donations` |
| `campaigns_lock_program()` | Trigger de `campaigns` |
| `audit_logs_prevent_changes()` | Trigger de `audit_logs` |

Las funciones usan `create or replace`, porque `migrate:fresh` borra tablas pero no funciones.

## Dónde vive la lógica

| Área | Actions (`app/Actions`) |
|---|---|
| Usuarios | `CreateUser`, `CreateAdministrator`, `UpdateUser`, `SetUserActive`, `EnsureActiveAdministratorRemains` |
| Donantes | `SaveDonor`, `SaveDonorTaxProfile`, `FindDonorDuplicates`, `SetDonorArchived`, `DeleteDonor` |
| Programas y campañas | `SaveProgram`, `DeleteProgram`, `SaveCampaign`, `DeleteCampaign` |
| Donativos | `RegisterDonation`, `UpdatePendingDonation`, `ConfirmDonation`, `CancelDonation` |
| Organización | `UpdateOrganizationSettings` |

Los Resources de Filament solo arman pantallas y llaman a estas Actions. Las operaciones que tocan
varias tablas usan `DB::transaction`, y las de cambio de estado bloquean la fila
(`lockForUpdate`) para evitar confirmaciones dobles.
