# Pagos en línea

Menú **Donativos → Pagos en línea**.

Aquí aparecen los donativos que las personas hacen con tarjeta en línea, por **Stripe** o por **Mercado Pago**. Nadie los captura a mano: el sistema los registra con lo que informa el proveedor de pago.

## Qué ves en la lista

- **Fecha, donante e importe.**
- **Proveedor:** Stripe o Mercado Pago.
- **Tipo:** *Único* o *Mensualidad* (un cobro de un donativo mensual).
- **Estado:**

  | Estado | Qué significa |
  |---|---|
  | Pendiente | El donante todavía no termina de pagar, o el proveedor volverá a intentar el cobro |
  | En proceso | El banco está confirmando el pago |
  | Exitoso | El dinero se cobró. **Solo en este caso se crea el donativo** |
  | Fallido | No se pudo cobrar |
  | Cancelado | El pago no se completó |

- **Reembolso:** *Sin reembolso*, *Parcialmente reembolsado* o *Totalmente reembolsado*. Un reembolso **no** borra el pago ni cancela el donativo.

Puedes filtrar por proveedor, estado, tipo, fechas, donante, campaña, programa, importe, reembolso y, si tu rol lo permite, por motivo de rechazo o por pagos con incidencia.

## El detalle de un pago

- **Donativo generado:** el enlace al donativo que se creó cuando el pago quedó exitoso. Un pago crea como máximo un donativo.
- **Motivo del último rechazo** (Administrador, Coordinador y Contador): por ejemplo "Fondos insuficientes" o "Tarjeta vencida".
- **Información técnica** (solo Administrador y Contador): identificadores del proveedor y el historial de **intentos de cobro**. De la tarjeta solo se ve la marca y los últimos 4 dígitos: el CRM nunca recibe ni guarda el número completo ni el código de seguridad.
- **Reembolsos** y **Disputas** (Administrador y Contador).

## Solicitar un reembolso (Administrador y Contador)

1. Abre el pago y pulsa **Solicitar reembolso**.
2. Escribe el importe. Puede ser menor al pago; se pueden hacer varios parciales hasta el total.
3. Elige el **motivo**: Solicitud del donante, Cobro duplicado, Importe incorrecto, Error administrativo u Otro. Con *Otro* debes escribir un comentario.
4. Pulsa **Enviar reembolso**.

Qué pasa después:
- Si el proveedor no responde en ese momento, el reembolso queda **En proceso** y el sistema lo reintenta solo, **sin duplicarlo**.
- No se puede reembolsar más de lo cobrado ni un pago con una disputa abierta.
- Queda registrado quién lo pidió, cuándo, el importe, el motivo y el resultado.
- El donativo no se cancela. El tratamiento fiscal de los reembolsos está pendiente de definir con el contador.

Los reembolsos hechos directamente en el panel del proveedor también aparecen aquí, con el motivo "Hecho en el panel del proveedor".

## Disputas y contracargos (Administrador y Contador)

Menú **Contabilidad → Contracargos**.

Un contracargo ocurre cuando el titular de la tarjeta desconoce el cobro ante su banco. Cuando llega uno:
- se abre una **incidencia crítica** y se avisa en la campana;
- la disputa se atiende en el panel del proveedor, antes de la fecha **Responder antes de**;
- el CRM muestra el seguimiento: Abierta, En revisión del proveedor, Ganada, Perdida o Cerrada.

La disputa no cambia el pago ni el donativo.

## Exportar

El botón **Exportar** está disponible para Administrador, Coordinador y Contador. Administrador y Contador reciben además columnas técnicas (identificador en el proveedor, número de intentos, motivo del último rechazo). La exportación nunca incluye datos de tarjeta.
