# ADR-005 — Donativo, pago, recibo simple y CFDI son conceptos distintos

- **Estado:** Aceptado
- **Fecha:** 2026-09-22

## Contexto

Las fases posteriores agregarán pagos en línea, recibos simples y CFDI deducibles. Mezclarlos en una
sola entidad haría imposible, por ejemplo, tener un donativo en efectivo sin pago de pasarela, o un
recibo simple sin CFDI.

## Decisión

| Concepto | Qué es | Fase | Relación |
|---|---|---|---|
| `Donation` | El donativo reconocido por el CRM (dinero o especie) | 1 | Entidad central |
| `Payment` | Una transacción procesada por una pasarela | 2 | `payments.donation_id`: un pago exitoso **origina** un donativo. Un donativo manual no requiere pago |
| `DonationReceipt` | Recibo simple de agradecimiento (folio, PDF, envío) | Posterior | `Donation 1 → 0..1 DonationReceipt`. Toda donación **confirmada** podrá tenerlo, haya o no CFDI |
| `Cfdi` | Comprobante fiscal deducible con complemento de donatarias | 3 | Solo si el donante lo pidió (`tax_receipt_requested`) y procede fiscalmente |

- En la Fase 1 **no** se crean tablas de pagos, recibos ni CFDI. Cada una tendrá **su propia tabla
  apuntando a `donations`**, así que `donations` no necesita columnas nuevas para recibirlas.
- `donations.tax_receipt_requested` registra desde ahora si el donante pidió recibo deducible.
- Flujo de donativos manuales: `pending → confirmed` o `pending/confirmed → cancelled`. Un
  confirmado no cambia sus datos sustantivos; si hay error se cancela con motivo y se registra otro.
- Destino único: `campaign_id` **o** `program_id` **o** ninguno (fondo general)
  (`CHECK donations_single_destination`). El programa para reportes es el directo o el de la
  campaña (`Donation::effectiveProgram()`, scope `forProgram`). Una campaña con donativos no puede
  cambiar de programa (Action + trigger `campaigns_program_locked`).

## Consecuencias

- Cada fase agrega su tabla sin reescribir `donations`.
- El recibo simple nunca se confunde con un comprobante fiscal.
