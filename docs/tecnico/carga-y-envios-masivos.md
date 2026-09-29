# Carga masiva de donantes (CSV) y envíos masivos

Implementado el 2026-09-28. Sin paquetes nuevos: la carga usa el importador que ya trae Filament y
los envíos reutilizan la cola de comunicaciones de la Fase 4 (`docs/tecnico/fase-4-comunicaciones.md`).

Decisiones del responsable (2026-09-28):

| Tema | Decisión |
|---|---|
| Correo repetido en el CSV | Se omite la fila y se informa. Nunca se modifica un donante existente |
| Consentimiento en el CSV | Columna opcional "acepta_comunicaciones", válida solo si quien carga confirma que la Fundación tiene la evidencia |
| Audiencia del envío masivo | Filtros guardados en el envío (tipo, etiquetas, campaña, programa y fechas de donativo) |
| Quién envía | Administrador y Coordinador, con correo de prueba obligatorio |

## 1. Carga de donantes por CSV

**Donantes → Cargar CSV** (permiso `donors.import`: A y C).

- **Flujo:** Filament lee el archivo (detecta UTF-8 o la codificación de Excel en Windows y el separador `,` o `;`), propone el mapeo de columnas, divide el archivo en bloques de 100 filas y los procesa en la cola (`worker`). Al terminar llega una notificación con el conteo y, si hubo rechazos, un CSV con el motivo de cada fila.
- **Límite:** 5 000 filas por archivo (`DonorResource::IMPORT_MAX_ROWS`).
- **Reglas por fila** (`App\Actions\Donors\ImportDonorRow`, que llama a `SaveDonor`): mismas validaciones y mensajes que el alta manual, y el mismo registro en la bitácora.
  - Solo altas nuevas. Si el correo ya existe (sin distinguir mayúsculas), la fila se rechaza. Un candado `pg_advisory_xact_lock` por correo evita que dos bloques del mismo archivo creen dos donantes con el mismo correo.
  - Las filas sin correo siempre se importan (no hay otro criterio de duplicado; la advertencia por RFC del alta manual no aplica porque no se importan datos fiscales).
  - `donors.origin = csv_import` y `registered_by_id` = quien cargó el archivo. El filtro **Registrado desde** de la lista de donantes los separa.
  - **Consentimiento:** "sí" solo se guarda si se marcó la confirmación en el formulario de la carga; si no, todos entran con "no acepta". La bitácora registra la creación con `accepts_communications`, `origin` y el usuario que cargó el archivo.
  - **No se importan** el aviso de privacidad (la aceptación exige evidencia individual) ni los datos fiscales.
  - **Etiquetas:** separadas por `;` o `,` (máximo 10). Se reutiliza la existente sin distinguir mayúsculas; si no existe, se crea. Opcionalmente, una **etiqueta para toda la carga** (por ejemplo, "Carga septiembre 2026") sirve para encontrarlos o elegirlos en un envío masivo.

### Columnas

La plantilla sale del botón **Descargar plantilla CSV** de la lista de donantes (`DonorImporter::templateCsv()`, en UTF-8 con BOM para Excel) y también del enlace de ejemplo del modal de carga. Trae estos encabezados y tres filas de ejemplo (persona física, persona moral y fila mínima) que se pueden cargar tal cual; una prueba lo verifica. También se reconocen los encabezados de la exportación de donantes, para poder exportar, corregir y volver a cargar (solo altas nuevas).

| Encabezado | Contenido | Obligatorio |
|---|---|---|
| `tipo_persona` | "física" o "moral" (vacío = física) | No |
| `nombre`, `apellido_paterno` | Persona física | Sí, si es física |
| `apellido_materno` | Persona física | No |
| `razon_social` | Persona moral | Sí, si es moral |
| `persona_contacto` | Persona moral | No |
| `correo` | Correo electrónico | No |
| `telefono` | Números, espacios, `+`, `()` y `-` (7 a 30) | No |
| `fecha_nacimiento` | `dd/mm/aaaa` o `aaaa-mm-dd`, año de cuatro dígitos | No |
| `etiquetas` | Separadas por punto y coma | No |
| `notas` | Texto libre (máx. 5 000) | No |
| `acepta_comunicaciones` | "sí" o "no" | No |

### Retención

- Las tablas `imports` y `failed_import_rows` son las del importador de Filament.
- Las filas rechazadas contienen los datos del archivo, así que se purgan a los 7 días junto con su importación (`App\Models\Import`, `model:prune` diario, igual que las exportaciones, ADR-007).
- La descarga de filas rechazadas solo la hace quien cargó el archivo (`ImportPolicy`), con usuario activo y sin contraseña temporal pendiente.

## 2. Envíos masivos

**Comunicaciones → Envíos masivos**.

| Permiso | A | C | Co | L |
|---|---|---|---|---|
| Consultar envíos (`communications.view`) | Sí | Sí | Sí | No |
| Preparar, probar, enviar, detener y borrar borradores (`communications.bulk`) | Sí | Sí | No | No |

### Ciclo

`Borrador → Preparando → Enviado`, o `Detenido`.

1. **Borrador** (`SaveBulkMessage`): asunto y texto simple con `{{ nombre }}` y `{{ organizacion }}` (mismo `TemplateRenderer` de las plantillas; nunca Blade ni HTML) y filtros de audiencia. El formulario muestra cuántos lo recibirán y cuántos quedan fuera por falta de correo o de consentimiento.
2. **Prueba** (`SendBulkMessageTest`, obligatoria): se envía de inmediato al correo de quien prepara el envío, con datos de ejemplo ("María") y el servidor de correo vigente. Tiene un límite de 5 pruebas cada 10 minutos y queda en la bitácora. Si después cambia el asunto o el texto, la prueba deja de valer; si solo cambian los filtros, se conserva.
3. **Enviar** (`SendBulkMessage`): exige borrador probado y al menos un destinatario. Guarda quién y cuándo, y encola `PrepareBulkMessage`.
4. **`PrepareBulkMessage`** (cola): evalúa la audiencia en ese momento y registra un `Communication` por destinatario (`kind = bulk_message`, `dedupe_key = bulk_message:{envío}:donor:{donante}`, `requested_by_id` = quien envió). Reparte los correos a `COMMUNICATIONS_BULK_PER_MINUTE` por minuto (30 por defecto) con retraso en la cola. Es único por envío y, si se repite, no duplica correos.
5. **Detener** (`StopBulkMessage`): mientras está preparando o enviado. Los correos que ya salieron no se recuperan. `SendCommunication` vuelve a revisar el estado antes de cada correo, así que los pendientes quedan "No enviado" con el motivo "El envío masivo se detuvo…".

- Un envío iniciado no se edita ni se borra: es la evidencia de a quién se escribió. Tampoco se reenvía (`ResendCommunication` solo aplica a agradecimientos).
- La ficha del envío muestra el resultado (Enviados, En cola, Fallidos y No enviados) y la lista de destinatarios con su estado. Cada correo aparece también en el **Historial de envíos**, con enlace al envío masivo.

### Audiencia (`App\Communications\BulkAudience`)

Es la única pieza que decide quién recibe. Los filtros se combinan con "y":

- **Tipo de persona.**
- **Etiquetas:** tiene al menos una de las elegidas.
- **Campañas, programas y rango de fechas:** tiene al menos un donativo **confirmado** que cumple todos esos filtros a la vez. El programa cuenta directo o a través de la campaña, igual que en los reportes.

Reglas fijas, iguales a las de la decisión #34 (comunicaciones informativas):

- el donante no está archivado;
- tiene correo;
- tiene "Acepta comunicaciones".

**Consentimiento verificado** (revisión de seguridad 2026-09-28): quien se registró en `/donar` escribió un correo sin probar que es suyo. Su "Acepta comunicaciones" solo cuenta cuando tiene **al menos un donativo confirmado**. Así, un bot o un tercero no puede inscribir correos ajenos para que reciban envíos masivos.

- La regla vive en `Donor::hasVerifiedCommunicationsConsent()` y en el scope `withVerifiedCommunicationsConsent()`.
- Aplica a los envíos masivos, a la felicitación de cumpleaños, a "Preparar WhatsApp" y a `QueueCommunication::skipReason()`.
- Los donantes registrados a mano o cargados por CSV no cambian.

`CommunicationKind::BulkMessage::requiresConsent()` es siempre verdadero, aunque `COMMUNICATIONS_TRANSACTIONAL_REQUIRES_CONSENT` sea `false`. Cada correo lleva enlace de baja y la cabecera `List-Unsubscribe` (`hasUnsubscribeLink()`).

### Modelo de datos

- `bulk_messages` (migración reversible `2026_10_06_000002`):
  - columnas: `subject`, `body`, `audience` (jsonb), `status`, `recipients_count`, `tested_at` / `tested_by_id`, `created_by_id`, `sent_at` / `sent_by_id` y `stopped_at` / `stopped_by_id`;
  - los CHECK garantizan que un envío no borrador tenga quien lo envió y una prueba, y que "detenido" tenga su fecha.
- `communications.bulk_message_id` y el tipo `bulk_message`: CHECK `(kind = 'bulk_message') = (bulk_message_id is not null)`.
- La reversión se niega si el historial ya tiene correos masivos.
- `donors.origin` admite `csv_import` (migración reversible `2026_10_06_000001`, que también crea `imports` y `failed_import_rows`).

## 3. Configuración

| Variable | Uso | Por defecto |
|---|---|---|
| `COMMUNICATIONS_BULK_PER_MINUTE` | Correos masivos por minuto; ajustarlo al límite del proveedor SMTP | 30 |

El `worker` debe estar activo para las cargas y los envíos.

## 4. Pendientes relacionados

- Proveedor SMTP real y su límite de envío diario (#32). Con un SMTP de buzón personal, el tope diario es bajo.
- Rebotes (#33) y baja en un clic (#36).
- La evidencia del consentimiento de los donantes cargados por CSV es responsabilidad de la institución (#54).
