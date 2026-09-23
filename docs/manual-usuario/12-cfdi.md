# CFDI (factura electrónica de donativos)

Menú **Donativos → CFDI**.

La Fundación debe emitir CFDI por **todos** los donativos que recibe, dentro de las 24 horas. No depende de que el donante lo pida.

> Mientras el sistema esté conectado al ambiente de prueba de Facturapi, los CFDI no tienen validez fiscal.

## Cómo queda cubierto cada donativo

En el detalle de un donativo confirmado sin CFDI aparece **Cobertura fiscal**:

- **CFDI individual:** el donante tiene datos fiscales. El CFDI se emite solo al confirmar el donativo.
- **Público en general (factura global):** el donante no tiene datos fiscales. La factura global todavía no está habilitada; está pendiente de definir con el contador.
- **Bloqueado:** el sistema indica el motivo. Por ejemplo:
  - donativo en especie;
  - depósito bancario;
  - tarjeta de prepago;
  - pago con reembolso o contracargo;
  - datos por corregir.

## Emitir a mano

Abre un donativo **Confirmado** y pulsa **Emitir CFDI** (Administrador y Contador). Si falta algo, el sistema lo dice y no emite.

El CFDI pasa por **En cola → Timbrando → Timbrado**. Cada donativo tiene un solo CFDI vigente. En los donativos mensuales, cada mes cobrado tiene su propio CFDI.

## Si algo falla

- **Error temporal:** el PAC no respondió. El sistema reintenta solo; también puedes pulsar **Reintentar timbrado**. Antes de reenviarlo, el sistema verifica en el PAC si ya se había timbrado, así que nunca se duplica.
- **Rechazado por datos:** corrige el dato que indica el mensaje y pulsa **Reintentar timbrado**. Si el CFDI ya no procede, pulsa **Descartar** (solo si nunca se timbró).

## Descargar

Los botones **XML** y **PDF** están disponibles para Administrador, Coordinador y Contador.

## Corregir un CFDI timbrado (Administrador y Contador)

- **Sustituir CFDI (motivo 01):** úsalo cuando el donativo sí existe pero el CFDI tiene errores (monto, descripción, datos del donante).
  1. Corrige primero los datos.
  2. El sistema emite un CFDI nuevo relacionado con el anterior.
  3. Después cancela el anterior.
- **Cancelar CFDI:**
  - **02:** el CFDI no debió emitirse o el RFC es totalmente erróneo. Después emite el correcto.
  - **03:** el donativo no se recibió.

Qué pasa después:
- Algunos CFDI requieren que el receptor acepte la cancelación (hasta 3 días hábiles). Mientras tanto aparecen como **Cancelación en proceso**.
- Si el receptor rechaza la cancelación, el CFDI sigue vigente. En una sustitución, ambos quedan vigentes y puedes volver a pulsar **Sustituir CFDI** para reintentar la cancelación.
- Cancelar un CFDI no cancela el donativo ni el pago.

## Qué ve cada rol

| | Administrador | Contador | Coordinador | Solo lectura |
|---|---|---|---|---|
| Ver CFDI y descargar XML/PDF | Sí | Sí | Sí | No |
| Emitir, reintentar, descartar, sustituir y cancelar | Sí | Sí | No | No |
| Detalle técnico (PAC, errores, llave) | Sí | Sí | No | No |
