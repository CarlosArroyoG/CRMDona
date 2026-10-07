# Estado actual de CRMDona

Fotografía consolidada del proyecto al cierre de la última fase. **No es un historial**: para el
detalle de cómo y por qué se llegó aquí, ver `docs/fases/FASE-XX-resumen.md` (histórico) y los ADR
en `docs/tecnico/decisiones/`. Este archivo se actualiza al cerrar cada fase; sustituye a los
resúmenes de fase como punto de partida para la siguiente.

- **Última fase cerrada:** Fase 8 — Gestión de relaciones con donantes (2026-10-06). Ver
  `docs/fases/FASE-08-resumen.md` y ADR-013.
- **Commit de referencia:** pendiente. Los cambios de la Fase 8 están completos y validados
  localmente pero **todavía no se confirmaron con `git commit`** (push, merge y commit requieren
  autorización explícita). Cuando se confirmen, este archivo debe actualizarse con el hash.

## 1. Arquitectura vigente

- Laravel + Filament 5 para el panel (`/admin`); Blade + Tailwind para la página pública (`/donar`);
  `/up` como healthcheck. Una sola aplicación, un solo dominio.
- PostgreSQL (datos, con invariantes reforzadas por `CHECK`, triggers e índices únicos/parciales) y
  Redis (colas y caché). Docker con 5 recursos: `app`, `worker`, `scheduler`, `postgres`, `redis`.
- Capas: Filament Resources (solo arman pantallas) → `app/Actions/<Dominio>` (reglas de negocio,
  autorización revalidada, transacciones) → Modelos Eloquent (auditables) → PostgreSQL (barrera
  final). Ningún Resource ni controlador contiene lógica de negocio.
- Namespaces de dominio de nivel superior: `app/Payments` (pasarelas), `app/Reports` (cálculos de
  tablero y reportes), `app/PublicDonations` (orquestación de `/donar`), `app/ExternalCfdi` (lectura
  segura de XML), `app/Communications`, `app/DonorRelations` (timeline y próxima acción, nuevo en la
  Fase 8), `app/Support` (Money, Search, Branding, AuditOrigin, SensitiveData).

## 2. Módulos actuales

| Módulo | Qué cubre |
|---|---|
| Núcleo | Donantes, donativos manuales, programas, campañas, usuarios, organización, bitácora |
| Pagos en línea | Pago único y mensual (Stripe, Mercado Pago, `FakeGateway`), intentos, reembolsos, disputas, incidencias, bandeja de webhooks |
| Cobro asistido | Solicitudes de pago con enlace (`PaymentRequest`), identidad institucional única (`Branding`) |
| CFDI externo y Contabilidad | El CRM no emite CFDI (ADR-012); adjunta CFDI externo como antecedente y avisa a Contabilidad por cada donativo confirmado |
| Comunicaciones | Recibo simple, agradecimiento, cumpleaños, plantillas editables, historial de envíos, correo saliente SMTP administrable |
| Reportes y tablero | Indicadores del mes, reporte de pagos, control contable |
| Página pública | `/donar` con pago único o mensual, aviso de privacidad publicado desde el CRM |
| **Relación con donantes** (Fase 8) | Actividades, tareas, responsable asignado con historial, próxima acción derivada, timeline 360°, panel operativo |

## 3. Modelo de dominio (resumen)

- `Donor` es el centro: 1 → N `Donation`, `Payment`, `Subscription`, `PaymentRequest`,
  `Communication`, `DonorActivity`, `Task` (opcional), `DonorAssignment`.
- Conceptos deliberadamente separados, nunca fusionados (ADR-005/012): `Donation` ≠ `Payment` ≠
  `DonationReceipt` ≠ `ExternalCfdi` ≠ `AccountingNotice`.
- **`DonorActivity` ≠ `Communication`.** `DonorActivity` es una interacción humana registrada a
  mano (llamada, visita, WhatsApp informal); `Communication` es el correo o WhatsApp que **envía el
  sistema** (agradecimiento, cumpleaños, solicitud de pago). No se fusionaron.
- **`Task`** puede o no tener `donor_id` (pendiente general vs. de un donante concreto).
- **`DonorAssignment`** es el historial de responsables de un donante: a lo más una fila vigente
  por donante (`ended_at IS NULL`), forzado por un índice único parcial en PostgreSQL. Reasignar
  cierra la vigente y crea una nueva; nunca edita ni borra una fila cerrada.
- **`registered_by_id`** (en `Donor`, `Donation`, etc.) **conserva exclusivamente su significado
  original: quién registró ese dato en el CRM.** No representa, ni nunca representó, al
  responsable de la relación con el donante — esa responsabilidad vive exclusivamente en
  `donor_assignments`.
- La responsabilidad **actual** de un donante se consulta con `Donor::currentAssignment()`; el
  **historial** completo, con `Donor::assignmentHistory()`.
- La **próxima acción** de un donante no es una columna: se deriva con
  `App\DonorRelations\NextAction::for($donor)` a partir de su actividad programada y su tarea
  abierta más próximas. No se persiste como dato duplicado en ningún lugar.
- El **timeline 360°** de un donante no es una tabla: se construye con
  `App\DonorRelations\Timeline::for($donor, $viewer)`, que agrega, en consulta, las fuentes de
  verdad reales (actividades, tareas, responsable, donativos, pagos, suscripciones,
  comunicaciones, solicitudes de pago, reembolsos, disputas, incidencias, recibos, CFDI externo,
  avisos a Contabilidad). **No existe una tabla `timeline_events`.**

## 4. Fuentes de verdad (una por concepto)

| Concepto | Fuente de verdad |
|---|---|
| Permisos y qué rol puede hacer qué | `App\Enums\Permission` (matriz en ADR-002 y `tests/Unit/Enums/PermissionMatrixTest.php`) |
| Dinero | `App\Support\Money` (strings + bcmath, nunca float — ADR-004) |
| Auditoría | `App\Models\Concerns\Auditable` + `AuditLog` (ADR-006) |
| Pagos | `app/Payments` (contratos por capacidad, `GatewayRegistry`, `FakeGateway` en pruebas) |
| Reportes y tablero | `app/Reports` (`DashboardMetrics`, `PaymentReport`, `AccountingControl`) |
| Próxima acción de un donante | `App\DonorRelations\NextAction` |
| Timeline 360° de un donante | `App\DonorRelations\Timeline` |
| Responsable actual e histórico de un donante | tabla `donor_assignments` vía `Donor::currentAssignment()` / `assignmentHistory()` |
| Búsqueda sin acentos | `App\Support\Search::unaccent()` (ADR-009) |
| Identidad institucional (logo, colores) | `App\Support\Branding` |
| Decisiones arquitectónicas | `docs/tecnico/decisiones/ADR-001` a `ADR-013` |

## 5. Seguridad y autorización

- Un rol por usuario (`users.role` + `App\Enums\Role`, ADR-002): Administrador, Coordinador de
  procuración de fondos, Contador, Solo lectura. Matriz única en `App\Enums\Permission`.
- **Dos niveles siempre**: Policy (gatea la UI de Filament) + la propia Action revalida el permiso
  con `$actor->hasPermission(...)` antes de mutar nada. Pendiente de homogeneizar en Actions más
  antiguas (#47, no introducido por la Fase 8).
- `DonorActivity`, `Task` y `DonorAssignment` **cambian de estado exclusivamente a través de sus
  Actions** (`CreateActivity`, `UpdateActivity`, `CompleteActivity`, `RescheduleActivity`,
  `CancelActivity`, `CreateTask`, `UpdateTask`, `CompleteTask`, `CancelTask`,
  `AssignDonorResponsible`): ningún Resource de Filament escribe esos modelos directamente.
- MFA (TOTP) obligatorio para los cuatro roles. Contraseñas: el Administrador restablece con una
  temporal de un solo uso (ADR-010); nunca se auditan contraseñas ni hashes.
- Datos sensibles fuera de bitácora, logs y exportaciones (`App\Support\SensitiveData`): nunca PAN,
  CVV, secretos, firmas ni payloads crudos.

## 6. Integraciones

| Integración | Estado |
|---|---|
| Stripe | Test validado end-to-end; Live pendiente de credenciales institucionales (#14) |
| Mercado Pago | Pendiente el sandbox institucional (#15, #49) |
| Correo saliente | SMTP administrable desde el panel; proveedor real de producción pendiente (#32); rebotes pendientes (#33) |
| CFDI / PAC | Ninguna: el CRM no emite CFDI (ADR-012); el CFDI externo es solo un antecedente adjunto |

## 7. Estado de pruebas y calidad (al cierre de la Fase 8)

- **Pest:** 755 pruebas, 3422 aserciones, 0 fallos.
- **Pint:** 504 archivos correctos.
- **Larastan:** nivel 8, 0 errores.
- **Build de assets** (`docker build --target assets`): correcto.
- **Build de imagen de producción** (`docker build --target prod`): correcto.
- Pruebas de concurrencia con procesos reales (`tests/Concurrency`) siguen pasando sin
  `RefreshDatabase`.

## 8. Mapa de código (orientación rápida)

```
app/Models/<Modelo>.php                    Eloquent + Auditable
app/Actions/<Dominio>/<Verbo><Sustantivo>.php   Una Action por operación de negocio
app/Policies/<Modelo>Policy.php            Autorización de Filament (nivel 1)
app/Enums/                                  Permission, Role, y enums de estado por dominio
app/Filament/Resources/<Dominio>/          Resource + Pages + RelationManagers
app/Filament/Widgets/                      Widgets del Escritorio
app/DonorRelations/                        Timeline y NextAction (Fase 8, agregadores de consulta)
app/Reports/                                Cálculos de tablero y reportes
app/Payments/                               Contratos de pasarela, GatewayRegistry, FakeGateway
database/migrations/                        Fecha-ordenadas; nunca se editan tras aplicarse
tests/{Unit,Feature,Concurrency}/
docs/
  fases/FASE-XX-resumen.md                  Histórico — no releer sistemáticamente
  tecnico/*.md                               Fuente de verdad técnica por dominio
  tecnico/decisiones/ADR-NNN-*.md            Decisiones arquitectónicas
  manual-usuario/                            Para el personal, no para programadores
  pendientes.md                              Lista única de pendientes, con número y fase objetivo
  CHANGELOG.md                                Keep a Changelog, en español
  ESTADO-ACTUAL.md                            Este archivo
```

## 9. Limitaciones actuales (no son fallas)

- El timeline 360° recupera como máximo los **25 eventos más recientes por fuente**; no hay
  paginación profunda. Aceptado por el volumen bajo esperado por donante (ADR-013).
- **#54** — No hay alertas push (campana o correo) por tareas vencidas o seguimientos atrasados de
  relación con donantes; el panel operativo las muestra, pero no notifica proactivamente.
- **#55** — La ficha del donante (responsable, próxima acción, actividades, tareas, timeline) no se
  verificó clic a clic en un navegador real en este entorno de desarrollo (no hay navegador
  disponible); se verificó con `Livewire::test()`, Pint, Larastan y los dos builds de Docker.
- Estripe Live, Mercado Pago, SMTP real, S3, dominio HTTPS, Coolify y credenciales productivas
  siguen pendientes de la institución (detalle completo en `docs/pendientes.md`).

## 10. Pendientes relevantes

Lista completa y siempre vigente en `docs/pendientes.md`. Los más relevantes para decidir la
siguiente fase:

- **#14 / #15 / #49** — Stripe Live y sandbox de Mercado Pago (institución).
- **#32 / #33** — Proveedor de correo real y rebotes (institución + técnico).
- **#54** — Alertas de tareas/seguimientos vencidos (técnico, fase futura).
- **#55** — Revisión visual en navegador de la Fase 8 (entorno).
- **#47** — Homogeneizar la revalidación de permisos dentro de Actions anteriores a la Fase 2
  (técnico, refactorización posterior).

## 11. Punto de partida para la siguiente fase

1. Leer, en orden: `CLAUDE.md` → este archivo → `docs/pendientes.md` → la documentación técnica
   relacionada con lo que se va a construir → el código afectado.
2. Antes de proponer una entidad, Action, permiso o pantalla nueva, verificar si ya existe algo
   reutilizable (ver §4, fuentes de verdad, y el mapa de código en §8).
3. La Fase 8 no modificó ninguna regla de donativos, pagos, CFDI, correo saliente ni
   comunicaciones: cualquier fase futura que necesite tocarlas debe justificarlo explícitamente,
   igual que pide `CLAUDE.md`.
4. Decisiones de negocio ambiguas: preguntar, no asumir.
