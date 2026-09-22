# ADR-006 — Bitácora de auditoría propia

- **Estado:** Aceptado
- **Fecha:** 2026-09-22

## Decisión

Implementación propia, sin paquete: tabla `audit_logs` + trait `App\Models\Concerns\Auditable`.

- **Qué se guarda:** quién (`user_id`, nulo si fue la consola), qué (`auditable_type`,
  `auditable_id`, `event`, `changed_fields`) y cuándo (`created_at`).
- **Lista cerrada por modelo** (sin copias indiscriminadas):
  - `auditValueFields()`: se guarda valor anterior y nuevo.
  - `auditNameOnlyFields()`: solo se registra *que* el campo cambió, sin su valor (datos personales
    y fiscales).
  - Cualquier otro campo no se registra (marcas de tiempo, `display_name`, `remember_token`).

| Modelo | Con valor | Solo nombre del campo |
|---|---|---|
| Donor | type, archived_at, accepts_communications, communications_consent_updated_at, privacy_notice_version, privacy_notice_accepted_at, registered_by_id | first_name, last_name, second_last_name, legal_name, contact_name, email, phone, birth_date, notes |
| DonorTaxProfile | tax_regime, cfdi_use | rfc, tax_name, tax_postal_code |
| Donation | todos los datos del donativo y su trazabilidad | notes |
| Program, Campaign, Tag, OrganizationSetting | todos los campos de negocio | — |
| User | role, deactivated_at | name, email, password |

- **Eventos de negocio** (`AuditEvent`): confirmar, cancelar, archivar, reactivar, desactivar y
  cambio de etiquetas. Se indican con `auditAs()` antes de guardar y **sustituyen** al "modificado"
  genérico (sin registros duplicados). Las etiquetas viven en una tabla pivote y se registran
  explícitamente con sus nombres.
- **Solo inserción:** el trigger `audit_logs_append_only` impide `UPDATE` y `DELETE`. En Filament
  la bitácora es de solo lectura y solo para el Administrador.
- **Etiquetas visibles** en `lang/es/audit.php`; una prueba exige que todo campo auditado tenga su
  etiqueta.
- Se escribe en la misma transacción que el cambio.

## Límites conocidos

- Los `update`/`delete` masivos por query builder no disparan eventos de modelo. Regla: los datos
  de negocio se escriben solo mediante las Actions.
- La retención de la bitácora está **PENDIENTE DE VALIDACIÓN LEGAL/FISCAL**; hoy no se borra nada.
