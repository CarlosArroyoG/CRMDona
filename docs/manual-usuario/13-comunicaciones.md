# Comunicaciones con donantes

Menú **Comunicaciones**.

## Correos automáticos

- **Agradecimiento:**
  - Se envía al confirmar un donativo, incluidos los donativos en línea y cada mensualidad.
  - Lleva adjunto el **recibo simple**. Nunca espera ni adjunta un CFDI: los CFDI los emite Contabilidad fuera del CRM (ver [CFDI y Contabilidad](12-cfdi.md)).
- **Cumpleaños:**
  - Todos los días a las 9:00 (hora del centro de México).
  - Solo a donantes que aceptan recibir comunicaciones, que tienen correo y que no están archivados.

Se activan o desactivan en **Administración → Organización → Correos automáticos a donantes**.

## Recibo simple

- Es un **acuse de agradecimiento**, con folio interno (por ejemplo, `R-000123`).
- **No es una factura ni un comprobante fiscal.** El comprobante fiscal es el CFDI, que emite Contabilidad por separado.
- Se descarga o reimprime desde el donativo con **Descargar recibo** o en la sección **Recibo de donación** (Administrador, Coordinador y Contador). Reimprimirlo no genera un folio nuevo.

## Plantillas (Administrador y Coordinador)

1. **Comunicaciones → Plantillas** → **Editar**.
2. Escribe el asunto y el texto. Separa párrafos con una línea en blanco.
3. Usa solo las variables que aparecen bajo el texto, por ejemplo `{{ nombre }}` o `{{ importe }}`.
4. Pulsa **Vista previa** para ver cómo queda con datos de ejemplo.

Qué hace el sistema por su cuenta:
- Si escribes una variable que no existe o dejas llaves sin cerrar, no se guarda.
- La firma y los avisos obligatorios se agregan solos. Por ejemplo: que el recibo no es comprobante fiscal, o el enlace de baja.

## Historial de envíos

**Comunicaciones → Historial de envíos** muestra cada correo con su estado:

| Estado | Significado |
|---|---|
| En cola / Enviando | Todavía no sale |
| Enviado | El servidor de correo lo aceptó |
| Fallido | El servidor de correo no respondió; el sistema reintenta solo |
| No enviado | El donante no tiene correo o no acepta comunicaciones |
| Rebotado | Aún no disponible: depende del proveedor de correo |

- **Reenviar:** manda de nuevo un agradecimiento, con el mismo recibo, al correo actual del donante; por ejemplo, después de corregirlo.
- Los "Envío de CFDI (histórico)" son de antes de que el CRM dejara de emitir CFDI: no se reenvían.
- **Enviar agradecimiento:** desde el donativo, si nunca se envió.

## Baja

Las felicitaciones incluyen un enlace para darse de baja. El donante no necesita cuenta. Al darse de baja, su ficha queda con "Acepta recibir comunicaciones" desactivado y el cambio aparece en la bitácora.
