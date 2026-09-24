# Cobro asistido, identidad institucional y felicitación por WhatsApp

Fuente de verdad del bloque aprobado el 2026-09-24. Complementa `fase-2-diseno-pagos.md`,
`fase-6-pagina-publica.md` y `fase-4-comunicaciones.md`.

## 1. Decisiones del Product Owner

| Tema | Decisión |
|---|---|
| Tarjeta | **Nunca pasa por el CRM.** El donante la escribe en la página segura del proveedor (Stripe Checkout embebido o Mercado Pago Brick), en el equipo presente o con el enlace. |
| MOTO | **Fuera de alcance.** El personal no captura tarjetas dictadas por teléfono ni maneja PAN o CVV. |
| Vigencia del enlace | 7 días (`PaymentRequest::VALID_DAYS`). Se puede cancelar y regenerar. Regenerar invalida el token anterior. |
| Correo con enlace | **Transaccional e individual**: una solicitud concreta, para su donante, pedida por una persona. No es marketing ni envío masivo; no existe forma de mandarlo a una lista. |
| WhatsApp | Solo **"Preparar WhatsApp"** con `wa.me`: el CRM abre la conversación con el texto; la persona envía. Requiere `accepts_communications = true`. Sin API de Meta ni consentimiento adicional por ahora. |
| Favicon | Derivado del logo institucional; sin campo aparte. |
| `payments.request` | Administrador y Coordinador. El Contador no genera solicitudes. `payments.view` sin cambios. |

## 2. Solicitud de pago (`payment_requests`)

"Crear donativo" tiene el selector **¿Cómo se recibe?**:

- **Ya se recibió**: el flujo manual de siempre (`RegisterDonation`, nace "Por confirmar").
- **Cobrar con tarjeta en línea** (solo con `payments.request`, solo al crear): reutiliza donante, importe, destino y
  CFDI solicitado; fija dinero; oculta forma de pago, fecha, referencia y notas; pide Único o Mensual. **No crea un
  Donation**: `CreatePaymentRequest` crea la solicitud y la pantalla lleva a su ficha con **Abrir pago ahora**,
  **Copiar enlace** y **Enviar por correo**.

Tabla `payment_requests` (migración `2026_10_04_000001`, reversible):

- `token_hash` (SHA-256, único) para buscar y `token` cifrado (cast `encrypted`, `APP_KEY`) para volver a copiarlo o
  enviarlo. El token son 40 caracteres de `Str::random` (fuente `random_bytes`). Ni el token ni el hash van a la
  bitácora (`auditValueFields` los excluye) ni a la serialización (`$hidden`).
- `donor_id`, `amount` (`numeric(12,2)`, > 0), `frequency` (`one_time`/`monthly`), `campaign_id` **o** `program_id`,
  `tax_receipt_requested`.
- `status` (`open`, `paid`, `cancelled`); "Vencida" se calcula (`open` con `expires_at` pasado).
- `payment_id` / `subscription_id` (último iniciado o el pagado), `token_version`, `attempt`, `paid_at`,
  `cancelled_at`/`cancelled_by_id`, `created_by_id`.
- CHECK de estado, frecuencia, destino único, `paid_at` ↔ `paid` y `subscription_id` solo en mensual.

La URL pública es `/donar/enlace/{token}`: sin IDs ni importes. Enviar al navegador otro importe, frecuencia,
destino o donante no cambia nada.

## 3. Reutilización de `/donar` (sin segundo checkout)

`OpenPaymentRequest` convierte la solicitud en **el mismo payload de sesión** que arma el formulario público
(`donor_id` ya resuelto, importe, frecuencia, destino, `tax`, llave de idempotencia) y redirige a
`/donar/resumen/{sesión}`. De ahí en adelante todo es lo existente: `pay` → `StartPublicDonation` →
`StartOneTimeDonation` / `StartMonthlyDonation` → proveedor → webhook → `SyncPayment` → `CreateDonationFromPayment`.

- Antes de mostrar el resumen y otra vez antes de cobrar se revalidan: vigencia y estado, donante no archivado,
  proveedor disponible (y que admita mensual), aviso de privacidad configurado, límites de importe y destino vigente.
- `StartPublicDonation` ahora transporta `program_id` (programa directo sin campaña) y valida que siga activo.
- El resumen dice que la Fundación preparó el donativo y no ofrece "Corregir mis datos".

### Idempotencia

Llave `payment-request:{id}:{intento}`. Abrir el enlace varias veces, en dos navegadores o pagar dos veces reutiliza
el mismo Payment/Subscription y la misma sesión del proveedor (`createOrFirst` + misma llave para Stripe). Se pasa a
un intento nuevo solo si:

- el anterior terminó sin cobro (fallido o cancelado);
- la persona usa **Reintentar** tras un rechazo (el reintento existente de `/donar`);
- el anterior sigue abierto después de **24 h**: es el plazo en que Stripe conserva la llave de idempotencia y en que
  vence la sesión de Checkout, así nunca hay dos sesiones pagables a la vez.

### Estado autoritativo

La solicitud se marca **pagada solo** desde `SyncPayment`, cuando el proveedor confirma el pago
(`CompletePaymentRequest`). La solicitud se reconoce por la llave del pago o de su donativo mensual, así funciona
después de reintentos. El regreso del navegador nunca cambia nada. Si alguien cancela o regenera mientras un pago
estaba en curso y el proveedor lo confirma, el dinero llegó: la solicitud queda pagada.

## 4. Correo de la solicitud

`CommunicationKind::PaymentRequest` con plantilla editable (Comunicaciones → Plantillas de correo; variables
`nombre`, `organizacion`, `importe`, `frecuencia`, `destino`, `vigencia`). Pasa por `QueueCommunication` y el SMTP
existente. El botón "Completar mi donativo" lo agrega el sistema al enviar (`ComposedMessage::actionUrl`): el enlace
**no se guarda** en `communications`, en la bitácora ni en los logs. Si al enviar la solicitud ya no está vigente,
queda "No enviado" con el motivo. Es transaccional: se envía aunque el donante no acepte comunicaciones
(`communications.transactional_requires_consent`).

## 5. Identidad institucional

`App\Support\Branding` es la única puerta a la identidad: nombre (`legal_name` o `APP_NAME`), logo
(`organization_settings.logo_path`, solo PNG/JPG, disco público) y favicon.

| Lugar | Uso |
|---|---|
| Panel y acceso | `brandLogo` + `favicon` de Filament. El logo va sobre una placa blanca en el encabezado azul (el logotipo es oscuro sobre claro). |
| `/donar` | Encabezado con el logo sobre placa blanca, `<link rel="icon">` y `apple-touch-icon`. |
| Favicon | `/favicon.png`: PNG cuadrado de 180 px derivado del logo con GD (proporción intacta, fondo transparente), guardado una vez por logo. Sin logo: `favicon.ico`. |
| Recibo PDF | `SimplePdf::image()`: el logo se convierte a JPG sobre blanco y se incrusta (DCTDecode) en máx. 160 × 60 pt sin deformarse. Folio, textos y la leyenda "no es factura ni comprobante fiscal (CFDI)" no cambian. |
| Correos | Encabezado blanco con el logo (56 px de alto) en agradecimiento, cumpleaños y solicitud de pago; sin logo, el nombre sobre azul. |

Nunca se descarga ni se escribe un logo en el código: el oficial lo sube Administración en **Organización**. El
archivo se revalida por contenido (PNG/JPG, límite de píxeles) antes de procesarlo; SVG sigue rechazado.

## 6. Felicitación por WhatsApp

`PrepareBirthdayWhatsApp` + acción de Filament **Preparar WhatsApp** (ficha del donante con fecha de nacimiento y
"Cumpleaños próximos" del Escritorio):

- Condiciones: permiso `communications.resend`, donante no archivado, `accepts_communications = true` y teléfono
  utilizable según `WhatsAppPhone::normalize()` (conservador: 10 dígitos → 52; `+52`, `0052`, `521`; otro país solo
  con `+`/`00` explícito; lo ambiguo no se ofrece).
- Texto: la plantilla de cumpleaños vía `MessageComposer::birthdayText()` (sin duplicar contenido).
- Abre `https://wa.me/<número>?text=<mensaje>` y la interfaz dice **"Se abrió WhatsApp; confirma el envío ahí."**
- Bitácora: evento "WhatsApp preparado (envío manual)" en el donante, **sin teléfono ni texto**. No se registra
  como comunicación enviada.

## 7. Mercado Pago

La solicitud, el enlace, el payload, la idempotencia, el cierre por webhook y el correo no dependen del proveedor:
`/donar` usa el que esté habilitado. **Pendiente de validar con el sandbox institucional (#15):** el Card Payment
Brick en el resumen del enlace (reintento con la misma llave y un `card_token` nuevo), el `/preapproval` mensual con
`card_token`, la firma y el `ts` de sus notificaciones (#46) y sus límites técnicos. No se implementó comportamiento
de Mercado Pago que requiera ese sandbox.

## 8. Estado de validación

- Stripe **Test validado** end-to-end (2026-09-23). Pendiente: cuenta y configuración **Live** institucional (#14).
- Mercado Pago: sandbox institucional pendiente (#15).
- Pruebas automáticas con FakeGateway: `tests/Feature/PaymentRequests`, `tests/Feature/Filament/PaymentRequestScreensTest.php`,
  `tests/Feature/Organization/BrandingTest.php`, `tests/Feature/Donors/BirthdayWhatsAppTest.php`,
  `tests/Unit/Support/WhatsAppPhoneTest.php`.
