# Bitácora

**Para qué sirve:** saber **quién** cambió **qué** y **cuándo**.

**Quién:** solo el **Administrador**. Es de solo consulta: nadie puede modificarla ni borrarla.

**Ruta:** menú **Administración → Bitácora de cambios**.

## Lista

| Columna | Significado |
|---|---|
| Fecha y hora | Cuándo ocurrió |
| Usuario | Quién lo hizo ("Automático" si no fue una persona) |
| Procedencia | Usuario, Notificación del proveedor, Proceso automático, Sincronización con el proveedor o Consola del servidor. Vacía en registros anteriores a los pagos en línea |
| Evento | Creación, Modificación, Eliminación, Confirmación, Cancelación, Archivado, Reactivación, Cambio de etiquetas, Desactivación de usuario… |
| Tipo de registro | Donante, Donativo, Campaña, Usuario… |
| Número | Número interno del registro |
| Campos | Qué campos cambiaron |

Filtros: evento, tipo de registro, usuario y rango de fechas.

## Detalle

Botón **Ver**. Muestra cada cambio como "Campo: antes → después".

Por privacidad, en datos personales y fiscales (nombre, correo, teléfono, fecha de nacimiento, RFC,
notas…) solo se registra **que cambiaron**, sin guardar el valor: aparece "(cambió; valor no
registrado por privacidad)". Las contraseñas nunca se registran.
