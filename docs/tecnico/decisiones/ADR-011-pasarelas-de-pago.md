# ADR-011 — Pasarelas de pago: contratos por capacidad, Stripe con SDK oficial y Mercado Pago con cliente HTTP

- **Estado:** aprobado por el responsable (2026-09-22). Implementado en la Fase 2 (2026-09-23).
- **Diseño completo:** `docs/tecnico/fase-2-diseno-pagos.md` (v3).

## Contexto

La Fundación recibirá donativos en línea con **Stripe y Mercado Pago a la vez**. Cada uno puede habilitarse por separado. El dominio (pagos, intentos, reembolsos, disputas, incidencias y donativos) no debe depender de ningún proveedor, y las pruebas nunca deben usar Internet.

## Decisión

1. **Contratos por capacidad** en `app/Payments/Contracts`:
   - `PaymentGateway` (base): webhooks, consulta del estado actual y límites técnicos;
   - `ProcessesOneTimePayments`, `ProcessesRecurringPayments`, `PausesSubscriptions` y `ProcessesRefunds`.

   El dominio pregunta con `instanceof`, nunca por el nombre del proveedor. Así OXXO, SPEI o meses sin intereses serán capacidades nuevas, sin rediseño.
2. **`GatewayRegistry`** entrega la pasarela de cada proveedor según `config/payments.php`. No existe una pasarela global: cada `Payment` y cada `Subscription` guardan su proveedor.
3. **`FakeGateway`** (obligatoria) es determinista, sin Internet, y solo existe en `local` y `testing`. Toda la lógica de negocio se prueba con ella.
4. **Stripe con el SDK oficial `stripe/stripe-php ^21.3`**, usado solo dentro de `app/Payments/Gateways/Stripe`.
   - Instalado: v21.3.2 (MIT), API fijada por el SDK `2026-08-26.dahlia`.
   - Aporta la verificación de la firma del webhook, errores tipados, llaves de idempotencia y versión de API fija.
5. **Mercado Pago con el cliente HTTP de Laravel** (`app/Payments/Gateways/MercadoPago`), sin SDK.
   - La firma HMAC (`x-signature`) se implementó y se probó.
   - El SDK de Mercado Pago no está autorizado. Si el sandbox muestra una razón técnica concreta para usarlo, se pide autorización antes.
6. **Ninguna Action ni pantalla usa clases de un SDK.** Los adaptadores traducen a snapshots normalizados (`app/Payments/Data`).

## Verificaciones de aceptación del SDK de Stripe (2026-09-23)

| Verificación | Resultado |
|---|---|
| Instalación limpia (`composer require stripe/stripe-php:^21.3`) | Solo se agregó `stripe/stripe-php v21.3.2`; ninguna otra dependencia cambió |
| `composer validate` / `check-platform-reqs` | Válido; PHP 8.5.10 compatible |
| Pint, Larastan nivel 8 y Pest | En verde (ver `docs/fases/FASE-02-resumen.md`) |
| Build de producción (`docker build --target prod --no-cache`) | Correcto (ver resumen de fase) |
| CI remoto | Pendiente: se revisa en GitHub después del push autorizado |

## Alternativas descartadas

- **Una sola pasarela:** no cubre la decisión de negocio de ofrecer ambas.
- **SDK de Mercado Pago:** agrega una dependencia sin necesidad demostrada; el cliente HTTP cubre el flujo elegido.
- **Llamar a Stripe desde las Actions:** acoplaría el dominio al proveedor e impediría probar sin Internet.

## Consecuencias

- Una dependencia nueva (`stripe/stripe-php`). Sus actualizaciones mayores cambian la versión de API: se revisan con una ADR.
- El endpoint de webhook de Stripe debe crearse con la misma versión de API que fija el SDK (`2026-08-26.dahlia`).
- Lo marcado **[S] POR CONFIRMAR EN SANDBOX** en el diseño se valida con cuentas de prueba antes de dar por terminado cada adaptador.
