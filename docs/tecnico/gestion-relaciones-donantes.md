# Gestión de relaciones con donantes

**Fuente de verdad** desde el cierre de esta fase (2026-10-06, ADR-013). Cubre actividades, tareas,
responsable asignado, próxima acción y el timeline 360° del donante.

## 1. Decisión y alcance

- El CRM no tenía ninguna entidad propia para la relación humana con el donante: esta fase la
  agrega sin tocar donativos, pagos, comunicaciones, CFDI ni correo saliente.
- Tres entidades nuevas: `DonorActivity` (interacción registrada a mano), `Task` (pendiente
  accionable) y `DonorAssignment` (historial de responsables). Por qué tres y no dos o cuatro, y
  por qué `Task` no siempre lleva donante: ver ADR-013.
- El timeline 360° y la "próxima acción" **no son tablas**: se calculan en consulta a partir de las
  entidades reales (`App\DonorRelations`).

## 2. Entidades

### 2.1 `DonorActivity` (tabla `donor_activities`)

Interacción humana con un donante: llamada, visita, correo o WhatsApp **registrados a mano** por el
personal. **No sustituye `Communication`**: esa tabla sigue siendo el correo o WhatsApp que ya envía
el sistema (agradecimiento, cumpleaños, solicitud de pago). Una `DonorActivity` tipo `whatsapp` es,
por ejemplo, "le escribí informalmente para preguntar si recibió el recibo", no el "Preparar
WhatsApp" de cumpleaños (que sigue siendo su propio botón y su propia fila en `communications`).

| Columna | Significado |
|---|---|
| `donor_id` | Obligatorio: una actividad es, por definición, sobre un donante. |
| `assigned_to_id` | Responsable del seguimiento (no necesariamente quien la registró). |
| `created_by_id` | Quién la registró. |
| `type` | `call`, `whatsapp`, `email`, `meeting`, `visit`, `follow_up`, `note`, `proposal`, `thanks`, `other`. |
| `subject`, `description` | Asunto y detalle libre. |
| `scheduled_at` | Fecha programada (si `status = scheduled`). |
| `completed_at`, `completed_by_id` | Cuándo y quién la completó. |
| `result` | Qué pasó (libre, solo si se completó). |
| `next_action` | Nota libre de seguimiento sugerido. **No crea una tarea por sí misma** (ver §6). |
| `status` | `scheduled`, `completed`, `cancelled`. |
| `cancelled_at`, `cancelled_by_id` | Cuándo y quién la canceló. |

Invariantes en PostgreSQL (`CHECK`): `type` y `status` están en su catálogo; `completed_at` y
`completed_by_id` van juntos y solo si `status = completed`; `cancelled_at` y `cancelled_by_id` van
juntos y solo si `status = cancelled`.

Transiciones: `scheduled → completed` (`CompleteActivity`), `scheduled → cancelled`
(`CancelActivity`), `scheduled → scheduled` con otra fecha (`RescheduleActivity`). Una actividad
completada o cancelada no se vuelve a editar ni a transicionar: el historial de lo que ocurrió no
se edita.

### 2.2 `Task` (tabla `tasks`)

Pendiente accionable con prioridad y fecha límite. **No siempre es de un donante**: `donor_id` es
opcional porque el Coordinador y el Contador también tienen pendientes generales ("organizar evento
de fin de año") que no son una interacción con nadie en particular.

| Columna | Significado |
|---|---|
| `donor_id` | Opcional. |
| `assigned_to_id`, `created_by_id` | Responsable y creador. |
| `title`, `description` | Qué hay que hacer. |
| `priority` | `low`, `medium`, `high`. |
| `due_date` | Fecha límite (opcional). |
| `status` | `pending`, `in_progress`, `done`, `cancelled`. |
| `completed_at`, `completed_by_id` / `cancelled_at`, `cancelled_by_id` | Igual que en `DonorActivity`. |

Transiciones con Action propia: `CompleteTask`, `CancelTask` (desde `pending` o `in_progress`).
Cambiar título, descripción, prioridad, fecha límite, donante o responsable mientras está abierta es
una edición normal (`UpdateTask`, Policy `update`), no una transición de estado.

### 2.3 `DonorAssignment` (tabla `donor_assignments`)

Historial de quién es responsable (procurador) de un donante. Es la primera tabla del repositorio
con un historial tipo "SCD2": una fila por periodo de vigencia.

| Columna | Significado |
|---|---|
| `donor_id`, `user_id` | El donante y su responsable en ese periodo. |
| `assigned_by_id` | Quién hizo la asignación. |
| `started_at` | Cuándo empezó a ser responsable. |
| `ended_at` | `NULL` = vigente; con fecha = periodo cerrado. |
| `note` | Nota libre opcional (por ejemplo, el motivo del cambio). |

Invariante de aplicación + base de datos: **nunca dos filas vigentes para el mismo donante.**
Índice único parcial `donor_assignments_one_active` en `donor_id` **where `ended_at is null`**.
Reasignar (`AssignDonorResponsible`) nunca edita ni borra la fila vigente: dentro de una
transacción, bloquea la fila vigente (`lockForUpdate`), le pone `ended_at = now()` y crea una fila
nueva. Solo **Administrador o Coordinador de procuración** pueden ser el `user_id` asignado (se
valida en la propia Action, no solo en el formulario): es el rol dedicado a esta relación.

`Donor` gana `currentAssignment()` (`hasOne`, vigente), `assignmentHistory()` (`hasMany`, más
reciente primero), `activities()` y `tasks()` (`hasMany`).

## 3. Próxima acción: una sola fuente de verdad

No existe ningún campo `next_action` en `donors`. La próxima acción se **deriva** en consulta
(`App\DonorRelations\NextAction::for(Donor $donor): ?NextActionDto`): la más próxima entre

1. la `DonorActivity` con `status = scheduled` y `scheduled_at` más cercano de ese donante, y
2. el `Task` de ese donante con `status` en `pending`/`in_progress` y `due_date` más cercano.

Una tarea sin `due_date` no participa (no hay fecha que comparar). Si no hay ninguna de las dos,
devuelve `null` ("Sin próxima acción pendiente"). Guardar esto como columna habría creado una
segunda fuente de verdad que se desincroniza en cuanto alguien reprograma o completa algo sin pasar
por ahí.

## 4. Timeline 360°: agregador de consulta, no tabla copiada

`App\DonorRelations\Timeline::for(Donor $donor, User $viewer, int $limit = 25)` devuelve una
colección de `TimelineEntry` (tipo, fecha, título, descripción corta, actor, enlace), más recientes
primero. Por cada fuente real hace **una sola query acotada** (`where donor_id -> latest() ->
limit($limit)`), y la fuente **solo se consulta si `$viewer` tiene el permiso que ya protege esa
fuente en el resto del CRM**: no se inventa un permiso nuevo que vea más de lo que ya se podía ver.

| Fuente | Permiso que la habilita (ya existente) |
|---|---|
| Actividades, responsable | `donor_relations.view` |
| Tareas | `tasks.view` |
| Donativos | `donations.view` |
| Pagos | `payments.view` |
| Intentos de pago fallidos | `payments.view_technical` |
| Donativos mensuales | `subscriptions.view` |
| Comunicaciones | `communications.view` |
| Solicitudes de pago | `payments.request` |
| Reembolsos | `refunds.request` |
| Disputas | `disputes.view` |
| Incidencias | `incidents.view` |
| Recibos simples | `receipts.view` |
| CFDI externo | `cfdi.view` |
| Avisos a Contabilidad | `accounting.process` |

**Fuera de alcance deliberado:** la bitácora genérica del propio `Donor` (consentimientos,
archivado) no aparece aquí. Ya tiene su propia pantalla (`audit.view`); mezclarla habría creado una
segunda forma de ver lo mismo.

**Rendimiento:** el número de queries es fijo (una por fuente habilitada), no crece con el número de
filas de cada fuente ni con el número de donantes (`tests/Feature/Performance/QueryCountTest.php`).
Para un donante con mucho volumen en una sola fuente, el timeline solo trae los `$limit` más
recientes de esa fuente — no hay paginación profunda; ver "pendiente" en ADR-013.

Se muestra en la ficha del donante con un `ViewEntry` de Filament y una vista Blade propia
(`resources/views/filament/infolists/donor-timeline.blade.php`), no con un RelationManager: no es
una sola relación Eloquent, es la unión de varias.

## 5. Autorización

### 5.1 Permisos (`App\Enums\Permission`, matriz en ADR-002)

| Permiso | Roles | Para qué |
|---|---|---|
| `donor_relations.view` | A, C, Co, L | Ver actividades, responsable (vigente e historial), timeline. |
| `donor_relations.manage` | A, C | Crear, completar, reprogramar y cancelar actividades. |
| `donor_relations.assign` | A, C | Asignar o reasignar al responsable. |
| `tasks.view` | A, C, Co, L | Ver tareas (de un donante o generales). |
| `tasks.manage` | A, C, Co | Crear, completar y cancelar tareas (no son solo de procuración). |

### 5.2 Dos niveles, siempre

1. **UI/Policy:** `DonorActivityPolicy`, `TaskPolicy` y una habilidad nueva en `DonorPolicy`
   (`assignResponsible`). Las habilidades de estado (`complete`, `reschedule`, `cancel`) exigen
   además que el registro esté en el estado que lo permite.
2. **La propia Action.** Cada Action (`CreateActivity`, `CompleteActivity`, `RescheduleActivity`,
   `CancelActivity`, `UpdateActivity`, `CreateTask`, `CompleteTask`, `CancelTask`, `UpdateTask`,
   `AssignDonorResponsible`) recibe al usuario actor y revalida el permiso con
   `$actor->hasPermission(...)`, igual que `RequestRefund` o `ConfirmDonation` — el estándar que el
   pendiente #47 señala como el correcto (no el que señala como débil). `AssignDonorResponsible`
   valida además que el `user_id` recibido tenga rol Administrador o Coordinador, no solo que el
   actor tenga permiso de asignar.

## 6. Por qué `next_action` no crea una tarea automáticamente

Se evaluó que completar una actividad con una "próxima acción" creara una `Task` de seguimiento
automáticamente. Se descartó: habría sido un efecto oculto (una actividad silenciosamente genera
otro registro con su propio responsable y fecha límite inventada) y habría exigido inventar una
fecha límite que nadie capturó. En vez de eso, `next_action` queda como nota de contexto de esa
actividad puntual, y la interfaz ofrece un botón para crear la tarea a mano, con su fecha límite
real, cuando hace falta.

## 7. Auditoría (ADR-006)

| Modelo | `auditValueFields()` | `auditNameOnlyFields()` |
|---|---|---|
| `DonorActivity` | `donor_id, assigned_to_id, type, status, scheduled_at, completed_at, completed_by_id, cancelled_at, cancelled_by_id` | `subject, description, result, next_action` |
| `Task` | `donor_id, assigned_to_id, priority, status, due_date, completed_at, completed_by_id, cancelled_at, cancelled_by_id` | `title, description` |
| `DonorAssignment` | `donor_id, user_id, assigned_by_id, started_at, ended_at` | `note` |

Eventos nuevos en `App\Enums\AuditEvent`: `Completed`, `Rescheduled`, `ResponsibleAssigned`. Se
reutiliza `Cancelled` (ya existía) para actividades y tareas canceladas.

## 8. Concurrencia

- **Reasignar responsable:** transacción con `lockForUpdate()` sobre la fila vigente de
  `donor_assignments`; si dos reasignaciones llegan a la vez, la segunda ve ya cerrada la que acaba
  de abrir la primera. El índice único parcial es la segunda barrera si algo escribe sin pasar por
  la Action.
- **Completar/reprogramar/cancelar actividad o tarea:** transacción con `lockForUpdate()` sobre el
  registro; la segunda solicitud simultánea ve el estado ya cambiado y falla con un mensaje claro
  ("Solo se completan actividades programadas"), no con un error genérico.
- **Crear actividad o tarea:** sin bloqueo (no hay fila existente con la que competir), igual que
  `CreatePaymentRequest`.

## 9. Panel operativo

Separado de las métricas financieras (`FundraisingOverview`) y de pagos (`DailyWork`), porque mide
procuración, no dinero:

- **`DonorRelationsWork`** (widget de conteos, como `DailyWork`): mis tareas de hoy, mis tareas
  vencidas, mis seguimientos próximos (actividades programadas en 7 días) y donantes activos sin
  próxima acción pendiente. Cada conteo es una sola consulta (sin N+1).
- **`MyPortfolio`** (tabla): los donantes de los que el usuario es responsable vigente, con su
  próxima acción. La próxima acción se trae con subconsultas correlacionadas en la misma query
  (`min(scheduled_at)`, `min(due_date)`), nunca llamando a `NextAction` por fila.

## 10. Filament

- **Recursos de nivel superior** (grupo "Recaudación"): `ActivityResource`, `TaskResource` — para
  ver el trabajo cruzando todos los donantes, no solo dentro de una ficha.
- **En la ficha del donante** (`DonorResource`): `ActivitiesRelationManager` y
  `TasksRelationManager` (editables, con las mismas Actions que los recursos de nivel superior),
  `AssignmentsRelationManager` (solo lectura, el historial), una sección "Responsable y próxima
  acción" y el botón "Asignar/Reasignar responsable".
- Los campos del formulario se definen una sola vez (`ActivityResource::formFields()`,
  `TaskResource::formFields()`) y los reutilizan tanto el recurso como el botón "Nueva
  actividad"/"Nueva tarea" de la ficha del donante, para no duplicar validaciones ni campos.

## 11. Ejemplo de uso

1. El Coordinador crea una actividad `call` programada para el donante X, resultado pendiente.
2. Dos semanas después la completa: resultado "Aceptó la propuesta", próxima acción "Enviar
   convenio". El Coordinador crea a mano una tarea "Enviar convenio" con fecha límite.
3. El Administrador asigna al Coordinador como responsable del donante X. Seis meses después lo
   reasigna a otro Coordinador: la fila anterior queda cerrada, la nueva vigente, el historial
   completo visible en "Historial de responsables".
4. La ficha del donante X muestra "Próxima acción: Enviar convenio (15/11/2026)" y, en el timeline,
   la actividad completada, la tarea creada, la reasignación y, si existieran, sus donativos, pagos
   y comunicaciones — todo en una sola línea de tiempo, sin que ninguna de esas fuentes se haya
   copiado a ningún lado.
