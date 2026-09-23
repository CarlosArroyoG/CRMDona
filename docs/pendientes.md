# Pendientes

| # | Pendiente | Tipo | Fase objetivo | Notas |
|---|---|---|---|---|
| 1 | Revisar en GitHub → Actions el workflow **CI** (jobs "Pint, Larastan y Pest" y "Construir imagen de producción") en los commits de la Fase 2 (incluye el build de producción con `stripe/stripe-php`) | PENDIENTE EXTERNO (lo revisa el responsable) | Después del push | El push requiere autorización explícita |
| 2 | Definir el servidor Coolify y los dominios definitivos (`crm.fdonbosco.org` y `donar.fdonbosco.org` son propuestas) | PENDIENTE EXTERNO | Antes del primer despliegue | Ver `docs/tecnico/despliegue-coolify.md` |
| 3 | Primer despliegue real: recursos, variables, HTTPS, volumen `crm-storage` (compartido por app, worker y scheduler), `unaccent`, `app:create-admin`, verificación y prueba de rollback | PENDIENTE EXTERNO | Antes del primer despliegue | Depende de #2 |
| 4 | Respaldos automáticos de PostgreSQL y del volumen `crm-storage` (S3 externo) y una prueba de restauración | PENDIENTE EXTERNO | Antes de la Fase 2 (recomendado) | Ver `despliegue-coolify.md` §9 |
| 5 | Plazo de conservación de donativos y bitácora | PENDIENTE DE VALIDACIÓN LEGAL/FISCAL | Antes de producción | No hay borrado por antigüedad; nada codificado (ADR-008) |
| 6 | Proceso de anonimización para solicitudes ARCO que procedan | Técnico + legal | Fase futura | Sin borrado de historial financiero (ADR-008) |
| 7 | Reglas fiscales: uso de CFDI para donativos (¿`D04`?), compatibilidad régimen/tipo de persona, RFC genérico, tratamiento y valuación de donativos en especie | PENDIENTE de contador/PAC | Antes de la Fase 3 (CFDI) | Hoy solo se valida la estructura del RFC y el CP; `cfdi_use` sin valor predeterminado |
| 8 | Validar los catálogos SAT `c_RegimenFiscal` y `c_UsoCFDI` contra la versión vigente | PENDIENTE de contador/PAC | Antes de la Fase 3 | `app/Enums/TaxRegime.php`, `app/Enums/CfdiUse.php` |
| 9 | Recuperación de contraseña **por correo** (autoservicio) | Técnico | Fase de comunicaciones | El restablecimiento por el Administrador y el comando de consola ya existen (ADR-010) |
| 10 | ~~Probar una exportación real con `worker` y Redis~~ | Cerrado | — | Verificada manualmente por el responsable (2026-09-22) |
| 11 | Índice GIN (`pg_trgm`) para la búsqueda sin acentos si el volumen de donantes crece | Técnico (mejora) | Cuando haga falta | ADR-009 |
| 12 | Reducir el tamaño de la imagen `prod` (~1.1 GB) separando las herramientas de compilación | Técnico (mejora) | Refactorización posterior | No afecta el funcionamiento |
| 14 | **Sandbox de Stripe**: cuenta de prueba MX, llaves en `.env`, webhook con Stripe CLI, Smart Retries con "dejar la suscripción vencida" y ciclo completo con Test Clocks. Confirmar los [S] de Stripe (§25.2 del diseño) | PENDIENTE EXTERNO + [S] | Antes de habilitar Stripe | Instrucciones: `docs/tecnico/integraciones-pagos.md` §3.1 |
| 15 | **Sandbox de Mercado Pago**: aplicación, cuentas de prueba, credenciales de prueba y webhooks. Ejecutar el checklist de 16 puntos (§16.3 del diseño): Orders, `/preapproval` sin plan, `authorized_payment`, reintentos reales, pausa, reactivación, contracargos, límites | PENDIENTE EXTERNO + [S] | Antes de habilitar Mercado Pago | Si contradice la opción A, detenerse y consultar |
| 16 | Límites de negocio de donativos en línea (mínimo y máximo) | [D] DECIDIDO 2026-09-23: permanecen `null` hasta decisión posterior | Después del sandbox | Solo aplica el técnico (Stripe 10 MXN; Mercado Pago sin verificar). No inventar valores |
| 17 | Pausa en Stripe con `pause_collection.behavior = void` (el periodo pausado no acumula adeudo) | [D] APROBADO PROVISIONALMENTE 2026-09-23; validación real [S] | Stripe Test | Implementado como `void`; reversible en el adaptador |
| 18 | Tratamiento fiscal de reembolsos y contracargos (recibo, CFDI) | PENDIENTE DE VALIDACIÓN FISCAL | Fase 3 (CFDI) | Hoy no se automatiza nada fiscal |
| 19 | Página pública de donación (Checkout embebido de Stripe y Bricks de Mercado Pago) | Técnico | Fase posterior | El dominio ya expone `StartOneTimeDonation` y `StartMonthlyDonation` |
| 20 | Envío de alertas de incidencias por correo | Técnico | Fase de comunicaciones | Hoy: campana de Filament |
| 21 | Opción B de Mercado Pago (cobros iniciados por el comercio, reintentos del CRM) | Alternativa futura | Solo si se decide | `retry_owner = crm` ya está previsto |
| 22 | OXXO, SPEI y meses sin intereses | Ampliación futura | Solo si se decide | Serían capacidades nuevas del gateway |
| 23 | Respaldo de `crm` antes de migrar a la Fase 2 (`pg_dump`, archivo local de la sesión) | Informativo | — | Las migraciones se aplicaron sin pérdida (1 usuario conservado) |
| 13 | ~~**RF-01 — Alertas de pagos**~~ Implementado en la Fase 2 (incidencias, campana, webhook inbox, fallos normalizados, seguimiento). Falta solo el correo (#20) | Cerrado (salvo correo) | Fase 2 | Decisiones sobre `donations` resueltas e implementadas (`origin`, `payment_id`, `manual_payment_method`, sin usuario "sistema") |
| 24 | **Elegir el PAC** (Facturapi recomendado técnicamente; alternativas Facturama y SW sapien) y aprobar su integración | [D] DECISIÓN | Fase 3 | `docs/tecnico/fase-3-cfdi.md` §5. Requiere luego credenciales de prueba y CSD de prueba (nunca en git) |
| 25 | **[F] Obligación del SAT de emitir CFDI por todo donativo recibido vs requisito "solo si el donante lo solicita"**; donantes sin RFC (XAXX010101000 o factura global); plazo de 24 h | [F] CONTRADICCIÓN A RESOLVER | Antes de activar `CFDI_AUTO_ISSUE` | `fase-3-cfdi.md` §2.1 |
| 26 | [F] CFDI de especie, tarjeta en línea (04 o 28), depósito bancario, vigencia de la autorización, sustitución (01) y motivo 04, efecto fiscal de reembolsos y contracargos; vigencia de la guía SAT en la RMF 2026 | [F] | Fase 3 | Bloqueados en código con motivo; no hay regla inventada |
| 27 | [S] Timbrado real con complemento de donatarias, idempotencia del PAC, PDF con leyenda, validación de uso contra régimen, estados de cancelación | [S] | Tras elegir PAC | — |
| 28 | Aprobar los permisos de CFDI propuestos (ver A/C/Co; emitir y cancelar A/Co; Solo lectura nada) | [D] | Fase 3 | Reversibles en la matriz |
