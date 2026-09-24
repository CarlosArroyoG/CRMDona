# Tablero y reportes

## Tablero (página de inicio)

| Indicador | Qué cuenta |
|---|---|
| Recaudado en el mes | Donativos **confirmados en dinero** recibidos este mes. No incluye especie, y no se restan los reembolsos |
| Comparación | Diferencia en % contra el mes anterior completo |
| Donantes nuevos | Personas o empresas cuyo **primer** donativo confirmado fue este mes |
| Donativos mensuales activos | Cuántos están activos hoy (también se muestran los que tienen un cobro pendiente y los que están en pausa) |
| Tasa de fallos de pagos | De los pagos en línea de este mes que ya terminaron, qué porcentaje falló. Un pago que se recuperó cuenta como exitoso |
| Reembolsado en el mes | Reembolsos que el proveedor completó este mes |
| Cumpleaños próximos | Hoy y los próximos 6 días, indicando si recibirán felicitación |

## Reporte de pagos

**Pagos en línea**. Además de los filtros de siempre:

- **Situación:**
  - **Recuperado:** el cobro se logró después de un rechazo;
  - **Cobro mensual fallido:** una mensualidad que no se pudo cobrar.
- **Reembolso:** parcial o total. Un pago reembolsado **no** es un pago cancelado.

Al pie de la tabla aparecen los **totales del filtro**: número de pagos, importe, cobrado y reembolsado.

**Exportar** descarga lo mismo que ves filtrado (Administrador, Coordinador y Contador).

## Control contable

**Contabilidad → Control contable** (Administrador, Coordinador y Contador) sustituye al antiguo Reporte CFDI. Tiene una fila por donativo confirmado, con:

- recibo, donante, fecha, importe y destino;
- si el donante solicitó CFDI;
- si el aviso a Contabilidad salió;
- si Contabilidad ya lo procesó;
- el CFDI externo adjunto, con su UUID, fecha de emisión y fecha en que se adjuntó.

Se puede filtrar y exportar a Excel o CSV. La exportación no incluye datos fiscales del donante.

El detalle está en [CFDI y Contabilidad](12-cfdi.md).
