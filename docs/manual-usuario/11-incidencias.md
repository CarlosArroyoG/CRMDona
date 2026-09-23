# Incidencias de pagos

Menú **Pagos en línea → Incidencias**. El número rojo junto al menú indica cuántas siguen sin resolver.

Una incidencia es un problema de pagos que **alguien debe revisar**. El CRM es la fuente de verdad: la campana y el correo solo avisan.

## Quién las ve

| Rol | Qué incidencias |
|---|---|
| Administrador y Contador | Todas |
| Coordinador de procuración de fondos | Solo las operativas: pagos fallidos, cobros mensuales rechazados, donativos mensuales cancelados por el proveedor y contracargos (sin detalle técnico) |
| Solo lectura | Ninguna |

## Cuándo se abre una

- Un **pago único** termina fallido por un motivo que hay que revisar. Los rechazos que el donante está corrigiendo (escribió mal la tarjeta, usa otra) **no** abren incidencia.
- Un **cobro mensual** es rechazado, desde el primer rechazo.
- Una **mensualidad** no se pudo cobrar después de los reintentos del proveedor.
- El **proveedor cancela** un donativo mensual.
- Llega un **contracargo o disputa**.
- Casos técnicos (solo Administrador y Contador): un reembolso falló, un pago exitoso no generó su donativo, un estado inconsistente, el proveedor no responde o una notificación no se pudo procesar.

El mismo problema no abre dos incidencias; un problema nuevo sobre el mismo pago sí abre otra.

## Cómo se atienden

1. **Tomar para revisión:** la incidencia pasa a *En revisión* a tu nombre.
2. **Agregar nota:** deja constancia de lo que hiciste (llamada al donante, revisión con el banco). Las notas no se pueden editar ni borrar.
3. **Marcar como resuelta:** escribe la resolución. La incidencia queda *Resuelta* y conserva quién y cuándo la resolvió.

**Leer la notificación de la campana no resuelve la incidencia.**

## Avisos

- **Administradores:** siempre reciben el aviso en la campana.
- **Coordinadores y Contadores:** solo si el Administrador activó "Recibe alertas de pagos" en su usuario (el Coordinador, solo de las operativas).
- **Solo lectura:** nunca.

El aviso dice qué pasó, el donante, el importe y qué hacer, sin datos técnicos. El aviso por correo se activará en la fase de comunicaciones.
