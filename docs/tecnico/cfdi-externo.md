# CFDI externo y flujo contable

**Fuente de verdad** desde el cambio de alcance del 2026-09-23 (ADR-012). Sustituye a
`fase-3-cfdi.md`, que queda solo como historial.

## 1. Decisión

- **El CRM no genera CFDI de ningún tipo.** No emite ni timbra CFDI individuales, globales, de especie
  ni de extranjeros. Tampoco cancela ni sustituye CFDI, ni llama a un PAC.
- **La contadora es responsable de toda emisión de CFDI, fuera del CRM.** También decide fuera del
  sistema el tratamiento fiscal de cada donativo, incluida la factura global.
- El CRM **no decide** qué donativos integran una factura global.
- Después, Contabilidad puede adjuntar el XML (y el PDF) del CFDI externo al donativo como
  **antecedente documental**. El CRM no certifica su validez fiscal.
- Facturapi queda **retirado y sin uso**: no hay código, configuración ni variables de entorno.

## 2. Flujo al confirmarse cada donativo

Aplica a los donativos manuales, a los pagos en línea únicos y a cada mensualidad. Lo ejecuta
`RunConfirmedDonationSteps` después del commit, en tres pasos independientes:

1. **Recibo simple** (`IssueDonationReceipt`).
   - PDF del CRM con folio propio `R-000000`, guardado en el disco privado.
   - No es un CFDI y no incluye datos fiscales del donante.
2. **Agradecimiento al donante** (`QueueDonationThankYou`).
   - Correo transaccional con el recibo adjunto.
   - Sale de inmediato: nunca espera ni adjunta un CFDI.
3. **Aviso a Contabilidad** (`QueueAccountingNotice` → Job `SendAccountingNotice`).

Reglas comunes a los tres pasos:

- Cada paso es idempotente.
- Un fallo en uno no impide los demás y no revierte la confirmación (`rescue` por paso).
- Los correos que fallan se reintentan desde su propio registro: `communications` para el donante y
  `accounting_notices` para Contabilidad.

## 3. Aviso a Contabilidad

- **Contenido** (`AccountingNoticeComposer`):
  - folio del recibo simple y número del donativo;
  - donante;
  - fecha de recepción;
  - importe;
  - destino (campaña con su programa, programa o fondo general);
  - forma de pago conocida: la manual o, en línea, el proveedor y el tipo de tarjeta si se conoce;
  - **CFDI solicitado: SÍ/NO**, también en el asunto.
- **Si CFDI solicitado = SÍ**, el aviso incluye los datos ya capturados en `DonorTaxProfile` (RFC,
  nombre o razón social, régimen, CP fiscal y uso del CFDI) y el correo del donante. Si faltan esos
  datos, el aviso lo dice; no los inventa ni los vuelve a pedir.
- **Si CFDI solicitado = NO**, el aviso lo dice y aclara que el CRM no clasifica el donativo ni emite
  nada.
- **Origen de "CFDI solicitado":**
  - donativos manuales: `donations.tax_receipt_requested`, que se captura en el formulario;
  - página pública: casilla "Quiero mi comprobante fiscal" → `payments.tax_receipt_requested` o
    `subscriptions.tax_receipt_requested`. Cada mensualidad lo hereda y pasa al donativo al crearse.
    Los pagos anteriores a este cambio quedan en `false`: nada se infiere hacia atrás.
- **Destinatarios** (solo configurados y autorizados):
  - usuarios activos con el permiso `accounting.process` (Administrador y Contador);
  - que además tengan activa la preferencia `users.receives_accounting_notices`
    (Usuarios → Editar → "Recibe avisos a Contabilidad").
  - Solo el Administrador cambia esa preferencia, y no se puede activar en otros roles. Si el rol
    cambia después, el usuario deja de recibir avisos.
  - Nunca hay direcciones fijas en el código ni en la configuración.
- **Sin destinatarios configurados:**
  - el aviso queda "No enviado" con su motivo;
  - los Administradores reciben una alerta operativa (campana y correo), una por día;
  - el aviso se reenvía desde **Control contable → Reenviar aviso**.
- **Idempotencia:**
  - hay un solo aviso por donativo (`accounting_notices.donation_id` único);
  - el envío se toma con un UPDATE condicional;
  - `delivered_to` evita repetir el correo a quien ya lo recibió cuando hay un reintento.
- **Bitácora:**
  - `AccountingNotice` es auditable (estado, envío y procesamiento);
  - la nota contable se registra solo por nombre;
  - los datos fiscales nunca se guardan en la tabla ni en la bitácora: el correo se arma al enviarlo.
- **Datos sensibles:**
  - el aviso solo va a Contabilidad;
  - el recibo simple y el agradecimiento no llevan RFC, régimen ni datos fiscales del donante.

## 4. Control contable (cola operativa)

**Reportes → Control contable** (`/admin/control-contable`) tiene una fila por donativo confirmado
(`App\Reports\AccountingControl`).

- **Columnas:**
  - recibo simple, donativo, fecha, donante, importe y destino;
  - CFDI solicitado;
  - aviso a Contabilidad;
  - procesamiento contable (pendiente o procesado);
  - CFDI externo adjunto, con su UUID, fecha de emisión y fecha en que se adjuntó.
- **Filtros:**
  - CFDI solicitado / no solicitado;
  - CFDI externo adjunto (sí o no);
  - procesamiento pendiente o procesado;
  - estado del aviso;
  - fechas y donante.
- **Acciones** (solo `accounting.process`):
  - **Marcar procesado**: individual con nota opcional, o en lote;
  - **Reabrir**: exige un motivo;
  - **Reenviar aviso**: solo si falló o no se envió.
  - Adjuntar un CFDI externo **no** marca el donativo como procesado: lo decide Contabilidad.
- **Permisos:**
  - consultan y exportan: Administrador, Coordinador y Contador (`cfdi.view`);
  - Solo lectura no tiene acceso;
  - la exportación no incluye datos fiscales del donante.
- Sustituye al antiguo "Reporte CFDI" (`/admin/reporte-cfdi`, retirado).

## 5. CFDI externo (antecedente documental)

- **Tabla `external_cfdis`:**
  - `donation_id`, `uuid`, `issued_at`, `stamped_at`, `total`;
  - `xml_path` (obligatorio) y `pdf_path` (opcional);
  - `notes`, `source`, `uploaded_by_id` y `uploaded_at`;
  - `removed_at`, `removed_by_id` y `removal_reason`.
- **Adjuntar** (`AttachExternalCfdi`, permiso `cfdi.manage`: Administrador y Contador):
  - solo en donativos confirmados;
  - el XML es obligatorio y el PDF opcional.
- **Lectura segura del XML** (`App\ExternalCfdi\CfdiXmlReader`):
  - máximo 2 MB;
  - se rechaza cualquier `<!DOCTYPE` o `<!ENTITY`, lo que evita XXE y bombas de entidades;
  - se usa `LIBXML_NONET`, sin `LIBXML_NOENT`, y nunca se ejecuta contenido;
  - se exige el nodo `cfdi:Comprobante` (3.3 o 4.0) y el UUID del Timbre Fiscal Digital;
  - se leen la fecha de emisión y la de timbrado (hora del centro de México), el total y el RFC emisor.
- **Validaciones:**
  - el tipo MIME real (finfo), no la extensión ni el nombre;
  - el PDF, con máximo 10 MB y firma `%PDF-`;
  - si la organización tiene RFC, el RFC emisor debe coincidir;
  - el mismo UUID vigente no puede adjuntarse dos veces al mismo donativo.
- **Almacenamiento:**
  - disco privado `local`, en `external-cfdi/AAAA/MM/{uuid}-{aleatorio}.xml|pdf`;
  - el nombre original del archivo nunca se usa.
- **Descarga:**
  - ruta `external-cfdi.files`, con sesión y el permiso `cfdi.view` en la Policy;
  - encabezados `Cache-Control: private, no-store` y `X-Content-Type-Options: nosniff`;
  - no hay URLs públicas permanentes.
- **Reemplazar y retirar** (`RemoveExternalCfdi`, motivo obligatorio):
  - el registro y los archivos se conservan como historial;
  - no se cancela nada ante el SAT.
- **Bitácora:**
  - se registran la carga, el reemplazo y el retiro (este último como evento "Descartado");
  - las rutas y las notas se registran solo por nombre.
- **Pantalla del donativo:**
  - la sección "Recibo de donación" permite descargar y reimprimir el recibo;
  - la sección "Contabilidad" muestra el aviso y el procesamiento;
  - el relation manager "CFDI externo / antecedentes fiscales" permite adjuntar, descargar,
    reemplazar y retirar;
  - nunca aparecen "Generar CFDI", "Timbrar" ni "Reintentar timbrado".
  - Solo lectura no ve nada fiscal.

## 6. Datos históricos que se conservan

- **Tablas:** `cfdis`, `global_cfdis`, `donation_global_cfdi` y `fiscal_incidents` **no se borran**.
- **Columnas:** tampoco se borran `donations.fiscal_route`, `donations.fiscal_block_reason`,
  `donations.fiscal_late_at` y los campos SAT de especie (`in_kind_*`), ni
  `organization_settings.global_cfdi_periodicity`. El código ya no los usa; siguen legibles para
  auditoría.
- **Copia de antecedentes:** la migración `2026_10_01_000001` copia los CFDI que el CRM llegó a timbrar
  a `external_cfdis` con `source = crm_legacy`, apuntando a sus mismos archivos:
  - un CFDI individual timbrado queda vigente;
  - un CFDI cancelado queda retirado, con su motivo;
  - una factura global queda como un antecedente por cada donativo que incluía.
- **Especie:** se quitó la restricción `donations_in_kind_evidence`, porque los códigos SAT ya no son
  obligatorios. Al revertir la migración se vuelve a crear como `NOT VALID`.
- **Modelos y catálogos históricos:** `Cfdi`, `CfdiStatus` y `CfdiCancellationMotive` quedan como
  modelos de solo lectura (bitácora y morph `cfdi`).
- **Comunicaciones históricas:** `CommunicationKind::Cfdi` queda como histórico. No se encola, no se
  reenvía y no tiene plantilla; un registro pendiente de ese tipo se marca "No enviado".

## 7. Reversibilidad de las migraciones

| Migración | `down()` |
|---|---|
| `2026_10_01_000001_create_external_cfdis_table` | Falla si hay CFDI cargados por usuarios (`source = upload`); si no, borra la tabla (los originales siguen en `cfdis`). |
| `2026_10_01_000002_create_accounting_notices_table` | Falla si Contabilidad ya marcó algún donativo como procesado. |
| `2026_10_01_000003_add_tax_receipt_requested_to_payments_and_subscriptions` | Quita las dos columnas. |

Los donativos confirmados antes de `000002` reciben su aviso como "No enviado". No se manda correo
retroactivo, y quedan pendientes de procesamiento contable.
