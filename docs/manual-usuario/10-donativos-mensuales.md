# Donativos mensuales

Menú **Donativos → Donativos mensuales**.

Un donativo mensual es el compromiso del donante de donar cada mes con su tarjeta. **Cada mes es un pago distinto**, con su propio donativo cuando se cobra. Si un mes no se cobra, ese mes no genera donativo.

## Estados

| Estado | Qué significa |
|---|---|
| Por activar | El donante aún no termina el alta |
| Activa | Se cobra cada mes |
| Con cobro pendiente | El último cobro no pasó y el proveedor lo está reintentando |
| Pausada | No se cobra hasta que se reanude |
| Cancelada | Ya no habrá más cobros |
| No se activó | El alta no se completó |

## Si un cobro mensual falla

- El proveedor (Stripe o Mercado Pago) **reintenta el cobro por su cuenta**; el CRM no cobra.
- Desde el primer rechazo se abre una **incidencia** y se avisa en la campana, porque el donante no está presente para corregir su tarjeta.
- **Un cobro fallido nunca cancela el donativo mensual.** Solo se cancela si el proveedor lo cancela o si una persona autorizada lo decide.

## Pausar, reanudar y cancelar (Administrador y Coordinador)

1. Abre el donativo mensual y pulsa **Pausar**, **Reanudar** o **Cancelar**.
2. Escribe el **motivo** (obligatorio) y confirma.

El cambio solo se refleja cuando el proveedor lo confirma. Queda registrado quién lo hizo, cuándo y por qué.

- Cancelar no se puede deshacer, y los pagos y donativos anteriores no cambian.
- Si el proveedor no responde, verás un aviso y el donativo mensual no cambia.

En **Mensualidades** ves cada cobro del donativo mensual con su periodo y su estado.
