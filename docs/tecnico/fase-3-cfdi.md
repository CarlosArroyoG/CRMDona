# Fase 3 — CFDI de donativos (reglas, diseño y Facturapi)

Marcas:
- **[V]** verificado en documentación oficial vigente;
- **[F]** requiere decisión o validación fiscal (bloqueado en código con su motivo);
- **[S]** requiere probarse en Facturapi Test.

PAC elegido: **Facturapi** (2026-09-23), con el cliente HTTP de Laravel y sin SDK. Permisos aprobados el 2026-09-23.

## 1. Reglas fiscales 2026 [V]

Fuentes oficiales, en orden de prioridad:
1. SAT, *Donatarias Autorizadas — Emisión de CFDI* (`CFDI_Donatarias2026.pdf`);
2. RMF 2026 (DOF 28-12-2025);
3. SAT, *Esquema de cancelación de CFDI 2026*;
4. complemento `donat11.xsd`.

La FAQ de 2024 solo se usa para S01/RESICO (ver la tabla).

| Regla | Valor | Fuente |
|---|---|---|
| Obligación | La donataria expide CFDI al recibir los donativos | RLISR 138-E; LISR 86; RMF 2026 3.10.1.2 |
| Plazo | Dentro de las 24 h posteriores a la operación; nunca antes de recibir el donativo | RCFF 39 (guía 2026) |
| Tipo de comprobante | Ingreso (`I`) | Guía 2026, caso A |
| Método de pago | `PUE`; el pago diferido no aplica a donativos | Guía 2026 |
| Forma de pago | La que corresponda del catálogo. Implementado: `01` efectivo, `02` cheque, `03` transferencia, `04` tarjeta de crédito, `28` tarjeta de débito | Guía 2026; c_FormaPago |
| Uso de CFDI | `D04` persona física; `G03` persona moral | Guía 2026 |
| Uso de CFDI en RESICO | `S01` si la persona física tributa en RESICO (626), porque D04 no admite ese régimen | FAQ SAT 2024 (no contradicha en 2026) |
| Concepto | Clave `84101600`, unidad `M4`, cantidad `1`, valor = monto del donativo | Guía 2026, caso A |
| Descripción | El propósito del donativo. Implementado: "Donativo para {campaña o programa}" o "para el fondo general" | Guía 2026 |
| Impuestos | ObjetoImp `01` (no objeto de impuesto) | Guía 2026 |
| Complemento Donatarias 1.1 | Obligatorio, con `noAutorizacion`, `fechaAutorizacion` y la leyenda | CFF 29-A fr. V b; RMF 2026 3.10.1.2 y 2.7.1.26; `donat11.xsd` |
| Público en general | Factura global diaria, semanal o mensual con: RFC `XAXX010101000`; `S01`; **con Complemento Donatarias** (ejemplo del caso C, verificado 2026-09-23); clave `01010101`; unidad `ACT`; número de folio de cada comprobante de operación; forma de pago del donativo de mayor monto. Se envía dentro de las 24 h siguientes al cierre del periodo | Guía 2026, caso C; RMF 2026 2.7.1.21 |
| Especie | Forma de pago `12`; la clave y la unidad del bien | Guía 2026, casos B y D |
| Motivos de cancelación | `01` sustitución: primero el CFDI nuevo con relación `04`, después cancelar con su UUID. `02` error sin relación: cancelar y reemitir. `03` operación no realizada. `04` operación nominativa incluida en una factura global | Esquema de cancelación 2026 |
| Aceptación del receptor | 3 días hábiles; si no responde, se cancela. Sin aceptación, entre otros: CFDI de hasta $1,000, público en general y cancelación dentro del día hábil siguiente | RMF 2026 2.7.1.34 y 2.7.1.35 |
| No cancelable | Un CFDI con documentos relacionados vigentes. La relación 04 se libera al pedir la cancelación | Esquema de cancelación 2026 |

## 2. Cobertura fiscal de cada donativo

Todo donativo confirmado cae en una de tres rutas (`ResolveDonationFiscalRoute`). El campo "Solicitó recibo deducible" queda solo como dato informativo para la atención al donante.

| Ruta | Cuándo | Qué hace el sistema |
|---|---|---|
| **CFDI individual** | El donante tiene datos fiscales y el CFDI se puede armar | Lo timbra al confirmar (`CFDI_AUTO_ISSUE=true`). La conciliación vuelve a intentar los de las últimas 72 h |
| **Público en general** | Sin datos fiscales, o con RFC `XAXX010101000` | No crea nada: la factura global está bloqueada ([F] §3) |
| **Bloqueado** | Especie, reembolso o disputa, depósito bancario, tarjeta de prepago o de tipo desconocido, RFC extranjero, datos por corregir | No crea nada; muestra el motivo |

La ruta se ve en el detalle del donativo ("Cobertura fiscal"), para los roles con `cfdi.view`.

## 3. Cuestiones [F]

1. **Factura global.** Falta decidir:
   - periodicidad: diaria, semanal o mensual;
   - qué cuenta como "comprobante de operación con el público en general": el folio del donativo en el CRM, un recibo propio o los "Recibos" de Facturapi;
   - si se emiten comprobantes de operación para donativos menores de $100 (RMF 2.7.1.21).

   Sin esas decisiones no se construye la factura global ni el motivo 04.
2. **Especie:** clave del bien, unidad y valuación.
3. **Depósito bancario:** si cuenta como efectivo (01) o como cheque (02).
4. **Tarjeta de prepago** (no está en el catálogo como tal) y tipo de tarjeta desconocido.
5. **Donante extranjero** (`XEXX010101000`).
6. **Reembolsos y contracargos:** se bloquea la emisión si el pago los tiene. Nada es automático sobre un CFDI ya timbrado.
7. **Vigencia de la autorización de donataria:** el CRM no la verifica; es responsabilidad del Administrador.
8. **Emisión tardía:** donativos confirmados después de 24 h, o antes de configurar el PAC. Se pueden emitir a mano.

## 4. Diseño (cambios de este bloque)

- **Sustitución (motivo 01):**
  - el CFDI nuevo lleva `substitutes_cfdi_id` y `replacement_pending`, y se timbra con `related_documents` 04;
  - al timbrarse, el original pasa a cancelación con motivo 01 y el UUID nuevo;
  - al cancelarse, el nuevo queda como el vigente;
  - si el receptor rechaza la cancelación, ambos siguen vigentes y "Sustituir" reintenta solo la cancelación;
  - índices: un CFDI vigente por donativo y, como máximo, una sustitución en curso.
- **Cancelación directa:** solo motivos 02 y 03. El 01 va por sustitución; el 04 queda bloqueado hasta que exista la factura global.
- **Descartar:** solo un CFDI rechazado que nunca se timbró (`discarded`; CHECK: sin UUID). Un error temporal no se descarta, porque su resultado es incierto.
- **Tipo de tarjeta:**
  - `payment_attempts.card_funding` (credit, debit, prepaid, unknown);
  - Stripe lo toma de `card.funding` y Mercado Pago de `payment_method.type` ([S] #30).

## 5. Facturapi (verificado en su documentación; `docs.facturapi.io`, `api-es.yaml`)

| Tema | Documentado | Uso en el adaptador |
|---|---|---|
| Crear | `POST /v2/invoices`, llave Bearer `sk_test_`/`sk_live_`. `200` = `valid`. `202` = rescate por intermitencia (hasta 5 intentos, cada 10 min) | Síncrono. Un `202` o `pending` se trata como no disponible y se reintenta |
| Receptor | `customer.legal_name`, `tax_id`, `tax_system`, `address.zip` | Del perfil fiscal del donante |
| Concepto | `product_key`, `unit_key`, `price`, `taxability`, `taxes` (con `01` → `[]`) | 84101600, M4, `taxability: 01`, sin impuestos |
| Complemento | `complements: [{type: "custom", data: "<xml>"}]`; Facturapi no lo imprime en el PDF | `donat:Donatarias` 1.1, más `namespaces` y `pdf_custom_section` con el oficio y la leyenda [S] |
| Relacionados | `related_documents: [{relationship, documents}]` | Sustitución 04 [S]: la especificación marca `related` como requerido |
| Idempotencia | `idempotency_key` "evita duplicados al reintentar"; **no documenta qué responde ante una llave repetida**. `external_id` se puede buscar (sin garantía de unicidad) | Se envían ambos con nuestra llave y, **antes de cada timbrado, se busca por `external_id`**; si existe, se adopta. Si hay 2 → rechazo para revisión humana |
| XML y PDF | `GET /invoices/{id}/xml` y `/pdf` | Se guardan en disco privado |
| Cancelar | `DELETE /invoices/{id}?motive=01..04&substitution=UUID`. Resultado: `status: canceled`, o `valid` con `cancellation_status: pending` | Cancelado, en espera o rechazado. `none` en una consulta → se reenvía. `409` → rechazo |
| Estado | `cancellation_status`: none, pending, accepted, rejected, expired | `expired` con `valid` = sigue vigente [S] |
| Errores | `400` (datos), `401`, `404`, `409`, `429`, `500`, con `message` y `code` | 400/404 → "Rechazado por datos". 401, 409 al crear, 429, 5xx y sin respuesta → "Error temporal" (busca antes de reintentar) |

- **Seguridad:**
  - la llave solo vive en `FACTURAPI_KEY`; nunca en base de datos, bitácora ni logs;
  - `sk_live_` solo se acepta con `APP_ENV=production`;
  - las pruebas usan `Http::fake` con `preventStrayRequests` y `phpunit.xml` vacía la llave.
- **Emisor:** RFC, régimen, CP y CSD son los de la organización configurada en el panel de Facturapi. Deben coincidir con Administración → Organización [S].

## 6. Pendientes [S] (Facturapi Test)

1. Timbrado con el complemento `custom` (namespace y `schemaLocation`).
2. `related_documents` en la sustitución.
3. Qué responde ante un `idempotency_key` repetido.
4. `pdf_custom_section`: que el PDF muestre la leyenda.
5. `D04` y `S01` contra el régimen del receptor.
6. Estados reales de cancelación, incluido `expired`.
7. Formato real de `stamp.date` y del UUID.
8. Que la organización de Facturapi coincida con la del CRM.
9. Tipo de tarjeta en Stripe y Mercado Pago (#30).

## 7. Instrucciones: Facturapi Test

1. Crea una cuenta en `https://dashboard.facturapi.io` y una **organización de prueba** con los datos fiscales de la Fundación. Estos deben coincidir con Administración → Organización del CRM local:
   - razón social;
   - RFC;
   - régimen 603;
   - código postal.
2. Si el panel de prueba pide un CSD:
   - sube un **CSD de prueba** (el que Facturapi o el SAT publican para pruebas) desde el propio panel, con su contraseña;
   - nunca uses el CSD real de la Fundación en pruebas;
   - nunca pongas el CSD ni su contraseña en el CRM, en `.env` ni en el chat.
3. En la sección de llaves de esa organización, copia la **llave secreta de prueba** (`sk_test_…`) y agrégala solo a tu `.env` local:
   ```
   CFDI_PROVIDER=facturapi
   CFDI_AUTO_ISSUE=true
   FACTURAPI_KEY=sk_test_…
   ```
4. Recarga la configuración: `docker compose up -d --force-recreate app worker scheduler`. El `worker` debe estar corriendo, porque el timbrado va en cola.
5. En el CRM, Administración → Organización, captura un número y una fecha de oficio de prueba.
6. Crea un donante con datos fiscales de prueba válidos para el SAT (RFC, nombre, régimen y CP coherentes). Registra un donativo en efectivo y confírmalo.
7. Verifica:
   - que quede "Timbrado";
   - que el XML tenga `donat:Donatarias` versión 1.1;
   - que el PDF muestre el oficio y la leyenda.

   Después prueba:
   - cancelar con motivo 02;
   - sustituir con motivo 01;
   - un receptor inválido: debe quedar "Rechazado por datos".
8. Anota los resultados en la lista del §6 y avísame. Nada de esto se marca [V] hasta verlo en Facturapi Test.

## 8. Fuentes

- SAT, Donatarias Autorizadas — Emisión de CFDI (2026): https://www.sat.gob.mx/minisitio/DonatariasAutorizadas/documentos/CFDI_Donatarias2026.pdf
- RMF 2026 (DOF 28-12-2025): https://www.sat.gob.mx/minisitio/NormatividadRMFyRGCE/documentos2026/rmf/rmf/RMF_2026-DOF-28122025.pdf
- SAT, Esquema de cancelación de CFDI 2026: https://www.sat.gob.mx/minisitio/Factura/documentos/EsquemaCancelacionCFDI.pdf
- Complemento Donatarias 1.1: http://www.sat.gob.mx/sitio_internet/cfd/donat/donat11.xsd
- SAT, preguntas frecuentes de donatarias (2024, solo para S01/RESICO): https://www.sat.gob.mx/minisitio/DonatariasAutorizadas/documentos/preguntasfrecuentes/CFDI.pdf
- Facturapi, referencia de la API: https://docs.facturapi.io/api/ y https://docs.facturapi.io/redocusaurus/api-es.yaml
- Facturapi, rescate en intermitencias: https://docs.facturapi.io/docs/guides/invoices/intermitencias
