# Fase 6 — Página pública de donativos (cierre)

- **Fecha:** 2026-09-23.
- **Estado:** cerrada con FakeGateway. Stripe y Mercado Pago quedan en [S] hasta sus sandbox.
- **Fuente de verdad:** `docs/tecnico/fase-6-pagina-publica.md`.

## Flujo de punta a punta

1. `/donar` o `/donar/campana/{identificador}`: formulario (único o mensual, cantidad sugerida o libre, datos del donante, datos fiscales opcionales, aviso de privacidad y consentimiento separado).
2. Resumen.
3. Pago con `StartOneTimeDonation` o `StartMonthlyDonation` (sin cambios a la capa de pagos).
4. Estado real, leído de la base de datos.

Lo demás ocurre en los flujos existentes: el Payment confirmado genera el Donation, y luego salen el recibo, el agradecimiento y la cobertura fiscal (y el CFDI, si hay PAC y datos fiscales).

**Verificado en `crm` por HTTP real** (FakeGateway activado solo durante la prueba):
- página con estilos;
- donante nuevo con `origin = public_page` y `registered_by_id` nulo;
- Payment `succeeded` → Donation `confirmed` (en línea) relacionados;
- recibo `R-000001`, agradecimiento en cola y ruta fiscal "público en general" (sin datos fiscales);
- bitácora con procedencia "donante".

Los registros de prueba se identifican como "PRUEBA SMOKE Fase 6" / `smoke-fase6@example.invalid`. Se conservan porque los donativos no se borran por diseño. `.env` y el aviso de privacidad se restauraron.

## Seguridad

- CSRF, validación en el servidor, límite por IP, campo trampa y tiempo mínimo.
- Importe, campaña y frecuencia solo del servidor.
- Tokens de sesión en lugar de IDs.
- Estado real, nunca por la URL de regreso.
- Errores genéricos y solo llaves públicas en el HTML.
- Donantes existentes: se reutilizan sin modificarse, con notas internas.

## Migración aplicada a `crm`

- **Migración:** `2026_09_28_000001_add_public_origin_to_donors_table`, con `php artisan migrate` (base `crm`).
- **Verificación:** 1 usuario conservado; los donantes existentes siguen cumpliendo el CHECK.
- **Pruebas previas:** probada antes en `crm_validation` (aplicar, revertir, volver a aplicar y `migrate:fresh --seed`).

## Build del frontend

- **Causa del fallo anterior:** el disco compartido de Windows (Docker Desktop con libkrun) no guarda los enlaces simbólicos. `npm ci` termina, pero `node_modules/.bin/vite` queda sin enlace, y por eso aparecía `vite: not found`. No es un problema del proyecto ni del lockfile.
- `npm ci` con el lockfile funciona. El build local se hace con `node node_modules/vite/bin/vite.js build`, y el CSS incluye las clases de `/donar`.
- La imagen `prod` (Linux, `npm ci` + `npm run build`) construye bien, con las clases en el bundle.

## Pruebas

- Pint, Larastan nivel 8 y Pest: 583 pruebas en verde, en 5 corridas completas seguidas.
- `composer validate` y `composer check-platform-reqs` en verde.
- **Prueba intermitente de `WebhookInboxTest`:**
  - **Causa:** el worker de pruebas (`queue:work --stop-when-empty`) tenía 128 MB de límite. Al final de la suite completa se detenía tras el primer job.
  - **Reproducción:** determinista con `--memory=1`.
  - **Corrección:** `runQueueWorker()` con `--memory=4096`; `CfdiTest` usa el mismo helper.
- **Prueba de concurrencia de reembolsos (Fase 4):**
  - no se reprodujo en 25 corridas aisladas, 15 del grupo, con CPU saturada ni en 6 corridas completas;
  - se verificó que `flock` excluye entre procesos en el disco compartido;
  - el código no muestra una carrera: el bloqueo del pago serializa la creación y FakeGateway serializa con la misma llave;
  - las pruebas de concurrencia ahora muestran la excepción del proceso hijo si vuelven a fallar.

## Pendientes reales

- Sandbox de Stripe y Mercado Pago, incluida la página pública con Checkout embebido y Brick (#14, #15).
- Facturapi Test (#24, #27).
- SMTP real y rebotes (#32, #33).
- Factura global (#29).
- Aviso de privacidad con las finalidades transaccionales (#34).
- Cantidades sugeridas definitivas (#39; hoy 200, 500, 1000 y 2000).
- Hardening de la Fase 7 (CSP y cabeceras).
