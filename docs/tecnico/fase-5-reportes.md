# Fase 5 — Tablero y reportes

Todo se deriva de estados reales del dominio. `Donation`, `Payment` y `Cfdi` siguen siendo entidades distintas.

Reglas generales:
- Los importes se suman en PostgreSQL (NUMERIC) y llegan como strings; los porcentajes se calculan con bcmath. Nunca se usa float.
- Los meses son de calendario, en la zona America/Mexico_City.
- Toda la lógica de cálculo vive en `app/Reports`; widgets, pantallas y exportaciones solo la muestran.

## 1. Tablero — definiciones exactas (`DashboardMetrics`)

| Métrica | Cálculo | No incluye |
|---|---|---|
| **Recaudado en {mes}** | Suma de `donations.amount` con `status = confirmed` y `kind = monetary`, y `received_on` dentro del mes en curso | Especie (valuación [F]), donativos por confirmar y cancelados. **No resta reembolsos** |
| **Comparación** | `(actual − anterior) / anterior × 100`, redondeado a 1 decimal (mitad hacia arriba). El mes en curso **hasta hoy** contra el **mes anterior completo** | Si el mes anterior es 0: "sin base de comparación" |
| **Donantes nuevos** | Donantes cuyo **primer** donativo confirmado (dinero o especie; mínimo de `received_on`) cae en el mes | Donantes registrados sin donativo confirmado; donantes recurrentes |
| **Donativos mensuales activos** | `subscriptions.status = active` en este momento (foto, no depende del mes). Se muestran aparte los `past_due` y `paused` | Pendientes, cancelados y vencidos |
| **Tasa de fallos de pagos** | Pagos en línea creados en el mes (`payments.created_at`) con estado final: `failed / (succeeded + failed) × 100`, a 1 decimal. Un pago recuperado tras un rechazo cuenta como exitoso | Pagos pendientes, en proceso y cancelados. Sin pagos terminados: "—" |
| **Reembolsado en el mes** | Suma de `refunds.amount` con `status = succeeded` y `processed_at` en el mes | Reembolsos pendientes, fallidos o cancelados |
| **Cumpleaños próximos** | Donantes no archivados con `birth_date` entre hoy y los 6 días siguientes; el 29 de febrero se cuenta el 28 en años no bisiestos. Se indica si recibirán felicitación (consentimiento + correo) | Archivados |

Visibilidad (Policies existentes):
- lo recaudado y los donantes nuevos: `donations.view`;
- los donativos mensuales: `subscriptions.view`;
- la tasa de fallos y lo reembolsado: `payments.view`;
- los cumpleaños: `donors.view`.

Los cuatro roles tienen estos permisos, igual que las pantallas de origen.

## 2. Reporte de pagos (Pagos en línea)

La pantalla de la Fase 2 se amplía con:

| Filtro | Definición |
|---|---|
| Estado | `payments.status`: pendiente, en proceso, exitoso, fallido o cancelado. **Reembolsado no es cancelado**: se filtra aparte |
| Reembolso | Parcial: 0 < reembolsado < importe. Total: reembolsado ≥ importe. Solo cuentan los reembolsos `succeeded` |
| Situación → Recuperado | Pago `succeeded` con al menos un intento `failed` |
| Situación → Cobro mensual fallido | `kind = recurring_charge` y `status = failed` |
| Con incidencia / Motivo de rechazo | Existe una `payment_incident` / categoría de algún intento fallido. Solo con `incidents.view`, como en la Fase 2 |
| Otros | Fecha (`created_at`), donante, campaña, programa (directo o por campaña), importe mínimo y máximo, proveedor, único o mensual |

- **Totales del filtro** (`PaymentReport::totals`, en NUMERIC):
  - número de pagos;
  - importe de todos;
  - cobrado (solo exitosos);
  - reembolsado (reembolsos exitosos).
- **Exportación CSV/XLSX:** la de la Fase 2, con los mismos filtros, más la columna "Recuperado tras rechazo".
  - Reembolsos y "recuperado" se precargan (sin consultas por fila).
  - La exportan quienes tienen `payments.export`; la versión técnica, quienes tienen `payments.view_technical`.

## 3. Reporte CFDI (Reportes → Reporte CFDI)

- **Qué incluye:** una fila por donativo confirmado, o con algún CFDI.
- **Columnas:**
  - fecha del donativo, donante e importe;
  - estado del CFDI vigente, UUID y fecha de timbrado;
  - ruta fiscal;
  - bloqueo o error;
  - número de CFDI cancelados.
- **Ruta fiscal:**
  - con CFDI vigente es "CFDI individual";
  - sin él, la calcula `ResolveDonationFiscalRoute`, la misma lógica que la emisión: individual, público en general o bloqueado.
  - **No hay factura global**, así que el público en general se muestra como tal, sin CFDI inventado.
- **Filtros:**
  - rango de fechas y donante;
  - estado del CFDI vigente (o "Sin CFDI vigente");
  - público en general;
  - con CFDI cancelados.
- **Consistencia del filtro "Público en general":** está escrito en SQL con la misma regla (`BuildDonationCfdiDraft::GENERIC_RFC`), y una prueba comprueba que coincide con `isPublicGeneral`.
- **Permisos:**
  - pantalla y exportación con `cfdi.view` (Administrador, Coordinador y Contador); Solo lectura no tiene acceso;
  - el mensaje técnico del PAC solo aparece con `cfdi.view_technical`;
  - la exportación nunca incluye RFC ni el mensaje técnico.
- **Rendimiento:** los CFDI, el perfil fiscal y el pago se precargan. La ruta de los donativos sin CFDI se calcula por fila con la lógica de emisión, sin duplicarla. Es aceptable con paginación; si el volumen crece, se puede materializar después.

## 4. Pruebas

Cubren:
- cada métrica, el mes sin datos y la comparación de meses;
- pagos únicos y mensuales, fallidos y recuperados, reembolsos parciales y totales, e incidencias;
- filtros combinados, totales exactos, exportaciones y un control de consultas por fila;
- el reporte CFDI con sus filtros y exportación;
- los permisos por rol.
