# Fase 7 — Operación, seguridad y rendimiento

**Estado: cerrada localmente** (2026-09-23)

## Validado localmente

- Pest: 611 pruebas, 2487 aserciones.
- Pint: 421 archivos correctos.
- Larastan nivel 8: sin errores.
- Hardening: CSP pública, CSP report-only del panel, HSTS, trusted proxies, límites de acceso y protección de comandos destructivos.
- RF-01: fallo persistente de pago -> incidencia -> notificación CRM y correo a responsables configurados; 7 pruebas y 29 aserciones focalizadas. La alerta es idempotente y marcarla como leída no resuelve la incidencia.
- Migraciones: la prueba de rollback/reapply pasó con 16 migraciones en la ventana real. La corrección fue del conteo del test, no de una migración publicada.
- `migrate:fresh --seed`: validado en base desechable.
- Ciclo `migrate:fresh` -> rollback de 16 pasos -> `migrate`: validado en base desechable sin datos que activen bloqueos de reversión.
- Backup/restore PostgreSQL local: `pg_dump -Fc` y `pg_restore` validados entre `crm_backup_source` y `crm_backup_restore`, con esquema, fila identificable y 30 registros de migraciones verificados. Las bases y el dump temporal fueron eliminados.
- Frontend: build reproducible mediante el stage `assets` de Docker (Node 24).
- Imagen de producción: `docker build --target prod` completado.
- Composer: `validate --strict` y `check-platform-reqs` completados.

## No validado localmente o pendiente externo `[S]`

- Stripe sandbox y Mercado Pago sandbox.
- Facturapi Test.
- SMTP real y rebotes.
- S3 real.
- Dominio HTTPS real.
- Despliegue en Coolify.
- Credenciales productivas.
- Decisiones fiscales pendientes.

Este cierre no declara producción validada.
