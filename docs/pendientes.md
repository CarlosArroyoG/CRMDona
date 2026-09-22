# Pendientes

| # | Pendiente | Tipo | Fase objetivo | Notas |
|---|---|---|---|---|
| 1 | Revisar en GitHub → Actions el workflow **CI** (jobs "Pint, Larastan y Pest" y "Construir imagen de producción") en el commit de cierre de la Fase 1 | PENDIENTE EXTERNO (lo revisa el responsable) | Después del push | El push requiere autorización explícita |
| 2 | Definir el servidor Coolify y los dominios definitivos (`crm.fdonbosco.org` y `donar.fdonbosco.org` son propuestas) | PENDIENTE EXTERNO | Antes del primer despliegue | Ver `docs/tecnico/despliegue-coolify.md` |
| 3 | Primer despliegue real: recursos, variables, HTTPS, volumen `crm-storage` (compartido por app, worker y scheduler), `unaccent`, `app:create-admin`, verificación y prueba de rollback | PENDIENTE EXTERNO | Antes del primer despliegue | Depende de #2 |
| 4 | Respaldos automáticos de PostgreSQL y del volumen `crm-storage` (S3 externo) y una prueba de restauración | PENDIENTE EXTERNO | Antes de la Fase 2 (recomendado) | Ver `despliegue-coolify.md` §9 |
| 5 | Plazo de conservación de donativos y bitácora | PENDIENTE DE VALIDACIÓN LEGAL/FISCAL | Antes de producción | No hay borrado por antigüedad; nada codificado (ADR-008) |
| 6 | Proceso de anonimización para solicitudes ARCO que procedan | Técnico + legal | Fase futura | Sin borrado de historial financiero (ADR-008) |
| 7 | Reglas fiscales: uso de CFDI para donativos (¿`D04`?), compatibilidad régimen/tipo de persona, RFC genérico, tratamiento y valuación de donativos en especie | PENDIENTE de contador/PAC | Antes de la Fase 3 (CFDI) | Hoy solo se valida la estructura del RFC y el CP; `cfdi_use` sin valor predeterminado |
| 8 | Validar los catálogos SAT `c_RegimenFiscal` y `c_UsoCFDI` contra la versión vigente | PENDIENTE de contador/PAC | Antes de la Fase 3 | `app/Enums/TaxRegime.php`, `app/Enums/CfdiUse.php` |
| 9 | Recuperación de contraseña (por correo) o restablecimiento por el Administrador | Técnico (decisión de negocio) | Fase de comunicaciones | Hoy solo existe el cambio de la propia contraseña |
| 10 | Probar una exportación real en el panel (con `worker` y Redis): Exportar → aviso en la campana → Descargar .xlsx/.csv | Verificación manual del responsable | Revisión de la Fase 1 | Las pruebas automáticas la cubren con cola síncrona |
| 11 | Índice GIN (`pg_trgm`) para la búsqueda sin acentos si el volumen de donantes crece | Técnico (mejora) | Cuando haga falta | ADR-009 |
| 12 | Reducir el tamaño de la imagen `prod` (~1.1 GB) separando las herramientas de compilación | Técnico (mejora) | Refactorización posterior | No afecta el funcionamiento |
| 13 | **RF-01 — Alertas de pagos y donativos con problemas**: incidencias en el CRM + notificación en el panel + correo, destinatarios por usuario o rol, webhook inbox idempotente, clasificación normalizada de fallos, seguimiento Nueva → En revisión → Resuelta, filtros y exportaciones | Técnico (requisito aprobado) | Diseño en Fase 2; correo en la fase de comunicaciones | `docs/tecnico/requisitos-fases-futuras.md`. Decisiones abiertas sobre `donations` (origen, actores humano/sistema, `payment_method`, reembolsos): se presentan en el diseño, sin migrar antes |
