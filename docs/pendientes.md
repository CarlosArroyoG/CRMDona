# Pendientes

| # | Pendiente | Tipo | Fase objetivo | Notas |
|---|---|---|---|---|
| 1 | Revisar en GitHub → Actions el workflow **CI** (jobs "Pint, Larastan y Pest" y "Construir imagen de producción") en los commits de la Fase 2 (incluye el build de producción con `stripe/stripe-php`) | PENDIENTE EXTERNO (lo revisa el responsable) | Después del push | El push requiere autorización explícita |
| 2 | Definir el servidor Coolify y el dominio definitivo (una sola app y un solo dominio para `/admin`, `/donar` y `/up`; `<dominio>` es un marcador de posición) | PENDIENTE EXTERNO | Antes del primer despliegue | Ver `docs/tecnico/despliegue-coolify.md` |
| 3 | Primer despliegue real: recursos, variables, HTTPS, volumen `crm-storage` (compartido por app, worker y scheduler), `unaccent`, `app:create-admin`, verificación y prueba de rollback | PENDIENTE EXTERNO | Antes del primer despliegue | Depende de #2 |
| 4 | Respaldos automáticos de PostgreSQL y del volumen `crm-storage` (S3 externo) y una prueba de restauración | PENDIENTE EXTERNO | Antes de la Fase 2 (recomendado) | Ver `despliegue-coolify.md` §9 |
| 5 | Plazo de conservación de donativos y bitácora | PENDIENTE DE VALIDACIÓN LEGAL/FISCAL | Antes de producción | No hay borrado por antigüedad; nada codificado (ADR-008) |
| 6 | Proceso de anonimización para solicitudes ARCO que procedan | Técnico + legal | Fase futura | Sin borrado de historial financiero (ADR-008) |
| 7 | ~~Reglas fiscales para emitir CFDI (uso D04, régimen, RFC genérico, especie)~~ | Sin efecto en el CRM (ADR-012) | — | La contadora emite los CFDI fuera del CRM y aplica esas reglas ahí. El CRM solo valida la estructura del RFC y el CP que se envían en el aviso contable |
| 8 | Validar los catálogos SAT `c_RegimenFiscal` y `c_UsoCFDI` contra la versión vigente | Contabilidad (informativo) | Antes de producción | Solo afectan la captura de datos fiscales del donante que se envían a Contabilidad; el CRM ya no emite |
| 9 | ~~Recuperación de contraseña **por correo**~~ **Implementada 2026-09-23** (Filament `passwordReset`, sale por el correo saliente vigente; anula la temporal) | Cerrado | Bloque SMTP | `docs/tecnico/correo-saliente.md` §5 |
| 10 | ~~Probar una exportación real con `worker` y Redis~~ | Cerrado | — | Verificada manualmente por el responsable (2026-09-22) |
| 11 | Índice GIN (`pg_trgm`) para la búsqueda sin acentos si el volumen de donantes crece | Técnico (mejora) | Cuando haga falta | ADR-009 |
| 12 | Reducir el tamaño de la imagen `prod` (~1.1 GB) separando las herramientas de compilación | Técnico (mejora) | Refactorización posterior | No afecta el funcionamiento |
| 14 | **Stripe Test: validación funcional completada** (2026-09-23): pago aprobado, pago rechazado, webhook real firmado, rechazo de firma inválida, idempotencia, eventos fuera de orden y Payment → Donation → recibo → agradecimiento → aviso contable. Pendiente para producción: cuenta y credenciales institucionales Live y configuración productiva | Test validado; Live PENDIENTE DE LA INSTITUCIÓN | Antes de habilitar Stripe en producción | Acción de seguridad: rotar la `sk_test_` expuesta durante las pruebas |
| 15 | **Mercado Pago — PENDIENTE DE LA INSTITUCIÓN** (no es un defecto del CRM ni bloquea otros bloques). Pasos: 1) crear la cuenta institucional; 2) crear la aplicación o integración; 3) obtener credenciales **Test**; 4) configurar `MERCADO_PAGO_ACCESS_TOKEN` y `MERCADO_PAGO_PUBLIC_KEY` solo en `.env` o en Coolify, nunca en Git; 5) configurar el webhook HTTPS `/webhooks/payments/mercado_pago` (en local, con un túnel HTTPS); 6) configurar `MERCADO_PAGO_WEBHOOK_SECRET`; 7) validar un pago aprobado; 8) validar un pago rechazado; 9) validar webhook e idempotencia; 10) solo después, credenciales productivas | PENDIENTE EXTERNO | Antes de habilitar Mercado Pago | `integraciones-pagos.md` §3.2 |
| 16 | Límites de negocio de donativos en línea (mínimo y máximo) | [D] DECIDIDO 2026-09-23: permanecen `null` hasta decisión posterior | Después del sandbox | Solo aplica el técnico (Stripe 10 MXN; Mercado Pago sin verificar). No inventar valores |
| 17 | Pausa en Stripe con `pause_collection.behavior = void` (el periodo pausado no acumula adeudo) | [D] APROBADO PROVISIONALMENTE 2026-09-23; validación real [S] | Stripe Test | Implementado como `void`; reversible en el adaptador |
| 18 | ~~Tratamiento fiscal de reembolsos y contracargos sobre CFDI~~ | Sin efecto en el CRM (ADR-012) | — | Lo decide Contabilidad fuera del CRM. El CRM avisa (alerta operativa) si se reembolsa un donativo con CFDI externo adjunto |
| 19 | ~~Página pública de donación~~ **Implementada en la Fase 6** con FakeGateway. Stripe (Checkout embebido) y Mercado Pago (Card Payment Brick) quedan [S] hasta su sandbox (#14, #15) | Cerrado salvo [S] | Fase 6 | `docs/tecnico/fase-6-pagina-publica.md` |
| 20 | Envío de alertas de incidencias por correo al personal (RF-01) | Técnico | Siguiente bloque de comunicaciones (no incluido en la Fase 4) | Hoy: campana de Filament |
| 21 | Opción B de Mercado Pago (cobros iniciados por el comercio, reintentos del CRM) | Alternativa futura | Solo si se decide | `retry_owner = crm` ya está previsto |
| 22 | OXXO, SPEI y meses sin intereses | Ampliación futura | Solo si se decide | Serían capacidades nuevas del gateway |
| 23 | Respaldo de `crm` antes de migrar a la Fase 2 (`pg_dump`, archivo local de la sesión) | Informativo | — | Las migraciones se aplicaron sin pérdida (1 usuario conservado) |
| 24 | ~~Elegir el PAC / Facturapi Test~~ | Retirado (ADR-012, 2026-09-23) | — | Facturapi retirado y sin uso; no hay PAC, CSD ni llaves fiscales |
| 25 | ~~Contradicción "solo si el donante lo solicita"~~ | Cerrado (ADR-012) | — | "CFDI solicitado" vuelve a ser un dato operativo: se informa a Contabilidad en el aviso de cada donativo |
| 26 | ~~[F] Especie, depósito bancario, prepago, extranjeros, vigencia, reembolsos~~ | Sin efecto en el CRM (ADR-012) | — | Contabilidad decide fuera del sistema |
| 27 | ~~[S] Facturapi (complemento, relaciones, cancelación, PDF)~~ | Retirado (ADR-012) | — | — |
| 28 | ~~Permisos de emisión de CFDI~~ | Sustituido (ADR-012) | — | Ahora: `cfdi.view` (A, C, Co), `cfdi.manage` (A, Co) y `accounting.process` (A, Co) |
| 29 | ~~[F] Factura global al público en general~~ | Sin efecto en el CRM (ADR-012) | — | El CRM no decide qué donativos integran una factura global; la emite Contabilidad fuera |
| 30 | [S] Tipo de tarjeta (crédito/débito) que reportan Stripe y Mercado Pago en cobros reales | [S] (informativo) | Sandbox de pagos | Ya no bloquea nada: solo se muestra en el aviso a Contabilidad si se conoce |
| 31 | ~~[F] Emisión tardía de CFDI~~ | Sin efecto en el CRM (ADR-012) | — | Contabilidad emite fuera; la cola "Control contable" muestra lo pendiente de procesar |
| 13 | ~~**RF-01 — Alertas de pagos**~~ Implementado en la Fase 2 (incidencias, campana, webhook inbox, fallos normalizados, seguimiento). Falta solo el correo (#20) | Cerrado (salvo correo) | Fase 2 | Decisiones sobre `donations` resueltas e implementadas (`origin`, `payment_id`, `manual_payment_method`, sin usuario "sistema") |
| 32 | **Proveedor de correo real**: la configuración SMTP ya es administrable (Administración → Correo saliente). Falta elegir proveedor, capturar sus datos en producción, publicar SPF/DKIM/DMARC del dominio remitente y hacer la prueba de envío | PENDIENTE EXTERNO | Antes de producción | `docs/tecnico/correo-saliente.md`. Las pruebas nunca usan la red |
| 33 | **Rebotes** (`bounced`): requieren los avisos (webhooks) del proveedor de correo que se elija. El estado existe en el modelo, sin integración | Técnico, depende de #32 | Tras elegir proveedor | No se inventó soporte de un proveedor concreto |
| 34 | [D] **APROBADO 2026-09-23**: agradecimiento, recibo y CFDI son transaccionales (no dependen del consentimiento ni la baja los bloquea); cumpleaños y comunicaciones no transaccionales exigen consentimiento. **El aviso de privacidad debe describir ambas finalidades** (pendiente de redacción legal) | DECIDIDO | Antes de producción (aviso) | `COMMUNICATIONS_TRANSACTIONAL_REQUIRES_CONSENT=true` lo invierte |
| 35 | Logotipo en el PDF del recibo simple (hoy solo texto; el generador propio no incrusta imágenes) | Técnico (mejora) | Refactorización posterior | Sin paquete de PDF (requiere ADR) |
| 36 | Baja "en un clic" (`List-Unsubscribe-Post`, RFC 8058) para grandes proveedores de correo | Técnico (mejora) | Tras #32 | Hoy el enlace lleva a una confirmación |
| 37 | ~~Aplicar a `crm` la migración de donantes públicos~~ **Aplicada 2026-09-23** (1 usuario conservado) | Cerrado | Fase 6 | — |
| 38 | Build local de estilos: en Windows (libkrun) los enlaces simbólicos de `node_modules/.bin` no se guardan; usar `node node_modules/vite/bin/vite.js build`. La imagen de producción compila normal | Informativo | — | Verificado 2026-09-23 |
| 39 | Cantidades sugeridas definitivas de la página pública (hoy 200, 500, 1000 y 2000 MXN, configurables) | [D] Negocio (reversible) | Antes de producción | `DONATIONS_SUGGESTED_AMOUNTS` |
| 40 | Activar en producción "Recibe avisos a Contabilidad" para la contadora (Usuarios → Editar). Sin destinatarios, los avisos quedan "No enviado" y los Administradores reciben una alerta diaria | PENDIENTE EXTERNO | Antes de producción | `docs/tecnico/cfdi-externo.md` §3 |
| 41 | ~~Aviso de privacidad según el flujo real~~ **Resuelto en el CRM 2026-09-23**: resumen visible en `/donar` antes de enviar datos (fiscal solo para Contabilidad, CFDI externo, pago sin tarjeta completa ni CVV, transaccional vs. informativo, baja) y versión mostrada. Contenido funcional para la institución en `docs/privacidad/aviso-privacidad-contenido-funcional.md`. El texto definitivo y sus datos (domicilio, contacto de privacidad, ARCO, transferencias, plazos) son de la institución (ver abajo) | Cerrado en el CRM | — | No es un dictamen jurídico |
| 42 | Donativos confirmados antes del aviso a Contabilidad: quedaron "No enviado" y pendientes de procesamiento contable. Contabilidad decide si los marca como procesados (hay acción en lote) | Operativo | Al migrar `crm` y producción | No se envía correo retroactivo |
| 43 | Aplicar a `crm` la migración `2026_10_02_000001_create_mail_settings_table` (aditiva) | PENDIENTE DE AUTORIZACIÓN | Antes de usar Correo saliente en local | Validada en `crm_validation` (fresh, rollback y reaplicación) |
| 44 | Si se rota `APP_KEY`, volver a capturar la contraseña SMTP (queda cifrada con la llave anterior) | Operativo | Siempre | `despliegue-coolify.md` |
| 45 | ~~Segundo factor (MFA)~~ **Implementado 2026-09-24**: MFA nativo de Filament obligatorio para los cuatro roles, con aplicación autenticadora (TOTP) y códigos de recuperación de un solo uso. Secreto cifrado y códigos con hash; fuera de la bitácora | Cerrado | — | Manual: `01-acceso-al-panel.md`. Sin paquetes nuevos (dependencias propias de Filament) |
| 46 | Mercado Pago: rechazar notificaciones con `ts` antiguo (repetición). Hoy se valida la firma, y la repetición no cambia datos porque se consulta el estado real al proveedor | [S] Técnico | Sandbox de Mercado Pago (#15) | Confirmar en sandbox si `ts` viene en segundos o milisegundos antes de fijar la tolerancia |
| 47 | Defensa en profundidad: `UpdateUser`, `CreateUser`, `SaveProgram`, `SaveCampaign`, `Delete*`, `SetDonorArchived`, `UpdatePendingDonation` y `UpdateOrganizationSettings` no reciben al usuario y confían en las Policies de Filament | Técnico (mejora) | Refactorización posterior | Revisión de seguridad 2026-09-24. `Register/Confirm/CancelDonation` y `SetUserActive` ya revalidan el permiso |
| 48 | Recuperar una cuenta que perdió la aplicación autenticadora **y** los códigos de recuperación. Hoy el CRM no permite que otra persona apague el MFA de un usuario; restablecer la contraseña no lo quita. El Administrador solo puede desactivar la cuenta, y para un Administrador bloqueado existe `app:create-admin` | [D] Decisión del responsable | Antes de producción (recomendado) | Sin bypass; definir un procedimiento verificado y auditado |
| 49 | Mercado Pago en el cobro asistido: validar con el sandbox institucional el Card Payment Brick en el resumen del enlace (reintento con la misma llave y `card_token` nuevo) y el mensual por `/preapproval` | [S] Técnico | Sandbox de Mercado Pago (#15) | La solicitud, el enlace y el cierre por webhook no dependen del proveedor. `docs/tecnico/solicitudes-de-pago.md` §7 |
| 50 | Subir el logotipo oficial de la Fundación en **Organización → Logotipo** (PNG o JPG, idealmente con fondo transparente). Es la única fuente para panel, `/donar`, favicon, recibo y correos | PENDIENTE DE LA INSTITUCIÓN | Antes de producción | El CRM no descarga ni trae logos en el código |
| 51 | Aviso de privacidad definitivo: mencionar el enlace de pago por correo (transaccional) y la felicitación por WhatsApp preparada por el personal con el consentimiento de comunicaciones | PENDIENTE DE LA INSTITUCIÓN | Antes de producción | Decisión vigente: WhatsApp usa `accepts_communications`, sin consentimiento aparte |
| 53 | Revisión legal del aviso publicado por el CRM (`/aviso-de-privacidad`): transferencias, plazos de conservación y procedimiento ARCO. Hoy usa textos generales y los datos que captura Administración (domicilio y correo de privacidad) | PENDIENTE DE LA INSTITUCIÓN | Recomendado antes de campañas amplias | No es un dictamen jurídico. Al cambiar el texto, volver a publicar para subir la versión |
| 52 | Pruebas manuales en Stripe Test del cobro asistido (enlace único y mensual, "Abrir pago ahora" y reintento) antes de Live | [S] Operativo | Antes de habilitar Live (#14) | La integración de Stripe no cambió; el enlace reutiliza `/donar` |

## Pendientes para producción

### De la institución

- **Mercado Pago:** cuenta institucional, aplicación, credenciales de prueba y luego productivas, webhook y validación (#15).
- **Correo:**
  - proveedor SMTP y sus datos, capturados en **Administración → Correo saliente**;
  - SPF, DKIM y DMARC publicados en el DNS del dominio remitente;
  - correo de prueba (#32).
- **Contabilidad:** activar a la o las destinatarias de los avisos contables en Usuarios → "Recibe avisos a Contabilidad" (#40).
- **Aviso de privacidad definitivo:**
  - responsable, domicilio y medio de contacto de privacidad;
  - procedimiento ARCO (#6);
  - transferencias;
  - plazos de conservación (#5);
  - URL pública y versión, capturadas en Organización (`docs/privacidad/aviso-privacidad-contenido-funcional.md`).
- **Dominio y DNS:** dominios definitivos del panel y de la página pública y acceso al DNS (#2).
- **Stripe:** cuenta y credenciales institucionales Live y configuración productiva (#14); Test ya validado.
- **Identidad:** subir el logotipo oficial en Organización (#50).

### Técnicos (nuestros)

- Respaldo externo S3-compatible de PostgreSQL y del volumen `crm-storage`, más una prueba de restauración (#4).
- Despliegue en Coolify con los 5 recursos (#3).
- HTTPS y dominio cuando la institución los entregue (#2, #3).
- Smoke final de producción: donativo en línea, recibo, agradecimiento, aviso contable, correo de prueba y respaldo.
