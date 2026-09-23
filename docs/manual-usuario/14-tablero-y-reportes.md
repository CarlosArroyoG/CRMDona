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

## Reporte CFDI

**Reportes → Reporte CFDI** (Administrador, Coordinador y Contador). Hay una fila por donativo confirmado, con:

- el CFDI vigente (estado, UUID y fecha de timbrado);
- la **ruta fiscal**:
  - **CFDI individual:** ya emitido, o listo para emitirse;
  - **Público en general:** el donante no tiene datos fiscales; la factura global todavía no está disponible;
  - **Bloqueado:** muestra el motivo (por ejemplo, un depósito bancario o un donativo en especie);
- cuántos CFDI se cancelaron.

Puedes filtrar por fechas, estado del CFDI, público en general, cancelaciones y donante, y exportar a Excel o CSV.

El Coordinador ve que un CFDI fue "Rechazado por datos", pero el mensaje técnico del PAC solo lo ven el Administrador y el Contador.
