# Fase 3 — CFDI de donativos (estado y reglas)

Marcas:
- **[V]** verificado en documentación oficial del SAT;
- **[F]** requiere decisión o validación fiscal;
- **[S]** requiere sandbox o PAC.

Fuente principal [V]: SAT, micrositio Donatarias Autorizadas, *Preguntas frecuentes — Emisión de facturas electrónicas* (documento de 2024, con fundamento en la RMF 2024), y el esquema de cancelación vigente del SAT.

## 1. Reglas implementadas [V]

| Regla | Valor | Dónde |
|---|---|---|
| Tipo de comprobante | Ingreso (`I`) | `BuildDonationCfdiDraft` |
| Método de pago | `PUE`; el SAT indica que PPD no aplica a donativos | ídem |
| Forma de pago | Según catálogo: efectivo `01`, cheque `02`, transferencia `03` | ídem |
| Uso de CFDI | `D04` persona física; `S01` si su régimen es 626 (RESICO); `G03` persona moral. Si el perfil del donante trae otro valor, se pide corregir; no se sobrescribe | ídem |
| Concepto | Clave `84101600`, cantidad `1`, unidad `M4`, descripción "Donativo", valor = importe, `ObjetoImp 01` | ídem |
| Complemento de donatarias 1.1 | Obligatorio: número de oficio, fecha de oficio y la leyenda textual del SAT | ídem; datos de Organización |
| Momento de emisión | Nunca antes de recibir el donativo (solo donativos confirmados). El SAT pide emitir en 24 h | ídem / §2 |
| Cancelación | Motivos `01`–`04`; el `01` exige el UUID que sustituye; puede requerir aceptación del receptor (3 días hábiles) | `RequestCfdiCancellation`, `CancelCfdi` |

## 2. Cuestiones [F] (bloqueadas en código; no se inventa regla)

1. **Contradicción con el requisito original: ¿solo cuando el donante lo solicita?**
   - El requisito del proyecto dice "CFDI solo cuando corresponda y el donante solicite comprobante fiscal".
   - El SAT dice que la donataria **tiene la obligación de expedir CFDI por los donativos que reciba** (pregunta 1) y que debe emitirse dentro de las 24 horas (pregunta 2).
   - Falta decidir con el contador:
     - si se emite CFDI a todo donativo;
     - qué hacer con los donantes sin RFC (público en general `XAXX010101000` o factura global);
     - el plazo.
   - Mientras tanto: `CFDI_AUTO_ISSUE=false` y sin datos fiscales del donante no se emite.
2. **Donativos en especie:** clave del bien, unidad, valuación y forma de pago `12` (dación en pago), verificada. Bloqueado.
3. **Tarjeta en línea:** crédito (`04`) o débito (`28`) requiere decidir el origen del dato (tipo de tarjeta en el proveedor [S]). Bloqueado.
4. **Depósito bancario:** efectivo o cheque depositado. Bloqueado.
5. **Vigencia de la autorización de donataria:** el complemento solo aplica con autorización vigente (renovación anual); el CRM no la verifica.
6. **Reembolsos, contracargos y cancelación del donativo:** ¿cancelar el CFDI? ¿con qué motivo? Hoy nada es automático.
7. **Sustitución (motivo 01) y motivo 04:** requieren CFDI relacionado; hoy solo se permite cancelar con `02` y `03`.
8. **Vigencia de la fuente:** confirmar que la guía del SAT (RMF 2024) no cambió en la RMF 2026 (Anexo 20 vigente).
9. **Permisos propuestos** (reversibles en la matriz):
   - `cfdi.view`: Administrador, Coordinador y Contador;
   - `cfdi.issue` y `cfdi.cancel`: Administrador y Contador;
   - Solo lectura, nada.

## 3. Cuestiones [S]

- Emisión real con complemento de donatarias en el PAC elegido.
- Idempotencia del PAC ante reintentos.
- PDF que imprima la leyenda.
- Validación de `D04` y `S01` contra el régimen del receptor (matriz del SAT).
- Estados reales de cancelación y aceptación.
- CSD de prueba.

## 4. Arquitectura (independiente del PAC)

- **`cfdis`** (Donation 1→N; como máximo uno vigente por donativo, con índice único). No copia datos fiscales: lo emitido es el XML en el disco privado (`storage/app/private/cfdi/AAAA/MM/UUID.xml|pdf`).
- **Estados:** `pending → stamping → stamped | failed (se reintenta) | rejected (se corrige)`, y `stamped → cancellation_pending → cancelled | stamped`.
- **Contrato `CfdiProvider`** (timbrar, cancelar, estado de cancelación), más la capacidad `RendersCfdiPdf` y `CfdiProviderRegistry` (`CFDI_PROVIDER`).
- **`FakeCfdiProvider`:** solo local y testing; XML "sin validez fiscal"; idempotente.
- **Actions:**
  - `BuildDonationCfdiDraft` (reglas);
  - `RequestDonationCfdi` (idempotente);
  - `RetryCfdi`;
  - `RequestCfdiCancellation`;
  - `IssueCfdiAutomatically` (cada donativo confirmado, incluida cada mensualidad; apagado).
- **Jobs:**
  - `StampCfdi`: toma exclusiva, PAC sin transacción abierta y misma llave;
  - `CancelCfdi`;
  - `ReconcileCfdis`: cada 15 minutos; timbrados interrumpidos, errores y cancelaciones en espera.
- **Filament y seguridad:**
  - pantalla CFDI, acción "Emitir CFDI" en el donativo y descargas solo con permiso (`/admin/cfdi-files/{id}/{xml|pdf}`);
  - la bitácora audita solicitud, timbrado y cancelación (con procedencia).

## 5. PAC candidatos (para elegir)

| | Facturapi | Facturama (API Multiemisor) | SW sapien |
|---|---|---|---|
| API | REST JSON | REST JSON | REST (JSON o XML) |
| CFDI 4.0 | Sí | Sí | Sí |
| Donatarias | Complemento como XML en `complements` [S] | Documentado en su soporte [S] | Documentado con ejemplos (1.1) |
| Timbrado | El PAC arma y sella (guarda el CSD) | Arma y sella (CSD subido por API); el folio lo pone el emisor | Emisión JSON: sella y timbra |
| Cancelación | Sí (motivo y sustitución) | Sí | Sí (UUID, CSD) |
| XML/PDF | Ambos | Ambos | XML; PDF con servicio aparte |
| Sandbox | Llaves de prueba | `apisandbox.facturama.mx` (15 timbres de prueba) | `services.test.sw.com.mx` |
| SDK/REST | SDK PHP; basta el cliente HTTP | SDK PHP; basta el cliente HTTP | SDK PHP; token de 2 h |
| Ventaja técnica | API más simple, PDF incluido | Complemento documentado; sandbox gratuito | Es PAC directo; donatarias con ejemplos |
| Desventaja técnica | El complemento va como XML crudo; el PDF podría no mostrarlo | Folios y CSD a nuestro cargo | Más bajo nivel (token, PDF aparte) |

Recomendación técnica: **Facturapi** (menos piezas para el adaptador). La alternativa es **SW sapien** si el sandbox no acepta el complemento de donatarias. No se integra hasta la aprobación.

Fuentes:
- SAT, Donatarias Autorizadas: https://www.sat.gob.mx/minisitio/DonatariasAutorizadas/documentos/preguntasfrecuentes/CFDI.pdf
- Complemento de donatarias 1.1: http://www.sat.gob.mx/sitio_internet/cfd/donat/donat11.xsd
- Esquema de cancelación del SAT: https://www.sat.gob.mx/minisitio/Factura/documentos/EsquemaCancelacionCFDI.pdf
- Facturapi, complementos: https://docs.facturapi.io/en/docs/guides/invoices/complementos/
- Facturama, API Multiemisor CFDI 4.0: https://apisandbox.facturama.mx/guias/cfdi40/multiemisor
- SW sapien, donatarias: https://developers.sw.com.mx/knowledge-base/donatarias/
