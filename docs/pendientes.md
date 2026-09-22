# Pendientes

| # | Pendiente | Tipo | Fase objetivo | Notas |
|---|---|---|---|---|
| 1 | Revisar en GitHub → Actions el workflow **CI** (jobs "Pint, Larastan y Pest" y "Construir imagen de producción") en el commit de cierre de la Fase 0 | PENDIENTE EXTERNO (lo revisa el responsable) | Después del push | El push requiere autorización explícita |
| 2 | Definir el servidor Coolify y los dominios definitivos (`crm.fdonbosco.org` y `donar.fdonbosco.org` son propuestas) | PENDIENTE EXTERNO | Antes del primer despliegue | Sin servidor no se puede ejecutar `docs/tecnico/despliegue-coolify.md` |
| 3 | Primer despliegue real: recursos, variables, HTTPS, volumen `crm-storage`, `app:create-admin`, verificación y prueba de rollback | PENDIENTE EXTERNO | Antes del primer despliegue | Depende de #2 |
| 4 | Respaldos automáticos de PostgreSQL y del volumen `crm-storage` (S3 externo) y una prueba de restauración | PENDIENTE EXTERNO | Antes de la Fase 2 (recomendado) | Ver `docs/tecnico/despliegue-coolify.md` §9 |
| 5 | Habilitar el panel a Coordinador, Contador y Solo lectura, con las Policies de sus módulos | Técnico | Fase de los primeros módulos que usen esos roles | ADR-002. Hoy solo el Administrador entra |
| 6 | Pantalla de administración de usuarios y roles | Técnico | Fase que construya el módulo de usuarios | Mientras tanto, los administradores se crean con `app:create-admin` |
| 7 | Reducir el tamaño de la imagen `prod` (~1.1 GB) separando las herramientas de compilación | Técnico (mejora) | Refactorización posterior | No afecta el funcionamiento |
