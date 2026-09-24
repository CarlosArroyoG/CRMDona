# Correo saliente (SMTP administrable)

**Fuente de verdad** del bloque SMTP (2026-09-23). Aplica a cualquier proveedor **SMTP estándar**. No
integra APIs propietarias (Resend, SendGrid, Mailgun, SES…) ni webhooks de rebote.

## 1. Qué configura el Administrador

Menú **Administración → Correo saliente** (`/admin/correo-saliente`). Requiere el permiso `mail.manage`,
que solo tiene el Administrador.

| Campo | Notas |
|---|---|
| Habilitar | Apagado = el CRM usa `MAIL_*` del entorno. Se puede probar antes de habilitar. |
| Servidor SMTP | Nombre DNS o IP, sin `smtp://`, puerto ni rutas. |
| Puerto | 1–65535. |
| Seguridad | `starttls` (STARTTLS obligatorio), `tls` (SSL/TLS implícito, SMTPS) o `none`. |
| Usuario / contraseña | Opcionales (relay sin autenticación). Con usuario, la contraseña es obligatoria. |
| Remitente | Correo (obligatorio si está habilitado) y nombre. |
| Reply-To | Correo y nombre opcionales. |
| Tiempo de espera | 1–120 s (30 por defecto). |

Ejemplos genéricos, sin credenciales. El servidor, el puerto y los requisitos exactos dependen del
proveedor:

| Tipo | Puerto típico | Seguridad |
|---|---|---|
| SMTP STARTTLS | 587 | TLS/STARTTLS |
| SMTPS | 465 | SSL/TLS |
| Relay interno | 25 | Ninguno (solo red de confianza) |

## 2. Contraseña SMTP (secreto)

- **Almacenamiento:**
  - se guarda con el cast `encrypted` de Laravel, que usa `APP_KEY`; en la base solo existe cifrada;
  - está en `$hidden` del modelo, así que nunca se serializa.
- **En la pantalla:**
  - nunca vuelve al navegador: el campo siempre se carga vacío y no se puede revelar;
  - **vacía = conservar la guardada**; con texto = reemplazarla;
  - "Eliminar la contraseña guardada" la quita explícitamente, junto con el usuario;
  - no se recortan espacios.
- **Bitácora:** no se registra ni el valor ni el campo. Queda solo el contexto
  `smtp_password: reemplazada | eliminada`. El usuario SMTP se registra solo por nombre.
- **Errores:**
  - en los errores guardados (`communications.last_error`, `accounting_notices.last_error`) y en los
    logs, `OutgoingMailConfig::scrub()` quita la contraseña y el usuario, en texto y en base64, además
    de `SensitiveData`;
  - el correo de prueba nunca devuelve el mensaje original del servidor.
- **Rotar `APP_KEY`** hace ilegible la contraseña guardada: hay que volver a capturarla.

## 3. Resolución de la configuración

Solo `App\Mail\Outgoing\OutgoingMailConfig` decide. Ningún Mailable ni Notification conoce credenciales.

- **Prioridad:**
  1. `mail_settings` habilitado y completo → mailer `crm`. Su transporte lo construye
     `SmtpTransportFactory` con `EsmtpTransport` de Symfony; `from` y `reply_to` se ponen en la
     configuración del mailer;
  2. si no, `mail.default` del entorno (`MAIL_MAILER`…) sin cambios.
- **Web y comandos:** la configuración se aplica cuando el proceso resuelve el gestor de correo
  (`afterResolving('mail.manager')`). Cada petición y cada `schedule:run` es un proceso nuevo.
- **Worker de la cola:** con `Queue::before`, antes de cada Job se compara
  `mail_settings.version`, que sube en cada guardado. Si cambió, `MailManager::forgetMailers()` descarta
  los mailers en memoria. **Un cambio del Administrador se usa sin redeploy ni reinicio.**
- **Al guardar:** se aplica de inmediato en el mismo proceso.
- **Instalación nueva:** si la tabla todavía no existe (antes de `migrate`), se usa el entorno.

Usan esta resolución, sin cambios en sus reglas de negocio, colas ni idempotencia:

- agradecimiento con el recibo adjunto;
- aviso a Contabilidad;
- cumpleaños;
- alertas operativas y de incidencias;
- recuperación de contraseña.

Un fallo SMTP deja el envío "Fallido" con el error ya depurado. No revierte el donativo ni el recibo, y
se reintenta por el flujo existente.

`SMTP` define **cómo** sale el correo. `users.receives_accounting_notices` define **quién** recibe los
avisos contables. Son independientes.

## 4. Correo de prueba

- `SendTestEmail` usa la configuración **guardada**, aunque no esté habilitada, con un mailer bajo
  demanda (`MailManager::build`). No cambia la configuración.
- Asunto: "Correo de prueba del CRM de [organización]". No lleva datos de donantes.
- **Límite:** 5 intentos cada 10 minutos por Administrador.
- **Errores:** `SmtpErrorTranslator` los clasifica en:
  - conexión o DNS;
  - tiempo de espera;
  - autenticación;
  - TLS;
  - remitente rechazado;
  - destinatario rechazado;
  - otro.

  Cada categoría muestra un mensaje administrativo, sin traza, credenciales ni texto del servidor.
- **Bitácora:** evento `mail_test` con el resultado, la categoría y el destinatario enmascarado. Un éxito
  guarda `last_successful_test_at` y quién hizo la prueba.
- **"Aceptado"** significa que el servidor respondió 250. No garantiza que llegue al buzón.

## 5. Recuperación de contraseña por correo

- El panel habilita `->passwordReset()` de Filament, que ya trae su propio límite de intentos.
- El enlace sale por el correo saliente vigente.
- Al restablecerla, `ClearTemporaryPasswordOnReset` anula una contraseña temporal pendiente (ADR-010).
- Un usuario desactivado puede cambiar su contraseña, pero sigue sin poder entrar
  (`canAccessPanel`).

## 6. DNS y entregabilidad

La pantalla solo informa; el CRM no modifica ni verifica DNS. Se configura con el proveedor y en el DNS
del dominio:

- **SPF**: autoriza a los servidores del proveedor a enviar en nombre del dominio;
- **DKIM**: firma los correos; el proveedor entrega los registros que hay que publicar;
- **DMARC**: indica qué hacer si fallan SPF o DKIM. Conviene empezar con `p=none`.

No hay webhooks de rebote (#33).

## 7. Seguridad

- **Acceso:** solo el Administrador, con el CSRF normal de Filament y la Policy de la página
  (`canAccess`) más el permiso en cada Action.
- **SSRF:** el único contacto de red es el envío SMTP al servidor configurado. No hay sondeos
  arbitrarios ni pruebas de conexión adicionales.
- **Servidores internos o IP privadas:** se permiten a propósito, para relays corporativos. El riesgo es
  que un Administrador apunte el CRM a un servicio interno. Se mitiga porque solo el Administrador puede
  hacerlo, todo queda en la bitácora y el protocolo es solo SMTP.
- **Sin cifrado ("Ninguno"):** solo para redes de confianza. Las credenciales viajarían en claro.

## 8. Almacenamiento

- La tabla `mail_settings` tiene una sola fila (`id = 1`).
- Restricciones (CHECK):
  - seguridad válida;
  - puerto entre 1 y 65535;
  - tiempo de espera entre 1 y 120 s;
  - si está habilitado, requiere servidor, puerto y remitente.
- La migración `2026_10_02_000001_create_mail_settings_table` es aditiva; `down()` elimina la tabla.
