# Fase 02 — Pagos en línea (Stripe y Mercado Pago)

- **Estado:** implementada y validada con `FakeGateway` y adaptadores probados sin Internet (2026-09-23).
- **Falta:** el sandbox real de Stripe y de Mercado Pago (puntos **[S]**) y la aprobación del responsable.
- **Fuente de verdad del diseño:** `docs/tecnico/fase-2-diseno-pagos.md` (v3; §25 con ajustes y diferencias). ADR-011.

## Qué se construyó

| Capa | Contenido |
|---|---|
| Migraciones (11, reversibles) | `receives_payment_alerts`; límites en línea; `subscriptions`; `payments`; `payment_attempts`; `refunds` con trigger; `payment_disputes`; `webhook_events`; `payment_incidents` y notas; `audit_logs.source`; `donations` (`origin`, `payment_id`, `manual_payment_method`, CHECKs) |
| Enums | Proveedor, estados de pago, intento, suscripción, reembolso y disputa; `RefundState` calculado; `RefundReason`; `FailureCategory`; tipo, severidad y estado de incidencia; origen del donativo; procedencia de la bitácora |
| Modelos | `Payment`, `PaymentAttempt`, `Subscription`, `Refund`, `PaymentDispute`, `WebhookEvent`, `PaymentIncident`, `PaymentIncidentNote` (auditados con lista cerrada) |
| Contratos | `PaymentGateway` y las capacidades `ProcessesOneTimePayments`, `ProcessesRecurringPayments`, `PausesSubscriptions`, `ProcessesRefunds`; snapshots normalizados; `GatewayRegistry` |
| Actions | Iniciar único o mensual; sincronizar pago, suscripción, reembolso y disputa; crear el donativo; solicitar y enviar reembolsos; pausar, reanudar y cancelar; incidencias; webhooks |
| Jobs | `ProcessWebhookEvent`, `SendPaymentIncidentAlert`, `ReconcilePayments` (cada 15 minutos) |
| Pasarelas | `FakeGateway` (determinista, cruza procesos); Stripe (`stripe/stripe-php` v21.3.2); Mercado Pago (cliente HTTP de Laravel) |
| Filament | Pagos en línea (intentos, reembolsos, disputas), Donativos mensuales, Incidencias (con notas), Reembolsos, Disputas, Bandeja de webhooks, Pasarelas de pago; preferencia de alertas; límites en Organización; origen en Donativos; procedencia en Bitácora |
| Seguridad | Sanitización central (`SensitiveData`), redacción en todos los canales de log, lista permitida por proveedor para los webhooks, verificación de firmas, modo prueba/producción validado contra la llave |

## Decisiones tomadas en la implementación (técnicas y reversibles)

1. **Pago único fallido.** Abre incidencia solo si el último rechazo no es corregible por el donante (§25.2 #9).
2. **Reembolsos hechos en el panel del proveedor.** Se registran con el motivo interno `provider_initiated`.
3. **Proveedor caído.** Una incidencia por proveedor y por hora.
4. **Concurrencia.** Se prueba con un script de pruebas (`tests/Support/race.php`) en lugar de un comando de Artisan; no está en la imagen de producción.
5. **Alerta idempotente.** No se avisa dos veces a la misma persona por la misma incidencia.
6. **Pasarela deshabilitada.** Su webhook responde 404 y en acciones de personas se muestra como error de validación.

## Diferencias entre documentación y código (§25.2 del diseño)

1. Stripe `ui_mode: embedded_page` (no `embedded`).
2. Estado de disputa `prevented`.
3. La Invoice de Stripe expone los cobros en `payments` (InvoicePayment) y la suscripción en `parent`.
4. En Stripe el PaymentIntent aparece hasta que el donante paga.
5. Stripe no tiene fecha de actualización en sus objetos.
6. En Mercado Pago, una Order rechazada no se reintenta [S].
7. La pausa de Stripe usa `void` (aprobada provisionalmente; [S] en Stripe Test).

## Pruebas

| Área | Pruebas |
|---|---|
| Dominio de pagos con FakeGateway y adaptadores (`tests/Feature/Payments`) | 184 (596 aserciones) |
| Pantallas de pagos por rol (`PaymentScreensTest`) | 14 (101 aserciones) |
| Concurrencia real entre procesos (`tests/Concurrency`) | 8 (37 aserciones) |
| **Suite completa** | **450 pruebas, 1704 aserciones** (antes de la fase: 223 / 784) |

**Idempotencia y concurrencia (pedidas explícitamente):**
- doble clic, llave de idempotencia y doble clic simultáneo;
- pago concurrente y donativo concurrente;
- webhook duplicado y duplicado simultáneo;
- fuera de orden y estado viejo;
- Job reintentado y timeout después de que el proveedor procesó;
- reembolso duplicado, reembolso concurrente (Action y trigger por separado) y suma de reembolsos;
- incidencia duplicada e incidencia nueva legítima; disputa duplicada.

**Además:**
- permisos por rol (matriz, pantallas y acciones);
- sanitización de payload, logs y excepciones;
- migración de donativos de la Fase 1, y reversión y reaplicación de las 11 migraciones;
- negativa a revertir si ya hay donativos en línea.

## Validación final (2026-09-23)

| Verificación | Resultado |
|---|---|
| Pint | 100 % (`--test` sin cambios) |
| Larastan nivel 8 | Sin errores (incluye `tests/`) |
| Pest | 450 / 450 |
| `migrate:fresh --seed` | Correcto en `crm_validation` (con pagos de demostración de la pasarela simulada) |
| Reversibilidad | Rollback de 11 pasos y reaplicación (en `crm_validation` y en pruebas) |
| Base local `crm` | Respaldo `pg_dump` previo; `migrate` aditivo; el administrador se conservó |
| Docker Compose real | app sano, worker procesa `ReconcilePayments` desde Redis, el scheduler la programa cada 15 minutos |
| Webhooks por HTTP real | 404 con las pasarelas deshabilitadas (correcto) |
| `docker build --no-cache --target prod` | Correcto; incluye `stripe/stripe-php` v21.3.2; `tests/` excluido |
| Composer | Solo se agregó `stripe/stripe-php`; `composer validate` y `check-platform-reqs` correctos |
| CI remoto | No observado (no hay push) |

## Pendientes y riesgos

- **[S] Sandbox de Stripe y de Mercado Pago:** `docs/pendientes.md` #14 y #15; instrucciones en `integraciones-pagos.md` §3. Mercado Pago tiene más supuestos [S] (Orders, authorized_payment, contracargos, límites).
- **Decidido (2026-09-23):** límites de negocio en `null` hasta decisión posterior (#16); pausa `void` en Stripe aprobada provisionalmente, con validación [S] en Stripe Test (#17).
- **Cierre administrativo (2026-09-23):** bloque implementable cerrado; sandbox de Stripe y Mercado Pago (#14, #15) sigue abierto.
- **Fiscal:** tratamiento de reembolsos y contracargos (#18). No se automatiza nada fiscal.
- **Fuera de alcance:** página pública (#19) y correo de alertas (#20).

## Cómo probarlo manualmente (sin credenciales)

1. Cargar datos ficticios en la base desechable (nunca en `crm`):
   ```
   docker compose exec -e DB_DATABASE=crm_validation app php artisan migrate:fresh --seed
   docker compose exec -e DB_DATABASE=crm_validation app php artisan app:create-admin
   ```
2. En `.env` local cambiar temporalmente `DB_DATABASE=crm_validation`, y reiniciar con:
   ```
   docker compose up -d --force-recreate app worker scheduler
   ```
3. Entrar al panel con el administrador de prueba y revisar **Pagos en línea**, **Donativos mensuales**, **Incidencias** y **Pasarelas de pago**. Crear usuarios con cada rol para comparar lo que ve cada uno.
4. Al terminar, regresar `DB_DATABASE=crm` y reiniciar igual.
