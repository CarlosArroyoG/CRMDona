# ADR-009 — Búsqueda sin acentos (`unaccent`)

- **Estado:** Aceptado
- **Fecha:** 2026-09-22

## Decisión

- Extensión **`unaccent`** de PostgreSQL (incluida en las imágenes oficiales; no es un paquete de
  Composer). Desde PostgreSQL 13 es *trusted*: la crea el dueño de la base sin ser superusuario.
- La migración `2026_09_23_000001_enable_unaccent_extension`:
  - verifica que la extensión esté disponible y, si no, **falla con un mensaje en español** que
    remite a la guía de Coolify;
  - crea la extensión y la función `f_unaccent(text)` marcada `IMMUTABLE` (necesario porque
    `unaccent()` no lo es);
  - es reversible (`down` borra función y extensión).
- La aplicación busca con `f_unaccent(columna) ILIKE f_unaccent(?)` mediante
  `App\Support\Search::unaccent()`, que solo acepta columnas de una lista cerrada.
- Aplica en: nombre o razón social y correo de donantes, nombres de programas, campañas y usuarios,
  y donante en la lista de donativos.

## Rendimiento

Sin índices por ahora: con miles de registros un recorrido completo tarda milisegundos. Si el
volumen crece, la opción es un índice GIN con `pg_trgm` sobre `f_unaccent(display_name)` (posible
porque `f_unaccent` es inmutable). No se implementa hasta que haga falta.
