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

**Buscar:** escribe en el buscador parte del nombre, del correo o del RFC. No importan acentos ni
mayúsculas: "jose pena" encuentra a "José Peña".

**Filtros:**

| Filtro | Uso |
|---|---|
| Tipo de persona | Solo personas físicas o solo morales |
| Etiquetas | Donantes con una o varias etiquetas |
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
