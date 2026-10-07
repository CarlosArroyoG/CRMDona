# Fase 8 — Gestión de relaciones con donantes

**Estado: cerrada localmente** (2026-10-06)

## Objetivo

Agregar la capa de **relación con el donante** que no existía: actividades, tareas, responsable
asignado con historial, próxima acción y un timeline 360° en la ficha del donante, sin tocar
donativos, pagos, comunicaciones, CFDI ni correo saliente.

## Alcance

- Actividades (`DonorActivity`): interacción registrada a mano, con canal, resultado y próxima
  acción sugerida.
- Tareas (`Task`): pendiente accionable con prioridad y fecha límite, de un donante o general.
- Responsable asignado con historial (`DonorAssignment`): solo Administrador o Coordinador pueden
  serlo; reasignar nunca borra el historial.
- Próxima acción derivada (no guardada) y timeline 360° (agregador de consulta, no tabla copiada).
- Panel operativo: widget de conteos (`DonorRelationsWork`) y cartera asignada (`MyPortfolio`).
- Integración en la ficha del donante existente (`DonorResource`): tres relation managers, una
  sección de responsable/próxima acción y una sección de timeline.

Fuera de alcance (ver "Pendientes"): alertas push por tareas vencidas, paginación profunda del
timeline, "rol de relación" distinto de "responsable".

## Decisiones (detalle en ADR-013 y `docs/tecnico/gestion-relaciones-donantes.md`)

1. Tres tablas, no dos ni cuatro: Actividad ≠ Tarea ≠ Responsable asignado, cada una con invariantes
   que no coinciden entre sí.
2. `tasks.donor_id` opcional (confirmado con la institución); `donor_activities.donor_id`
   obligatorio.
3. Solo Administrador o Coordinador pueden ser el responsable asignado (confirmado con la
   institución); los cuatro roles pueden verlo.
4. "Próxima acción" se deriva en consulta; no hay campo en `donors`.
5. El timeline no copia datos: agrega consultas acotadas de las fuentes reales, cada una gateada por
   el permiso que ya la protege en el resto del CRM.
6. La bitácora genérica del donante no entra al timeline (tiene su propia pantalla).
7. `next_action` de una actividad no crea una tarea automáticamente (efecto oculto evitado).

## Funcionalidades implementadas

- Registrar, completar, reprogramar y cancelar actividades.
- Crear, completar y cancelar tareas (ligadas a un donante o generales); editarlas mientras están
  abiertas.
- Asignar y reasignar al responsable de un donante, con historial completo visible.
- Próxima acción visible en la ficha del donante y en "Mi cartera de donantes".
- Timeline 360° en la ficha del donante, con 14 fuentes reales, cada una respetando su propio
  permiso.
- Recursos de nivel superior **Actividades** y **Tareas** (grupo "Recaudación"), más tres relation
  managers nuevos en la ficha del donante.
- Panel operativo: "Relación con donantes" (conteos) y "Mi cartera de donantes" (tabla), separados
  de las métricas financieras existentes.

## Modelos creados

`DonorActivity`, `Task`, `DonorAssignment` — ver `app/Models`. `Donor` gana las relaciones
`activities()`, `tasks()`, `currentAssignment()` y `assignmentHistory()`.

## Migraciones

- `2026_10_07_000002_create_donor_activities_table`
- `2026_10_07_000003_create_tasks_table`
- `2026_10_07_000004_create_donor_assignments_table`

(Renombradas desde `2026_10_06_000001/2/3` al integrar con la carga CSV y los envíos masivos de
otra sesión, que ya ocupaban esos mismos sufijos de fecha para tablas distintas — `imports` y
`bulk_messages`. Sin cambio de contenido, solo de nombre de archivo y su fila en `migrations`.)

Las tres son reversibles (`Schema::dropIfExists`); ninguna modifica tablas existentes.

## Permisos

Cinco permisos nuevos en `App\Enums\Permission` (matriz actualizada en ADR-002 y en
`tests/Unit/Enums/PermissionMatrixTest.php`): `donor_relations.view`, `donor_relations.manage`,
`donor_relations.assign`, `tasks.view`, `tasks.manage`.

## Auditoría

`DonorActivity`, `Task` y `DonorAssignment` son auditables (ADR-006), con sus campos de valor y de
solo-nombre documentados en `docs/tecnico/gestion-relaciones-donantes.md` §7. Tres eventos nuevos en
`App\Enums\AuditEvent`: `Completed`, `Rescheduled`, `ResponsibleAssigned`; se reutiliza `Cancelled`.

## Pruebas

- `tests/Feature/DonorRelations/{ActivityTest,TaskTest,DonorAssignmentTest,TimelineTest,NextActionTest,ConstraintsTest}.php`:
  transiciones de estado, restricciones por permiso y por estado, revalidación de permiso dentro de
  las Actions, historial de reasignación, agregación del timeline con aislamiento por permiso,
  derivación de la próxima acción, `CHECK` de PostgreSQL.
- `tests/Feature/Performance/QueryCountTest.php`: listados de actividades y tareas, timeline del
  donante y los dos widgets nuevos, sin consultas por fila.
- Matriz de permisos actualizada.
- Suite completa: 755 pruebas, 3422 aserciones. Pint: 504 archivos correctos. Larastan nivel 8: sin
  errores. Build de assets (`docker build --target assets`) e imagen de producción
  (`docker build --target prod`): completados sin errores.

## Riesgos

- El timeline no pagina en profundidad: para un donante con mucho volumen en una sola fuente, "ver
  más" implicaría ampliar el límite, no una paginación real. Volumen esperado bajo por donante.
- Prueba manual en navegador **no se hizo en este entorno** (no hay Chrome/Playwright disponible):
  se verificó en su lugar con `Livewire::test()` (renderiza el HTML real de cada página y widget
  nuevos), con el build de Vite dentro de Docker (`docker build --target assets`, que compiló el
  CSS nuevo sin errores) y con la imagen de producción completa (`docker build --target prod`).

## Pendientes

Registrados en `docs/pendientes.md`:

- Alertas push (campana/correo) por tareas vencidas o seguimientos atrasados: no se implementaron
  en esta fase: el panel operativo ya las muestra, pero no notifica proactivamente.
- Verificar visualmente en navegador (clic a clic) la ficha del donante: timeline, responsable,
  próxima acción, actividades y tareas.

## Decisiones descartadas

- **Una sola tabla para actividades y tareas con un discriminador:** descartada por forzar columnas
  sin sentido en una u otra fila (canal vs. prioridad, resultado vs. fecha límite).
- **Incluir la bitácora genérica del donante en el timeline:** descartada por duplicar la pantalla
  de bitácora existente.
- **Que completar una actividad cree una tarea de seguimiento automáticamente:** descartada por ser
  un efecto oculto que además exigiría inventar una fecha límite.
- **Un campo `role` en `donor_assignments`** para distintos tipos de responsable: descartado por
  especulativo; esta fase solo tiene un tipo de responsabilidad (el procurador asignado).

## Integración con fases anteriores

No se modificó ninguna regla de donativos, pagos, CFDI, correo saliente ni comunicaciones. El
timeline y el panel operativo **leen** esas tablas sin alterarlas. La ficha del donante
(`DonorResource`, Fase 1) se extendió con nuevas secciones y relation managers, siguiendo
exactamente el patrón ya usado por `DonationsRelationManager`. El grupo de navegación "Recaudación"
(Fase 1) ahora también alberga Actividades y Tareas.
