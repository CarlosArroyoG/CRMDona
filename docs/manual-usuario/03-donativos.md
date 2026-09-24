# Donativos

**Para qué sirve:** registrar los donativos recibidos a mano (efectivo, transferencia, cheque,
depósito o especie), confirmarlos cuando se verifica que llegaron y cancelarlos si hubo un error.

**Quién la usa:** todos consultan. Registrar y editar pendientes: Administrador, Coordinador y
Contador. **Confirmar y cancelar: Administrador y Contador.**

**Ruta:** menú **Donativos → Donativos**.

## Cómo funciona

```
Registrar  →  Por confirmar  →  Confirmado
                     │               │
                     └── Cancelado ◄─┘  (con motivo)
```

- Todo donativo nace **Por confirmar**, también el efectivo.
- El Contador o el Administrador lo **confirma** al verificar que se recibió.
- Un donativo **confirmado ya no se puede editar**. Si tiene un error: **Cancelar** indicando el
  motivo y registrar uno nuevo correcto.
- **Ningún donativo se borra.** Los cancelados quedan en el historial.

## Lista de donativos

| Columna | Significado |
|---|---|
| Folio | Número interno del donativo |
| Fecha | Fecha en que se recibió |
| Donante | Quién donó |
| Tipo | Dinero o especie |
| Forma de pago | Efectivo, transferencia, cheque o depósito |
| Importe o valor | En especie, el valor asignado |
| Destino | Campaña, programa o "Fondo general" |
| Estado | Por confirmar, Confirmado o Cancelado |

**Buscar:** por nombre del donante (sin importar acentos) o por referencia.

**Filtros:** estado, tipo, forma de pago, donante, campaña, programa (incluye los donativos de sus
campañas), rango de fechas de recepción, importe mínimo y máximo, y si solicitó recibo deducible.

## Registrar un donativo

Botón **Crear donativo**.

Primero, en **¿Cómo se recibe?** (Administrador y Coordinador):

- **Ya se recibió**: efectivo, transferencia, cheque, depósito o especie. Es el registro de siempre (tabla siguiente).
- **Cobrar con tarjeta en línea**: no registra el donativo; prepara una solicitud de pago para que el donante pague
  con su tarjeta en la página segura del proveedor. Ver [Cobrar con tarjeta](17-cobro-con-tarjeta.md).

| Campo | Significado |
|---|---|
| Donante | Escribe parte del nombre. Los archivados no aparecen |
| Tipo de donativo | Dinero o Especie |
| Forma de pago | Solo en dinero: efectivo, transferencia bancaria, cheque o depósito bancario |
| Importe / Valor asignado (MXN) | Ejemplo "1500" o "1,500.50". Máximo dos decimales |
| Fecha de recepción | No puede ser futura |
| Descripción de lo donado | Solo en especie: qué se recibió, cantidad y estado |
| Referencia | Folio de transferencia, número de cheque o de recibo físico |
| Solicitó recibo deducible (CFDI) | Solo informativo: la Fundación emite CFDI por todo donativo confirmado (ver [CFDI](12-cfdi.md)) |
| ¿A qué se destina? | **Campaña**, **Programa (sin campaña)** o **Fondo general**. Solo uno |
| Notas internas | Información interna |

Si eliges una campaña, su programa se toma automáticamente.

## Ficha del donativo

Muestra todos los datos y la **trazabilidad**: quién lo registró, quién lo confirmó o canceló y
cuándo. Botones: **Editar** (solo "Por confirmar"), **Confirmar**, **Cancelar**.

**Cancelar** pide el motivo, por ejemplo: "Importe capturado con error; se registró de nuevo con el
folio 128".

## Donativos en línea

Los donativos con origen **Pago en línea** los crea el sistema cuando un pago con tarjeta queda **Exitoso** (ver [Pagos en línea](09-pagos-en-linea.md)).

- Nacen ya **Confirmados**. En "Registrado por" y "Confirmado por" aparece que fue automático: ninguna persona los capturó.
- No se editan. El enlace **Pago en línea** lleva al pago que los originó.
- Si hubiera un error, se cancelan con motivo como cualquier otro donativo.
- Un reembolso del pago **no** cancela el donativo.

Usa el filtro **Origen** para ver solo los manuales o solo los de pagos en línea. La forma de pago (efectivo, transferencia, cheque, depósito) aplica solo a los donativos manuales.

## Errores frecuentes

| Mensaje | Qué hacer |
|---|---|
| "El campo importe debe ser un importe mayor a cero, con máximo dos decimales…" | Revisa el número: sin letras, máximo dos decimales |
| "El donante no existe o está archivado…" | Reactiva al donante desde su ficha |
| "Elige una campaña o un programa, no ambos…" | Deja un solo destino |
| "Solo se pueden modificar donativos 'Por confirmar'…" | Cancélalo con motivo y registra uno nuevo |
| "El campo motivo de cancelación es obligatorio." | Explica el motivo (mínimo 5 caracteres) |
