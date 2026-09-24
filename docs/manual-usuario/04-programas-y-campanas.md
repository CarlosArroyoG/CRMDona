# Programas y campañas

- **Programa:** destino o causa permanente. Ejemplos: Becas, Formación, Alimentación,
  Infraestructura.
- **Campaña:** esfuerzo concreto de procuración, normalmente con fechas. Ejemplos: Navidad 2026,
  Regreso a clases 2027. Puede pertenecer a un programa.

**Quién la usa:** todos consultan y exportan. Crear y editar: Administrador y Coordinador.
Eliminar: solo Administrador.

**Ruta:** menú **Recaudación → Programas** y **Recaudación → Campañas**.

## Programas

| Columna / campo | Significado |
|---|---|
| Nombre | No puede repetirse |
| Estado | Activo o Archivado. Los archivados no se ofrecen al registrar donativos |
| Campañas | Cuántas campañas tiene |
| Identificador para enlaces | Opcional; si se deja vacío se genera con el nombre (ejemplo: "alimentacion") |
| Descripción | Texto libre |

Filtro: **Estado**. Búsqueda por nombre sin importar acentos.

## Campañas

| Columna / campo | Significado |
|---|---|
| Nombre | Nombre de la campaña |
| Programa | Opcional. **Si ya tiene donativos no puede cambiar de programa**, para no alterar los reportes |
| Estado | Borrador, Activa, Finalizada o Archivada |
| Fecha de inicio / fin | Opcionales; el fin no puede ser antes del inicio |
| Meta (MXN) | Opcional. Ejemplo: 150000 |
| Identificador para enlaces | Se usará en la página pública futura |

Filtros: **Estado**, **Programa** y **Vigencia** (campañas activas entre dos fechas).

## Eliminar

Solo si no tienen donativos (y un programa, además, sin campañas). Lo habitual es **archivar**
cambiando el estado.

## Errores frecuentes

| Mensaje | Qué hacer |
|---|---|
| "Ya existe un programa con ese nombre." | Usa otro nombre o edita el existente |
| "El campo fecha de fin debe ser una fecha posterior o igual a fecha de inicio." | Corrige las fechas |
| "La campaña ya tiene donativos: no puede cambiar de programa…" | Crea una campaña nueva para el otro programa |
| "Esta campaña tiene donativos y no puede eliminarse. Puedes archivarla." | Cambia su estado a Archivada |
