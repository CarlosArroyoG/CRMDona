# ADR-013 — Gestión de relaciones con donantes: actividades, tareas y responsable asignado

- **Estado:** Aceptado
- **Fecha:** 2026-10-06
- **Diseño completo:** `docs/tecnico/gestion-relaciones-donantes.md`.

## Contexto

El CRM cubre el ciclo de donativos, pagos en línea, comunicaciones y CFDI externo, pero no tenía
ninguna entidad propia para la **relación humana** con el donante: qué se hizo, qué falta por
hacer, quién es responsable de cada donante y un historial de ese seguimiento. No existía código,
migración ni documento previo sobre esto — es terreno nuevo, no una extensión de algo existente.

## Decisión

1. **Tres tablas nuevas, no dos.** `donor_activities` (interacción registrada a mano, con canal,
   resultado y próxima acción sugerida), `tasks` (pendiente accionable con prioridad y fecha
   límite) y `donor_assignments` (historial de responsables). Se evaluó una tabla única para
   actividades y tareas con un discriminador y se descartó: una actividad registra un canal de
   interacción que ya ocurrió o se planeó; una tarea es un pendiente genérico sin canal. Forzarlas
   en una tabla habría dejado columnas sin sentido en una u otra fila.
2. **Las tareas no siempre son de un donante.** `tasks.donor_id` es opcional (decisión confirmada
   con la institución): el Coordinador y el Contador también usan tareas generales ("organizar
   evento de fin de año"). Las actividades sí siempre llevan donante: son, por definición, una
   interacción de procuración con alguien.
3. **`donor_assignments` es la primera tabla con historial tipo "SCD2"** del repositorio (una fila
   por periodo de vigencia, `ended_at IS NULL` = vigente). Reasignar nunca edita ni borra una fila
   cerrada: cierra la vigente y crea una nueva, dentro de una transacción con `lockForUpdate()`
   sobre la fila vigente. Un índice único parcial (`donor_id` where `ended_at is null`) es la
   barrera real contra dos responsables vigentes a la vez.
4. **Solo Administrador o Coordinador de procuración pueden ser el responsable asignado**
   (confirmado con la institución): la procuración de fondos es el rol dedicado a esta relación.
   Cualquiera de los cuatro roles puede ver quién es el responsable y el historial.
5. **"Próxima acción" se deriva, no se guarda.** No hay ningún campo en `donors` para esto: es la
   más próxima entre la actividad programada y la tarea abierta con fecha límite más cercanas de
   ese donante (`App\DonorRelations\NextAction`). Guardar un campo propio habría creado una segunda
   fuente de verdad que se desincroniza en cuanto alguien edita una fecha sin pasar por ahí.
6. **El timeline 360° es un agregador de consulta, no una tabla copiada.** `App\DonorRelations\Timeline`
   hace una query acotada (`limit()`) por cada fuente real (actividades, tareas, responsable,
   donativos, pagos, intentos fallidos, donativos mensuales, comunicaciones, solicitudes de pago,
   reembolsos, disputas, incidencias, recibos, CFDI externo, avisos a Contabilidad), activada solo
   si el usuario tiene el permiso que ya protege esa fuente en el resto del CRM. No se inventa un
   permiso nuevo que vea más de lo que ya se podía ver, y no se copian datos a una tabla
   `timeline_events`: eso duplicaría el origen de la verdad de cada fuente.
7. **La bitácora genérica del donante no entra al timeline.** Los cambios de consentimiento o
   archivado de `Donor` ya tienen su propia pantalla con `audit.view`; mezclarlos aquí habría creado
   una segunda forma de ver lo mismo.
8. **Permisos nuevos** (`App\Enums\Permission`): `donor_relations.view` (los cuatro roles),
   `donor_relations.manage` y `donor_relations.assign` (Administrador y Coordinador), `tasks.view`
   (los cuatro roles) y `tasks.manage` (Administrador, Coordinador y Contador — las tareas no son
   solo de procuración).
9. **Auditoría con eventos propios.** `AuditEvent` gana `Completed`, `Rescheduled` y
   `ResponsibleAssigned`; se reutiliza `Cancelled` (ya existía) en vez de duplicarlo.

## Consecuencias

- Primera tabla del repositorio con un índice único parcial como barrera de "solo un vigente a la
  vez": el patrón queda documentado aquí para reutilizarse si otra relación lo necesita.
- El timeline no se pagina en profundidad (solo trae los más recientes por fuente): para un
  donante con miles de eventos en una sola fuente, "ver más" implicaría ampliar el límite, no
  paginar de verdad. Se acepta porque el volumen por donante es bajo en la práctica; si deja de
  serlo, se documenta como pendiente.
- Ningún permiso, regla de pagos, CFDI, correo o fiscalidad existente cambió.
