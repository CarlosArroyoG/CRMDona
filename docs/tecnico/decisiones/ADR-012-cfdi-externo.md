# ADR-012 — El CRM no emite CFDI: CFDI externo y aviso a Contabilidad

- **Estado:** aprobado por el responsable (2026-09-23): "Cambio de alcance aprobado — CFDI externo" y
  "Flujo contable definitivo".
- **Diseño completo:** `docs/tecnico/cfdi-externo.md`.
- **Sustituye:** la emisión de CFDI de la Fase 3 (`fase-3-cfdi.md`, histórico) y la parte de CFDI de ADR-005.

## Contexto

La Fase 3 implementó la emisión automática de CFDI con Facturapi:

- CFDI individual, factura global y cancelación y sustitución;
- conciliación programada;
- alertas de timbrado.

Nunca pasó a Facturapi Test ni a producción.

La Fundación decidió que **la contadora emite todos los CFDI fuera del CRM** y decide ahí el tratamiento
fiscal de cada donativo, incluida la factura global.

## Decisión

1. **Retiro de la emisión.** El CRM no emite, timbra, cancela ni sustituye CFDI y no llama a ningún PAC.
   - Se retiraron del producto el proveedor Facturapi, los Jobs de timbrado y conciliación, la factura
     global, la cobertura fiscal, las acciones de Filament de emisión y las alertas de timbrado.
   - También se retiraron su configuración y sus variables de entorno.
2. **Pasos al confirmar.** Al confirmar un donativo se generan, de forma independiente e idempotente:
   - el recibo simple;
   - el agradecimiento al donante con el recibo;
   - el **aviso a Contabilidad** con "CFDI solicitado: SÍ/NO" y, si es SÍ, los datos fiscales ya
     capturados.
3. **Destinatarios del aviso.** Solo lo reciben usuarios autorizados (`accounting.process`: Administrador
   y Contador) que el Administrador haya configurado.
4. **Control contable.** Una vista para Contabilidad distingue:
   - CFDI solicitado y no solicitado;
   - CFDI externo adjunto;
   - procesamiento contable pendiente.
5. **CFDI externo como antecedente.** Contabilidad puede adjuntar el XML y el PDF del CFDI externo al
   donativo, sin que el CRM certifique su validez.
6. **Historial.** Nada se borra de la base de datos: las tablas y columnas de la Fase 3 quedan como
   historial. Los CFDI ya timbrados se copian como antecedentes `crm_legacy`.
7. **Dependencias.** `stripe/stripe-php` se conserva: lo usan los pagos con Stripe, no el CFDI.

## Consecuencias

- Desaparecen del checklist de producción:
  - Facturapi Test y Live;
  - CSD;
  - llaves de PAC.
- Los pendientes fiscales #24–#31 de `docs/pendientes.md` dejan de aplicar al CRM: los resuelve
  Contabilidad fuera del sistema.
- Cambios en permisos:
  - `cfdi.issue`, `cfdi.cancel` y `cfdi.view_technical` dejan de existir;
  - `cfdi.view` (A, C y Co) permite consultar;
  - `cfdi.manage` (A y Co) permite adjuntar, reemplazar y retirar CFDI externos;
  - `accounting.process` (A y Co) permite recibir avisos y marcar el procesamiento.
- La página pública ya no promete que el CRM enviará el CFDI: dice que Contabilidad lo emite por separado.
