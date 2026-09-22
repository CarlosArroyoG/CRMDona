# ADR-007 — Exportaciones nativas de Filament

- **Estado:** Aceptado
- **Fecha:** 2026-09-22

## Decisión

- `ExportAction` de Filament con una clase `Exporter` por módulo (`app/Filament/Exports`). **Sin
  dependencias nuevas**: `openspout` (XLSX) y `league/csv` (CSV) ya vienen con `filament/actions`.
- Formatos **CSV (UTF-8 con BOM, para que Excel muestre acentos) y XLSX**. Encabezados y valores de
  catálogos en español; fechas `AAAA-MM-DD`.
- Respeta la búsqueda y los filtros activos de la tabla.
- **En cola** (servicio `worker`, `job_batches`). El enlace de descarga llega como notificación del
  panel (tabla `notifications`, columna `data` en `jsonb`).
- **Seguridad:** el botón solo aparece con el permiso de exportar del módulo; los archivos se
  guardan en el disco privado `local` (`storage/app/private`); `ExportPolicy` solo permite descargar
  a quien generó el archivo y siga activo.
- **Purga a 7 días** del **archivo temporal** y su registro en `exports` (`App\Models\Export`,
  `model:prune` diario a las 03:00 en el `scheduler`). Nunca se borran datos originales.
- **Importes en XLSX:** Filament arma el XLSX desde los CSV intermedios (todo texto). El trait
  `WritesMoneyAsNumbers` convierte solo las columnas de importe a celdas numéricas con formato
  `#,##0.00`. El CSV conserva el decimal exacto.

## Consecuencias

- Exportaciones grandes no bloquean la pantalla.
- Las exportaciones dependen de que `worker` y `scheduler` estén corriendo.
