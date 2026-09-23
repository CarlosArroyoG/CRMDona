# CFDI (factura electrónica de donativos)

Menú **Donativos → CFDI**.

> Mientras no se elija el proveedor de facturación (PAC), el sistema solo funciona en modo de prueba: los comprobantes dicen "sin validez fiscal".

## Emitir

1. Abre un donativo **Confirmado** y pulsa **Emitir CFDI** (Administrador y Contador).
2. Si falta algo, el sistema lo dice y no emite. Por ejemplo:
   - datos fiscales del donante;
   - número de oficio de autorización (en Organización);
   - un uso de CFDI incorrecto.
3. El CFDI pasa por **En cola → Timbrando → Timbrado**.

Cada donativo tiene como máximo un CFDI vigente. En los donativos mensuales, cada mes cobrado es un donativo distinto con su propio CFDI.

Por ahora **no** se emiten CFDI de:
- donativos en especie;
- donativos en línea con tarjeta;
- depósitos bancarios;
- donantes sin datos fiscales.

Estos casos están pendientes de definir con el contador.

## Si algo falla

- **Error temporal:** el PAC no respondió. El sistema reintenta solo; también puedes pulsar **Reintentar timbrado**. Nunca se duplica.
- **Rechazado por datos:** corrige el dato que indica el mensaje y pulsa **Reintentar timbrado**.

## Descargar

Los botones **XML** y **PDF** están disponibles para Administrador, Coordinador y Contador.

## Cancelar (Administrador y Contador)

1. Pulsa **Cancelar CFDI**.
2. Elige el motivo del SAT: 02 (errores sin relación) o 03 (la operación no se llevó a cabo).
3. Escribe la razón.

Qué pasa después:
- Algunos CFDI requieren que el receptor acepte la cancelación (hasta 3 días hábiles). Mientras tanto aparecen como **Cancelación en proceso**.
- Si el receptor la rechaza, el CFDI sigue vigente.
- Cancelar el CFDI no cancela el donativo ni el pago.
- Una vez cancelado, se puede emitir otro CFDI para el mismo donativo.
