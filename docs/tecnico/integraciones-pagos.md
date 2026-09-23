# Integraciones de pagos — Stripe y Mercado Pago

Guía técnica de operación (Fase 2). Diseño: `fase-2-diseno-pagos.md`. Decisión: ADR-011.

Marcas: **[V]** verificado en documentación oficial · **[S]** por confirmar en sandbox · **[D]** decisión de negocio.

## 1. Arquitectura

```
Página pública (fase posterior) ─► StartOneTimeDonation / StartMonthlyDonation
                                        │  guarda Payment/Subscription (llave de idempotencia)
                                        ▼
                              GatewayRegistry ─► Stripe | Mercado Pago | Fake
                                        ▲
Proveedor ─► POST /webhooks/payments/{proveedor}
              1. verifica firma (400 si no)       RecordWebhookEvent
              2. guarda una vez (lista permitida)  (INSERT … ON CONFLICT DO NOTHING)
              3. encola y responde 200
                                        ▼
                         ProcessWebhookEvent (cola Redis, sin transacción abierta)
                           consulta el estado ACTUAL del recurso en el proveedor
                                        ▼
                         ApplyProviderSnapshot ─► SyncSubscription / SyncPayment /
                           SyncRefund / SyncDispute (con bloqueo de fila)
                                        ▼
                     PaymentAttempt · Donation (solo si succeeded) · PaymentIncident → alerta
```

- **Conciliación** (`ReconcilePayments`, cada 15 minutos con el scheduler):
  - reenvía reembolsos sin respuesta con su misma llave;
  - consulta pagos y reembolsos que siguen en proceso;
  - crea el donativo que falte de un pago exitoso, o abre una incidencia.
- **Reintentos de cobro:** nunca los hace el CRM en esta fase (`retry_owner = provider`). Stripe usa Smart Retries y Mercado Pago, "recycling".

## 2. Variables de entorno

| Variable | Uso |
|---|---|
| `STRIPE_ENABLED` | `true` para habilitar Stripe |
| `STRIPE_MODE` | `test` o `live`. Debe coincidir con la llave (`sk_test_` / `sk_live_`); si no, la pasarela no arranca |
| `STRIPE_SECRET_KEY` | Llave secreta (o restringida) |
| `STRIPE_PUBLISHABLE_KEY` | Llave pública, para la página pública |
| `STRIPE_WEBHOOK_SECRET` | Secreto de firma del endpoint (`whsec_…`) |
| `MERCADO_PAGO_ENABLED` | `true` para habilitar Mercado Pago |
| `MERCADO_PAGO_MODE` | `test` o `live` (Mercado Pago no lo indica en la credencial: se declara) |
| `MERCADO_PAGO_ACCESS_TOKEN` | Access token de la aplicación |
| `MERCADO_PAGO_PUBLIC_KEY` | Public key, para la página pública (Bricks) |
| `MERCADO_PAGO_WEBHOOK_SECRET` | Clave secreta de las notificaciones (firma `x-signature`) |
| `PAYMENTS_FAKE_ENABLED` | Pasarela simulada: solo local; se ignora fuera de `local` y `testing` |

- Las llaves van **solo** en `.env` (local) o en las variables del recurso en Coolify. Nunca en git, seeders, pruebas, logs, bitácora, exportaciones ni documentación.
- La pantalla **Administración → Pasarelas de pago** muestra si cada una está habilitada y en qué modo, sin mostrar las llaves.

## 3. Instrucciones de sandbox

### 3.1 Stripe (modo de prueba)

1. **Cuenta.** Crear o entrar a una cuenta de Stripe (dashboard.stripe.com) con país **México**. No hace falta activarla para usar el modo de prueba.
2. **Modo de prueba.** Activar el interruptor "Test mode" / entorno de prueba (sandbox) en el Dashboard.
3. **Llaves.** En Developers → API keys, copiar:
   - "Secret key" (`sk_test_…`) → `STRIPE_SECRET_KEY`;
   - "Publishable key" (`pk_test_…`) → `STRIPE_PUBLISHABLE_KEY`.
4. **Webhook local.** Con Stripe CLI (los pagos creados desde el Dashboard no disparan webhooks; con la CLI sí [V]):
   ```
   stripe login
   stripe listen --forward-to http://localhost:8000/webhooks/payments/stripe
   ```
   La CLI imprime `whsec_…` → `STRIPE_WEBHOOK_SECRET`.
5. **Webhook en un servidor de prueba.** En Developers → Webhooks:
   - agregar el endpoint `https://<dominio>/webhooks/payments/stripe`;
   - versión de API `2026-08-26.dahlia`;
   - eventos: `checkout.session.*`, `payment_intent.*`, `charge.*`, `charge.dispute.*`, `refund.*`, `invoice.*`, `invoice_payment.paid` y `customer.subscription.*`.
6. **Smart Retries.** En Billing → Revenue recovery → Retries:
   - dejar la política predeterminada;
   - en "si fallan todos los reintentos", elegir **dejar la suscripción vencida (past due)**, no cancelar ni marcar como impagada [V]. Las etiquetas exactas de la pantalla se confirman en sandbox [S].
7. **`.env` local.**
   ```
   STRIPE_ENABLED=true
   STRIPE_MODE=test
   STRIPE_SECRET_KEY=sk_test_...
   STRIPE_PUBLISHABLE_KEY=pk_test_...
   STRIPE_WEBHOOK_SECRET=whsec_...
   ```
   Después: `docker compose up -d --force-recreate app worker scheduler`.
8. **Tarjetas de prueba [V].**
   - `4242 4242 4242 4242`: éxito;
   - `4000 0000 0000 9995`: fondos insuficientes;
   - `4000 0000 0000 0069`: vencida;
   - `4000 0000 0000 0002`: rechazo genérico;
   - `4000 0000 0000 0259`: disputa.

   Para los ciclos mensuales se usan los **Test Clocks** de Stripe.

### 3.2 Mercado Pago (credenciales de prueba)

1. **Cuenta.** Entrar a <https://www.mercadopago.com.mx/developers> con la cuenta de la Fundación (o una cuenta de desarrollo).
2. **Aplicación.** En "Tus integraciones", crear una aplicación:
   - producto: pagos en línea / Checkout API;
   - también se usarán **Suscripciones**.
3. **Cuentas de prueba [V].**
   - En la aplicación → "Cuentas de prueba", crear un **vendedor** y un **comprador** de México (máximo 15; no se pueden borrar).
   - Nota [V]: Checkout Bricks no admite usuarios de prueba. Para el pago único se usan las credenciales de prueba con tarjetas de prueba; para `/preapproval` se usan las cuentas de prueba [S].
4. **Credenciales.** En "Credenciales de prueba", copiar:
   - Access Token → `MERCADO_PAGO_ACCESS_TOKEN`;
   - Public Key → `MERCADO_PAGO_PUBLIC_KEY`.
5. **Notificaciones (webhooks).** En la aplicación → "Webhooks" → modo de prueba:
   - URL: `https://<dominio público>/webhooks/payments/mercado_pago`. Para desarrollo local hace falta un túnel HTTPS (por ejemplo, ngrok) hacia `http://localhost:8000`;
   - eventos: **Pagos**, **Órdenes (Order)**, **Planes y suscripciones** (preapproval y authorized payments) y **Contracargos**;
   - guardar y copiar la **clave secreta** → `MERCADO_PAGO_WEBHOOK_SECRET`.
6. **`.env` local.**
   ```
   MERCADO_PAGO_ENABLED=true
   MERCADO_PAGO_MODE=test
   MERCADO_PAGO_ACCESS_TOKEN=...
   MERCADO_PAGO_PUBLIC_KEY=...
   MERCADO_PAGO_WEBHOOK_SECRET=...
   ```
   Después: `docker compose up -d --force-recreate app worker scheduler`.
7. **Checklist.** Ejecutar el checklist de §16.3 del diseño (16 puntos) y anotar el resultado real de cada uno.

**Nunca pegues llaves en el chat ni en un archivo del repositorio.**

## 4. Qué registra cada proveedor

| Concepto | Stripe | Mercado Pago (opción A) |
|---|---|---|
| Payment único (`external_id`) | PaymentIntent (`pi_…`); antes de pagar solo existe la sesión de Checkout | Order [S] |
| Mensualidad (`external_id`) | Invoice (`in_…`) | authorized_payment [S] |
| Intento (`external_id`) | Charge (`ch_…`) [V] | payment [S] |
| Suscripción | Subscription (`sub_…`) | preapproval [V] |
| Reembolso | Refund (`re_…`), con metadatos `crm_refund_id` | Reembolso de la Order [S]; se asocia por importe (sin metadatos) |
| Disputa | Dispute (`dp_…`), ligada por PaymentIntent o cargo | Chargeback, ligada por el payment [S] |
| Referencia del CRM | `metadata.crm_payment_id` / `crm_subscription_id` | `external_reference` = `crm-payment-{id}` / `crm-subscription-{id}` |

## 5. Pruebas

- `tests/Feature/Payments/*`: dominio completo con `FakeGateway`, sin Internet.
- `StripeGatewayTest`: el SDK oficial con un cliente HTTP falso (`tests/Support/FakeStripeHttpClient.php`).
- `MercadoPagoGatewayTest`: `Http::fake()` con `preventStrayRequests()`.
- `tests/Concurrency/*`:
  - condiciones de carrera reales con dos procesos PHP contra PostgreSQL (`tests/Support/race.php`, solo pruebas; no es un comando de Artisan ni una ruta);
  - no usan `RefreshDatabase`, así que limpian sus tablas al terminar.
- `phpunit.xml` fuerza la pasarela simulada y deja vacías todas las llaves reales.
