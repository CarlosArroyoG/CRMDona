# ADR-004 — Manejo de importes

- **Estado:** Aceptado
- **Fecha:** 2026-09-22

## Decisión

- **PostgreSQL `numeric(12,2)`** para todo importe (`donations.amount`, `campaigns.goal_amount`).
  Máximo 9,999,999,999.99 MXN: suficiente para cualquier donativo o meta razonable. Ampliarlo no
  aporta nada; las sumas en PostgreSQL (`SUM(numeric)`) no tienen límite.
- **Nunca `float`/`double`** en base de datos ni en la lógica. En PHP los importes viajan como
  **strings decimales** (cast `decimal:2`) y se operan con **bcmath** (`App\Support\Money`).
- `Money::normalize()` acepta "1,234.5" o "1234" y devuelve "1234.50"; rechaza negativos, cero, más
  de dos decimales y valores fuera de rango. La regla `App\Rules\MoneyAmount` lo usa en validación.
- **Moneda MXN**: columna `currency` con `CHECK (currency = 'MXN')` mientras no haya otras monedas.
- **Exportaciones:** en CSV el importe va como texto exacto; en XLSX como celda numérica con formato
  `#,##0.00` para que Excel pueda sumar (solo presentación; ver ADR-007).

## Consecuencias

- No hay errores de redondeo binario (0.1 + 0.2) en importes.
- Formatear para mostrar usa `Money::format()` ("$1,234.50"), sin pasar por float.
