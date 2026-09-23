# Fase 6 — Página pública de donativos

Blade + Tailwind dentro del mismo proyecto, sin framework JS ni paquetes nuevos. El JS inline es mínimo y la página funciona también sin JS.

## 1. Flujo

1. **`/donar` (o `/donar/campana/{identificador}`):** el formulario.
2. **POST:** validación en el servidor (`ValidatePublicDonationForm`). Lo validado se guarda en la sesión del navegador bajo un token aleatorio de 40 caracteres. La llave de idempotencia la genera el servidor (`public:{uuid}`).
3. **`/donar/resumen/{token}`:** resumen y botón de pago.
4. **POST `/donar/pagar/{token}`:** `StartPublicDonation`:
   - resuelve el donante;
   - llama a `StartOneTimeDonation` o `StartMonthlyDonation` de la Fase 2, sin cambiar la capa de pagos.
5. **`/donar/estado/{token}`:** estado **real** en la base de datos (`PublicDonationStatus`): confirmado, en proceso (se actualiza solo), rechazado o fallido (con "Intentar de nuevo").
6. **Regreso del proveedor (`/donar/gracias`):** no lee ningún parámetro; redirige al estado del último donativo de esa sesión.

El Donation lo crea el flujo existente solo cuando el Payment queda `succeeded`, por la respuesta del proveedor, su webhook o la conciliación. Recibo, agradecimiento y CFDI siguen sus flujos de las Fases 3 y 4.

## 2. Proveedores

- **Selección:** `DONATIONS_PUBLIC_PROVIDER`; si está vacío, se usa el primero habilitado (Stripe, Mercado Pago o, solo en local, FakeGateway).
- **FakeGateway:** el resumen ofrece el resultado del pago (aprobado, rechazado, fondos insuficientes o pendiente). Permite demostrar todo de punta a punta sin credenciales.
- **Stripe [S]:** se renderiza el Checkout embebido con la `client_secret` que devuelve el adaptador y la llave publicable. Al terminar, Stripe regresa a `PAYMENTS_RETURN_URL` (`/donar/gracias`).
- **Mercado Pago [S]:** Card Payment Brick con la llave pública. El token de tarjeta se envía por POST (con CSRF) y el servidor inicia el pago. Si el adaptador devuelve `redirectUrl` (por ejemplo, la mensualidad), se redirige.
- Stripe y Mercado Pago no se han probado en sus sandbox (pendientes #14 y #15).

## 3. Donante

- **Coincidencia evidente (mismo correo, no archivado):**
  - se reutiliza el donante sin modificar datos, consentimientos ni datos fiscales;
  - si lo capturado es distinto (otro RFC, o marcó comunicaciones), queda una **nota interna** sin el RFC.
- **Sin coincidencia:**
  - se crea con `origin = public_page` y `registered_by_id` nulo (migración con CHECK, igual que `donations.origin`);
  - guarda la versión del aviso de privacidad y el consentimiento de comunicaciones (opcional, nunca preseleccionado);
  - si el RFC coincide con otro donante, queda una nota de posible duplicado. Nunca se fusiona.
- Nada de esto se muestra al público.
- Un doble envío simultáneo no crea dos donantes: hay un bloqueo por correo (`pg_advisory_xact_lock`).
- Bitácora: procedencia "Donante (página pública o enlace de baja)".

## 4. Datos fiscales

- La casilla "Quiero mi comprobante fiscal a mi nombre" solo pide lo que exige un CFDI 4.0 para el receptor: RFC, nombre, régimen y CP fiscal. Se valida con `SaveDonorTaxProfile`. No se pide el uso de CFDI: lo decide `BuildDonationCfdiDraft`.
- La obligación fiscal no depende de la casilla: la cobertura (individual, público en general o bloqueado) la resuelve `ResolveDonationFiscalRoute`.
- No hay agregador de factura global.

## 5. Campañas

- `Campaign::acceptsDonations()`: activa, dentro de `starts_on`/`ends_on` si existen y con su programa activo si tiene programa.
- Un enlace inválido, en borrador, terminado, archivado o fuera de fechas responde 404 (y 422 al enviar).
- La campaña sale de la URL, nunca del formulario, y se vuelve a validar al pagar.
- Sin campaña, el donativo va al fondo general (ya válido en el dominio).

## 6. Seguridad y privacidad

- **Entrada:**
  - CSRF (grupo `web`) y validación en el servidor;
  - límite de 10 envíos por minuto por IP (`DONATIONS_RATE_LIMIT`);
  - campo trampa y tiempo mínimo de 3 s desde que se abrió el formulario (`DONATIONS_MIN_SECONDS_TO_SUBMIT`).
- **Manipulación:**
  - el importe solo puede ser una cantidad sugerida u "otro" validado contra los límites de Organización y del proveedor;
  - la frecuencia solo puede ser única o mensual (si el proveedor la admite);
  - al pagar se ignoran importe, campaña, frecuencia, donante o estado enviados por el navegador.
- **IDs:** no se exponen IDs internos; los tokens solo valen en la sesión que los creó.
- **Errores:** son genéricos y sin detalles internos.
- **Secretos:** solo se usan llaves públicas (la publicable de Stripe y la pública de Mercado Pago); ningún secreto llega al HTML ni a los logs.
- **Colores:** los configurables se validan como hexadecimales antes de entrar al CSS.
- **Privacidad:**
  - el enlace al aviso es visible y aceptarlo es obligatorio; sin aviso configurado la página no acepta donativos;
  - el consentimiento de comunicaciones es separado y opcional;
  - agradecimiento, recibo y CFDI son transaccionales (#34).
- **Fase 7:** CSP y cabeceras de seguridad quedan para el hardening general.

## 7. Configuración

- `DONATIONS_SUGGESTED_AMOUNTS`: `200,500,1000,2000` por defecto; es una decisión reversible.
- `DONATIONS_PUBLIC_PROVIDER`, `DONATIONS_RATE_LIMIT`, `DONATIONS_MIN_SECONDS_TO_SUBMIT`.
- `BRAND_PRIMARY_COLOR` y `BRAND_SECONDARY_COLOR`.
- Logotipo y aviso de privacidad: en Organización.

## 8. Administración

- La ficha de la campaña muestra su enlace público (copiable), indica si recibe donativos y tiene el botón "Abrir página pública".
- Organización muestra la URL general.
