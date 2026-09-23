# Fase 4 — Comunicaciones con donantes

Correos con el sistema de Mail de Laravel, sin paquetes nuevos. El proveedor real (SMTP u otro mailer nativo) se configura solo con las variables `MAIL_*`. En pruebas: `Mail::fake()` y disco simulado.

## 1. Qué se envía

| Correo | Cuándo | Consentimiento | Una sola vez por |
|---|---|---|---|
| **Agradecimiento** con recibo simple | Al confirmarse el donativo (manual, en línea y cada mensualidad), con una espera configurable (`COMMUNICATIONS_THANK_YOU_DELAY`, 300 s) | No se exige: es transaccional | Donativo (`thank_you:donation:{id}`) |
| **CFDI** (XML y PDF) | Al timbrarse, si el agradecimiento ya salió sin él | No se exige | CFDI (`cfdi:{id}`) |
| **Cumpleaños** | Diario a las 09:00 America/Mexico_City | "Acepta recibir comunicaciones", no archivado | Donante y año (`birthday:donor:{id}:{año}`) |

- Si el CFDI ya está timbrado cuando sale el agradecimiento, se adjunta y no hay otro correo de CFDI.
- Si todavía no está timbrado, el agradecimiento sale igual. Si el donativo va por la ruta individual y hay PAC, avisa que el CFDI llegará aparte; en público en general o bloqueo no promete nada.
- Si el CFDI se timbra mientras sale el agradecimiento, al terminar el envío se vuelve a revisar y se manda por separado.
- `communications.transactional_requires_consent` (`COMMUNICATIONS_TRANSACTIONAL_REQUIRES_CONSENT`, `false`) permite exigir el consentimiento también en los transaccionales.
- Los interruptores "Agradecimiento" y "Cumpleaños" están en Administración → Organización.

## 2. Recibo simple (`donation_receipts`)

- Uno por donativo confirmado; folio interno `R-000123`; PDF en disco privado (`receipts/AAAA/MM/`).
- Contenido:
  - organización (razón social y RFC);
  - nombre del donante (sin RFC ni datos de contacto);
  - fechas, importe (o descripción y valor registrado si es en especie) y destino.
- Dice expresamente que **no es una factura ni un comprobante fiscal (CFDI)**. No duplica el XML ni el PDF fiscal.
- El PDF se genera con `App\Support\SimplePdf` (texto, sin logotipo; pendiente #35).
- Descarga en `/admin/receipt-files/{id}`, solo con `receipts.view`.

## 3. Envío e idempotencia

- `communications` registra cada correo con estos datos:
  - tipo, donante, donativo o CFDI y `dedupe_key` única;
  - estado, destinatario **enmascarado** y asunto;
  - nombres de los adjuntos, intentos, motivo de no envío, último error y quién lo pidió (nulo = automático).
- No guarda el cuerpo del mensaje.
- Estados: En cola → Enviando → Enviado | Fallido | No enviado (sin correo o sin consentimiento).
- "Rebotado" existe en el modelo, pero requiere los avisos de rebote del proveedor de correo (pendiente #33).
- `SendCommunication`:
  - toma exclusiva con un UPDATE condicional; un "Enviado" nunca se repite;
  - 3 intentos con espera (1, 5 y 30 min);
  - un "Enviando" abandonado se retoma a los 15 min;
  - un error de correo nunca revierte la confirmación del donativo ni el timbrado (`rescue`).
- Reenvío manual: crea otro registro con quien lo pidió. Las felicitaciones no se reenvían.

## 4. Plantillas

- Una por tipo (`message_templates`), editable en Comunicaciones → Plantillas.
- Formato: texto simple con variables `{{ variable }}` de una lista cerrada por tipo; nunca Blade ni HTML.
- Variables:
  - `nombre` y `organizacion` en todos los tipos;
  - `importe`, `fecha_donativo`, `destino` y `folio_recibo` en el agradecimiento;
  - `importe`, `fecha_donativo` y `folio_fiscal` en el de CFDI.
- Al guardar se rechazan llaves sin cerrar y variables desconocidas. Si aun así una plantilla falla al enviar, se usa el texto predeterminado y el envío queda marcado.
- La firma y los avisos obligatorios (recibo no fiscal, CFDI, baja) los agrega el sistema.
- La vista previa usa datos de ejemplo, nunca de donantes reales.

## 5. Baja de comunicaciones

- Enlace `/comunicaciones/baja/{token}` en las felicitaciones (y en la cabecera `List-Unsubscribe`).
- El token son 64 hex aleatorios (256 bits), únicos por donante y creados al primer uso.
- GET solo muestra la confirmación; POST aplica la baja. Sin sesión, con límite de 30 por minuto.
- La página no muestra datos del donante. Un token alterado no encuentra a nadie (404).
- En la bitácora queda el cambio de `accepts_communications`, con procedencia "Donante" y sin datos personales.

## 6. Permisos

| Permiso | A | C | Co | L |
|---|---|---|---|---|
| `receipts.view` (descargar recibo) | Sí | Sí | Sí | No |
| `communications.view` (historial) | Sí | Sí | Sí | No |
| `communications.resend` (reenviar, enviar agradecimiento a mano) | Sí | Sí | Sí | No |
| `communications.templates` (editar plantillas) | Sí | Sí | No | No |

## 7. Configuración en producción

- `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` y `MAIL_FROM_NAME`, en las variables de Coolify.
- El `worker` y el `scheduler` deben estar activos.
