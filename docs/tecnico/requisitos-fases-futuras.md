# Requisitos y dependencias para fases futuras

Requisitos ya aprobados que **no** se implementan todavía, pero que condicionan el diseño de fases
posteriores. Leer antes de diseñar la fase indicada. Nada de este documento autoriza migraciones:
cada fase presenta su diseño y espera aprobación.

**Separación conceptual obligatoria:**

`Donation ≠ Payment ≠ PaymentAttempt ≠ Subscription ≠ DonationReceipt ≠ Cfdi`

- `Donation`: el donativo reconocido por el CRM.
- `Payment`: la operación de cobro en la pasarela.
- `PaymentAttempt`: cada intento real de cobro (si el diseño de la Fase 2 lo justifica).
- `Subscription`: el compromiso recurrente; su estado no depende de un intento aislado.
- `DonationReceipt`: recibo simple de agradecimiento (no es CFDI).
- `Cfdi`: comprobante fiscal deducible, solo cuando corresponda y se solicite.

El método de pago en línea, la marca y los últimos cuatro dígitos de una tarjeta, los códigos de
rechazo y datos equivalentes pertenecen al **pago o intento**, nunca al donativo.

---

## RF-01 — Alertas de pagos y donativos con problemas

- **Registrado y aprobado:** 2026-09-22 (revisión de la Fase 1).
- **Se diseña en:** Fase 2 (pagos, intentos, webhooks, incidencias, vista, filtros y exportaciones).
- **Se completa en:** fase de comunicaciones (envío de correo reutilizando las colas).
- **En la Fase 1:** solo documentación. No se agregó lógica de pagos, webhooks ni correos, ni
  columnas anticipadas a `donations`.

### Flujo conceptual

```
PaymentAttempt → fallo → registro persistente → incidencia → notificación en el CRM
               → correo al responsable → seguimiento hasta resolverla
```

- El correo es solo un **canal de aviso**; la **fuente de verdad** de la incidencia es el CRM.
- Leer una notificación **no** resuelve la incidencia.

### 1. Incidencias

Estados: `new → reviewing → resolved` (en pantalla: **Nueva → En revisión → Resuelta**). Sin
sistema complejo de tickets. Cada incidencia registra:

- causa (categoría normalizada);
- pago o intento relacionado;
- fecha y hora;
- quién la tomó para revisión y cuándo comenzó la revisión;
- quién la resolvió y cuándo;
- notas de seguimiento cuando corresponda;
- resolución.

### 2. Causas de fallo normalizadas

Clasificación interna independiente de cualquier pasarela, por ejemplo: tarjeta vencida, tarjeta
rechazada, fondos insuficientes, autenticación requerida o fallida (cuando el proveedor lo informe),
error de procesamiento, error temporal de la pasarela, cobro recurrente fallido, webhook no
procesado, pago que requiere revisión, causa desconocida.

Se conservan **por separado**, cuando sea seguro: la categoría interna, el código original del
proveedor y el mensaje **sanitizado** del proveedor. No se exponen ni registran payloads sensibles
innecesarios.

### 3. Webhooks e idempotencia

Bandeja persistente (*inbox*) o mecanismo equivalente con, como mínimo: proveedor, identificador
externo único del evento, tipo de evento, payload sanitizado o protegido, fecha de recepción, fecha
de procesamiento, estado de procesamiento, número de intentos, último error seguro para logs y
relación con `Payment` / `PaymentAttempt` cuando pueda determinarse.

- Restricción de unicidad (por ejemplo, proveedor + identificador externo) para no procesar dos
  veces el mismo evento.
- Un **reintento del mismo webhook** no duplica el pago, el donativo ni la incidencia, y no reenvía
  la alerta.
- Un **intento de cobro realmente nuevo** sí se registra como otro intento.
- Si un webhook importante sigue fallando después de los reintentos definidos, genera una
  incidencia para revisión humana.

### 4. Seguridad de tarjetas

Nunca almacenar: PAN completo, CVV/CVC, banda magnética, datos de autenticación sensibles ni nada
que el proveedor prohíba almacenar. **No diseñamos almacenamiento de tarjetas**: la pasarela se
encarga de los datos sensibles.

Solo cuando el proveedor lo permita, metadatos seguros para soporte: identificador o token del
método de pago, marca, últimos cuatro dígitos y, únicamente si hay una finalidad operativa clara y
el proveedor lo permite, mes y año de expiración.

### 5. Notificaciones dentro del CRM y destinatarios

- Reutilizar lo existente: tabla `notifications`, notificaciones de Filament, Redis, `worker`,
  usuarios, roles y la matriz de permisos con Policies.
- Nunca correos escritos en el código.
- Solución **mínima** para decidir quién recibe alertas operativas: como mínimo el Administrador;
  configurable después para otros responsables autorizados (Coordinador, Contador). Sin plataforma
  compleja de preferencias. La propuesta concreta se presenta en el diseño de la Fase 2.

### 6. Correo de alerta (fase de comunicaciones)

Solo información suficiente para actuar: donante, importe, fecha y hora, campaña o programa, pago o
intento, tipo de operación, categoría del fallo, información segura del método de pago si existe y
enlace al registro en el CRM. Nunca datos sensibles de tarjeta.

### 7. Pagos recurrentes

Un intento fallido **no** cancela la suscripción. Se distinguen: suscripción activa, intento de
cobro, intento fallido, reintento, cobro recuperado, suscripción pausada y suscripción cancelada. Se
conserva el historial completo de intentos: si un cobro se recupera, los fallos anteriores no
desaparecen.

### 8. Vista, reportes y exportaciones

Filtrar y exportar (CSV/XLSX) al menos: pagos exitosos, pendientes, fallidos, cancelados y
reembolsados; reembolsos parciales y totales si el proveedor los soporta; cobros recurrentes
fallidos; pagos recuperados después de un fallo; incidencias nuevas, en revisión y resueltas;
tarjeta vencida, rechazada, fondos insuficientes y demás categorías; rango de fechas, donante,
campaña, programa e importe.

La exportación respeta exactamente filtros, búsqueda, permisos y protección de datos, reutilizando
la infraestructura de la Fase 1 (ADR-007).

---

## Hallazgos sobre el modelo actual que la Fase 2 debe resolver (sin migrar antes de aprobar el diseño)

El modelo de la Fase 1 supone que todo donativo es manual. No impide los pagos en línea, pero hay
que decidir:

1. **Origen del donativo.** Distinguir un donativo capturado por un usuario de uno creado
   automáticamente por un pago exitoso. Analizar una solución tipo `origin = manual / online`.
2. **Actores humanos y del sistema.** `registered_by_id` y `confirmed_by_id` hoy presuponen una
   persona. **No se creará un "usuario sistema" ficticio.** El diseño debe expresar si una persona
   registró o confirmó, o si el sistema lo hizo como consecuencia verificable de un evento externo
   (por ejemplo, el pago o evento que lo originó), sin atribuir falsamente la acción a un humano.
3. **`donations.payment_method`.** No se asume agregar métodos en línea (como tarjeta) a
   `Donation`. Analizar si debe: (a) seguir representando solo métodos de captura manual;
   (b) renombrarse para dejar explícito ese significado; (c) ser nulo o no aplicable en donativos
   originados por pagos electrónicos; o (d) sustituirse por un diseño más limpio.
4. **Reembolsos.** Un reembolso **no** equivale automáticamente a cancelar el donativo: es un hecho
   financiero posterior al pago y se conserva el historial. El diseño debe explicar la relación
   entre donativo confirmado, pago exitoso, reembolso parcial, reembolso total y cancelación
   administrativa. **Nunca** se borra el pago original ni el donativo histórico.

---

## Recibo simple y CFDI (requisitos obligatorios de fases posteriores)

- **Recibo simple:** toda donación confirmada podrá generar recibo simple con folio, PDF, correo
  automático de agradecimiento y recibo adjunto. **No es un CFDI.**
- **CFDI:** flujo independiente; solo cuando corresponda y el donante solicite comprobante fiscal
  deducible. Reglas fiscales pendientes de validación (ver `docs/pendientes.md`).
- Se mantienen separados: `Donation ≠ DonationReceipt ≠ Cfdi`, y todos separados de `Payment`.

---

## Qué ya existe y se reutiliza

- Tabla `notifications` (`data` en `jsonb`) y notificaciones de Filament (campana).
- Colas en Redis con el servicio `worker`; `scheduler` para tareas periódicas.
- Matriz de permisos (`App\Enums\Permission`), Policies, roles y usuarios activos.
- Bitácora (`Auditable`, `AuditEvent`) y exportaciones nativas con purga (ADR-007).
- Separación `Donation` / `Payment` (ADR-005): un pago fallido nunca crea un donativo.
