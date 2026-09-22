# ADR-008 — Conservación y eliminación de datos

- **Estado:** Aceptado
- **Fecha:** 2026-09-22

## Decisión (política conservadora)

| Entidad | Regla | Dónde se garantiza |
|---|---|---|
| Donativo | **Nunca se elimina**; se cancela con motivo, usuario y fecha | Policy (sin `delete`) + trigger `donations_no_delete` |
| Donante con donativos | Nunca se elimina físicamente; se **archiva** | Policy + Action + FK `restrict` |
| Donante sin donativos | Solo el Administrador lo elimina (con sus datos fiscales y etiquetas) | Policy + Action |
| Programa o campaña con donativos | No se elimina; se archiva por estado | Policy + Action + FK `restrict` |
| Programa con campañas | No se elimina | Policy + Action + FK `restrict` |
| Programa o campaña sin donativos | Solo el Administrador lo elimina | Policy + Action |
| Usuario | No se elimina; se desactiva | Policy (sin `delete`) + FKs `restrict` |
| Bitácora | Nunca se modifica ni elimina desde la aplicación | Policy + trigger `audit_logs_append_only` |

- **Sin borrado automático por antigüedad.** La única purga automática es la del archivo temporal
  de exportación (ADR-007).
- **Retención:** plazo de conservación de donativos y bitácora **PENDIENTE DE VALIDACIÓN
  LEGAL/FISCAL** (se mencionó el art. 30 del CFF como referencia; no está codificado).
- **Derechos ARCO:** las solicitudes de cancelación que procedan se atenderán en una fase futura con
  un proceso de **anonimización** (no de borrado), para conservar la trazabilidad financiera.
