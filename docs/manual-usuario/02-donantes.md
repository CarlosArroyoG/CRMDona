# Donantes

**Para qué sirve:** registrar a las personas y organizaciones que donan, sus datos de contacto, sus
consentimientos y, si piden recibo deducible, sus datos fiscales.

**Quién la usa:** todos los roles la consultan. Crear y editar: Administrador y Coordinador. Datos
fiscales: Administrador, Coordinador y Contador. Eliminar: solo Administrador.

**Ruta:** menú **Donativos → Donantes**.

## Lista de donantes

| Columna | Significado |
|---|---|
| Nombre o razón social | Nombre completo (persona física) o razón social (persona moral) |
| Tipo | Persona física o persona moral |
| Correo | Correo de contacto |
| RFC | Solo lo ven quienes tienen acceso a datos fiscales |
| Etiquetas | Clasificación libre (por ejemplo "Padrino", "Empresa") |
| Donativos | Cuántos donativos tiene registrados |

**Buscar:** escribe en el buscador parte del nombre o del correo, o el **RFC completo** (el RFC se
guarda cifrado, así que no se encuentra por un fragmento). No importan acentos ni
mayúsculas: "jose pena" encuentra a "José Peña".

**Filtros:**

| Filtro | Uso |
|---|---|
| Tipo de persona | Solo personas físicas o solo morales |
| Etiquetas | Donantes con una o varias etiquetas |
| Registrado desde | A mano, desde la página pública o por carga CSV |
| Archivados | Por defecto se muestran **solo activos**; elige "Solo archivados" o "Todos" |
| Datos fiscales | Con o sin datos fiscales |

## Registrar un donante

Botón **Crear donante**.

| Campo | Significado |
|---|---|
| Tipo de persona | Persona física o moral. Cambia los campos que se muestran |
| Nombre(s), apellido paterno, apellido materno | Persona física (nombre y apellido paterno obligatorios) |
| Fecha de nacimiento | Opcional. Se usará para felicitar en su cumpleaños |
| Razón social | Persona moral (obligatoria) |
| Persona de contacto | Persona moral, opcional |
| Correo electrónico, Teléfono | Opcionales |
| Etiquetas | Elige existentes; Administrador y Coordinador pueden crear nuevas |
| Notas | Información interna |
| Aceptó el aviso de privacidad | **Solo si existe evidencia** de que lo aceptó. Guarda la versión vigente y la fecha |
| Acepta recibir comunicaciones | Autoriza correos informativos y de campañas. Es independiente del aviso |
| Tiene datos fiscales | Actívalo solo si el donante pidió recibo deducible |
| RFC, nombre o razón social fiscal, régimen fiscal, código postal fiscal | Tal como aparecen en su constancia de situación fiscal |
| Uso de CFDI | Opcional; se confirmará con el contador |

**Aviso de duplicado:** si ya existe un donante con el mismo correo o RFC, aparece un aviso junto al
campo. **No impide guardar**: una familia puede compartir correo. Revisa que no sea la misma persona.

## Cargar donantes desde un archivo CSV

Para registrar muchos donantes a la vez (por ejemplo, una lista de un evento). Administrador y
Coordinador. Botón **Cargar CSV** en la lista de donantes.

1. En la lista de donantes pulsa **Descargar plantilla CSV**. Trae las columnas correctas y tres
   filas de ejemplo: una persona física, una persona moral y una fila mínima. **Borra las filas de
   ejemplo** y escribe tus donantes debajo de los encabezados, sin cambiarlos. Al guardar en Excel,
   elige "CSV UTF-8" si está disponible; el sistema también entiende el CSV normal de Excel.
2. Sube el archivo. El sistema reconoce las columnas y te deja corregir cuál es cuál.
3. Opcional: escribe una **etiqueta para toda la carga** (por ejemplo, "Carga septiembre 2026").
4. Marca la **confirmación de consentimiento** solo si la Fundación tiene evidencia de que los
   donantes marcados "sí" en la columna `acepta_comunicaciones` autorizaron recibir correos. Sin la
   confirmación, todos quedan sin aceptar comunicaciones.
5. Pulsa **Importar**. El archivo se procesa en segundo plano; la campana avisa al terminar.

| Columna | Qué escribir |
|---|---|
| `tipo_persona` | "física" o "moral" (vacío = física) |
| `nombre`, `apellido_paterno`, `apellido_materno` | Persona física (nombre y apellido paterno obligatorios) |
| `razon_social`, `persona_contacto` | Persona moral (razón social obligatoria) |
| `correo`, `telefono` | Opcionales |
| `fecha_nacimiento` | `15/03/1980` (año con cuatro dígitos) |
| `etiquetas` | Separadas por punto y coma: `Padrino; Evento 2026` |
| `notas` | Opcional |
| `acepta_comunicaciones` | "sí" o "no" |

Qué hace el sistema por su cuenta:

- Solo **agrega** donantes nuevos. Si el correo ya está registrado, esa fila **no se importa** y el
  donante existente no cambia.
- Las filas con error no detienen la carga. La notificación final trae un archivo con cada fila
  rechazada y el motivo; corrígelas y vuelve a cargar solo esas. El archivo se borra a los 7 días.
- No se cargan el aviso de privacidad ni los datos fiscales: se capturan en la ficha de cada donante.
- Máximo 5 000 filas por archivo.

## Ficha del donante

Botón **Ver** en la lista. Muestra datos, consentimientos, datos fiscales (según tu rol) y el
historial de **donativos**. Botones:

| Botón | Qué hace | Quién |
|---|---|---|
| Editar | Cambia los datos | Administrador, Coordinador |
| Datos fiscales | Edita solo los datos fiscales | Contador |
| Archivar / Reactivar | Oculta o vuelve a mostrar al donante. No borra nada | Administrador, Coordinador |
| Eliminar | Borra al donante **solo si no tiene donativos** | Administrador |

Un donante **archivado** no aparece en los listados habituales y no se le pueden registrar
donativos hasta reactivarlo.

## Ejemplos

- **Persona física:** Tipo "Persona física", Nombre "María", Apellido paterno "Núñez", correo, y
  "Acepta recibir comunicaciones" si lo autorizó.
- **Empresa que pide deducible:** Tipo "Persona moral", Razón social, activa "Tiene datos fiscales" y
  captura RFC de 12 caracteres, nombre fiscal, régimen y código postal fiscal.

## Errores frecuentes

| Mensaje | Qué hacer |
|---|---|
| "El campo nombre es obligatorio." | Persona física: captura nombre y apellido paterno |
| "El campo razón social es obligatorio." | Persona moral: captura la razón social |
| "No hay un aviso de privacidad configurado…" | El Administrador debe registrar URL y versión en Organización |
| "El campo RFC no tiene la estructura de un RFC de Persona física (13 caracteres)." | Revisa el RFC: 13 caracteres persona física, 12 persona moral |
| "El código postal fiscal debe tener 5 dígitos." | Captura los 5 dígitos |
| "Este donante tiene donativos registrados y no puede eliminarse. Puedes archivarlo." | Usa **Archivar** |
| "Ya existe un donante con el correo …; no se modificó." (carga CSV) | Es un donante ya registrado; si hay que cambiar algo, edítalo en su ficha |
| "Fecha de nacimiento inválida: usa el formato dd/mm/aaaa." (carga CSV) | Escribe la fecha con año de cuatro dígitos, por ejemplo `15/03/1980` |
