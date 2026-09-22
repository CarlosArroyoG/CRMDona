# Fase 01 — Núcleo del CRM

> **Estado: implementada y validada localmente** (2026-09-22) con Docker Compose real. Queda
> **pendiente de aprobación** del responsable. Lo que depende de infraestructura externa o de
> confirmación legal/fiscal está marcado como tal y no es una falla de la implementación.

## Qué se construyó

**Terminado y verificado localmente**

| Módulo | Qué incluye |
|---|---|
| Organización | Fila única con datos fiscales, autorización como donataria, leyenda, logotipo, firma de correo y aviso de privacidad (URL + versión, juntos o ninguno) |
| Donantes | Persona física/moral con reglas en base de datos; datos fiscales 1:1 opcionales; etiquetas; consentimientos independientes; aviso de duplicados por correo o RFC; archivar/reactivar; eliminar solo sin donativos |
| Programas y campañas | `Program 1 → N Campaign`; estados; vigencia; meta; identificador para enlaces futuros; una campaña con donativos no cambia de programa |
| Donativos | Efectivo, transferencia, cheque, depósito y especie; destino único; flujo `Por confirmar → Confirmado / Cancelado` con trazabilidad; confirmados no editables; nunca se eliminan |
| Usuarios | Solo Administrador: alta con rol, cambio de rol, desactivar/reactivar; siempre queda un Administrador activo; cambio de la propia contraseña para todos |
| Permisos | Matriz única (`Permission`) + Policies; los cuatro roles entran al panel |
| Auditoría | Bitácora propia de solo inserción, lista cerrada de campos por modelo, eventos de negocio |
| Búsqueda y filtros | Sin acentos (`unaccent`) en donantes, donativos, programas, campañas y usuarios; filtros de la sección B |
| Exportación | CSV (UTF-8 con BOM) y XLSX nativos de Filament, en cola, descarga solo para quien la generó, purga del archivo a 7 días |
| Documentación | Manual de usuario por módulo, modelo de datos, ADR-003 a ADR-009 |

**No implementado (fuera de alcance, por decisión):** pasarela de pagos, suscripciones, webhooks,
PAC/CFDI, envío de correos, página pública, recibos PDF.

## Decisiones tomadas

| Decisión | Dónde |
|---|---|
| Roles oficiales del prompt maestro; matriz única de permisos; sin paquetes | ADR-002 |
| Donantes en una tabla con CHECK por tipo; datos fiscales 1:1; consentimientos separados; sin dirección postal; correo y RFC no únicos (solo aviso) | ADR-003 |
| `numeric(12,2)`, strings + bcmath, MXN | ADR-004 |
| Donativo ≠ pago ≠ recibo simple ≠ CFDI; destino único; programa efectivo derivado | ADR-005 |
| Bitácora propia con campos "con valor" y "solo nombre"; solo inserción | ADR-006 |
| Exportaciones nativas de Filament; importes numéricos en XLSX; purga a 7 días del archivo | ADR-007 |
| Donativos nunca se eliminan; archivar en lugar de borrar; retención pendiente de validación | ADR-008 |
| `unaccent` + `f_unaccent`; sin índices por ahora | ADR-009 |
| Cuatro formas de pago manuales: efectivo, transferencia bancaria, cheque, depósito bancario | `PaymentMethod` |
| El Contador edita datos fiscales desde su propio formulario (no los generales) | `DonorPolicy::updateTaxProfile` |
| El seeder de demostración usa un usuario técnico sin rol, desactivado y con contraseña aleatoria | `DemoDataSeeder` |

## Hallazgos durante la implementación (corregidos)

| Hallazgo | Corrección |
|---|---|
| El CHECK de evidencia impedía cancelar un donativo confirmado (flujo aprobado) | CHECKs por estado: un cancelado conserva la evidencia de confirmación |
| `migrate:fresh` no borra funciones de PostgreSQL; la segunda ejecución fallaba | Funciones con `create or replace` |
| `notifications.data` en `text` rompía las notificaciones de Filament en PostgreSQL | Columna `jsonb` |
| Filament no define qué exportaciones purgar (`Prunable` sin `prunable()`) | Modelo `App\Models\Export` propio |
| Filament entrega los Select de enums como objetos | `NormalizesInput` convierte enums a su valor |
| Riesgo: el permiso fiscal habría dejado al Contador editar datos generales | Formulario fiscal separado |
| El XLSX de Filament se arma desde CSV (todo texto) | `WritesMoneyAsNumbers` para celdas numéricas de importe |
| Faker no incluye `es_MX` | Nombres de las factories con `es_ES` |

## Archivos y módulos principales

| Área | Archivos |
|---|---|
| Migraciones | `database/migrations/2026_09_22_163601…` a `2026_09_23_000007…` |
| Enums | `app/Enums/{DonorType,ProgramStatus,CampaignStatus,DonationKind,PaymentMethod,DonationStatus,TaxRegime,CfdiUse,AuditEvent,Permission}.php` |
| Modelos | `app/Models/{OrganizationSetting,Program,Campaign,Donor,DonorTaxProfile,Tag,Donation,AuditLog,Export}.php`, `Concerns/Auditable.php` |
| Actions | `app/Actions/{Users,Donors,Programs,Campaigns,Donations,Organization,Concerns}` |
| Policies | `app/Policies/*` |
| Filament | `app/Filament/Resources/{Donors,Donations,Programs,Campaigns,Users,AuditLogs}`, `app/Filament/Pages/{OrganizationSettings,Auth/ChangePassword}.php`, `app/Filament/Exports/*` |
| Soporte | `app/Support/{Money,Search}.php`, `app/Rules/{MoneyAmount,RfcFormat}.php`, `lang/es/audit.php` |
| Seeders | `database/seeders/{DatabaseSeeder,DemoDataSeeder}.php`, factories de cada modelo |

## Pruebas

**202 pruebas, 695 aserciones** (antes 40), todas contra `crm_testing`.

| Archivo | Qué cubre |
|---|---|
| `Unit/Enums/PermissionMatrixTest` | La matriz del código coincide con la aprobada; Solo lectura sin acciones prohibidas |
| `Unit/Support/MoneyTest` | Normalización, rechazo de inválidos, suma exacta, formato |
| `Feature/Donors/SaveDonorTest` | Tipos de persona (aplicación y base), consentimientos y evidencia, datos fiscales por permiso, estructura de RFC, etiquetas en bitácora, duplicados, archivar, eliminar |
| `Feature/Donations/DonationLifecycleTest` | Registro pendiente, importes exactos, especie, métodos, destino único, programa efectivo, confirmar, cancelar, no editar confirmados, trigger de borrado, CHECKs |
| `Feature/Campaigns/ProgramsAndCampaignsTest` | Slugs, nombres únicos, fechas y meta, bloqueo de programa (Action y trigger), eliminación |
| `Feature/Audit/AuditLogTest` | Quién/qué/cuándo, sin datos personales ni fiscales, sin contraseñas, eventos de negocio, solo inserción, etiquetas en español |
| `Feature/Users/UserManagementTest` | Alta por rol, política de contraseña, cambio de rol, último Administrador, desactivar/reactivar |
| `Feature/Organization/OrganizationSettingsTest` | Fila única, guardado, aviso de privacidad completo |
| `Feature/Exports/ExportsTest` | CSV con BOM y acentos, filtros respetados, importes numéricos en XLSX, permisos, descarga, purga a 7 días |
| `Feature/Filament/*` | Pantallas por rol (200/403), formularios, búsqueda sin acentos, filtros, confirmar/cancelar, datos fiscales del Contador, usuarios, organización, cambio de contraseña, bitácora, fichas y edición |
| `Feature/Auth/PanelAccessTest`, `DatabaseSeederTest` (actualizadas) | Cuatro roles entran; desactivados no; seeder ficticio sin credenciales |

## Validación final (Docker Compose real, 2026-09-22)

| Verificación | Resultado |
|---|---|
| `docker compose down` + `up -d --build` | PASS: 5 servicios, 0 reinicios, `app` sano |
| `migrate` en la base de desarrollo `crm` | PASS: 9 migraciones nuevas; el administrador local se conserva |
| `migrate:fresh --seed` (base desechable `crm_validation`, `APP_ENV=local`), dos veces seguidas | PASS: 13 migraciones; 4 programas, 3 campañas, 13 donantes, 33 donativos; 1 usuario técnico sin rol y desactivado |
| Rollback completo (`migrate:reset`) y re-migración | PASS: 13 y 13 |
| Pint | PASS (162 archivos) |
| Larastan nivel 8 | PASS (sin errores) |
| Pest | PASS: 202 pruebas, 695 aserciones en `crm_testing`; la base `crm` intacta |
| `/up`, `/`, `/admin/login` | 200, 200, 200 (login en español, sin claves) |
| `/admin` y pantallas sin sesión | 302 → `/admin/login` |
| Logs de app, worker y scheduler | Sin errores |
| Build `prod` con `--no-cache` | PASS |
| Imagen `prod` contra base vacía | PASS: aplica 13 migraciones y 3 triggers al arrancar; `/up` 200 |

## Pendientes y riesgos

**PENDIENTE EXTERNO:** CI remoto tras el push; servidor Coolify, DNS, dominios, HTTPS; primer
despliegue (incluye `unaccent` y volumen compartido por app, worker y scheduler); respaldos.

**PENDIENTE DE VALIDACIÓN LEGAL/FISCAL:** plazo de retención; reglas fiscales (uso de CFDI, régimen,
RFC genérico, especie); vigencia de los catálogos SAT.

**Verificación manual sugerida:** una exportación real desde el panel con `worker` y Redis (las
pruebas usan cola síncrona).

**Riesgos:**
- La base local `crm` ya tiene el esquema de la Fase 1 pero sin datos de demostración; cargarlos
  con `migrate:fresh --seed` en `crm` borraría el administrador local (usar `crm_validation`).
- No hay recuperación de contraseña por correo; si alguien la olvida, hoy no hay restablecimiento
  por el Administrador (pendiente #9).
- Imagen `prod` de ~1.1 GB; `EXPOSE 80` heredado (fijar 8080 en Coolify).

## Qué necesita la siguiente fase

- Aprobación de la Fase 1.
- **Antes de la Fase 2:** elegir la pasarela de pagos.
- Decidir si se agrega el restablecimiento de contraseña por el Administrador.

## Cómo probarlo manualmente

```sh
docker compose up -d
docker compose exec app php artisan migrate          # ya aplicado en tu base local
```

1. Entra a <http://localhost:8000/admin> con tu administrador.
2. **Organización:** captura URL y versión del aviso de privacidad y guarda.
3. **Usuarios:** crea un Coordinador y un Contador de prueba (correos ficticios).
4. **Programas/Campañas:** crea "Becas" y la campaña "Regreso a clases 2027" dentro de Becas.
5. **Donantes:** registra "José Peña" (acepta aviso) y una persona moral con datos fiscales.
   Busca "jose pena".
6. **Donativos:** como Coordinador registra uno en efectivo a la campaña → queda "Por confirmar".
   Como Contador, **Confirmar**; luego intenta editarlo (no se puede) y **Cancelar** con motivo.
7. **Exportar** donativos → espera el aviso en la campana → **Descargar .xlsx** y **.csv**.
8. **Bitácora:** revisa los eventos; los datos personales aparecen "(cambió; valor no registrado por privacidad)".
9. Entra como Solo lectura: no ve RFC, no exporta donantes ni donativos, no ve botones de edición.

**GitHub Actions** (después del push): workflow **CI**, jobs **"Pint, Larastan y Pest"** y
**"Construir imagen de producción"**, en el commit de cierre de la Fase 1.
