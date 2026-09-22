# CRM Donataria — Fundación Don Bosco

CRM para una donataria autorizada en México (Fundación Don Bosco, Cuernavaca, Morelos).
Cubre: felicitación de cumpleaños, pagos en línea únicos y recurrentes, reporte de pagos,
CFDI 4.0 con complemento de donatarias timbrado automáticamente y agradecimiento automático con recibo.

El documento de requisitos completo lo entregó el usuario al iniciar el proyecto; este archivo es la memoria operativa.

## Estado actual

- **Fase en curso:** Fase 0 — Cimientos (en progreso).
- Esqueleto listo: Laravel 13 + Filament 5 + Pest + Larastan 8, Docker `dev`/`prod`, CI. Versiones en `docs/CHANGELOG.md`.
- Docker Desktop instalado (VM libkrun, no WSL2). **Falta compartir la carpeta del proyecto** en
  Settings → Resources → File sharing; mientras tanto se verifica pasando el código por `tar` a un contenedor.
- Repositorio remoto: https://github.com/CarlosArroyoG/CRMDona (`origin`).
- Resúmenes de fases cerradas: `docs/fases/`.

## Stack

- Laravel (última estable) + PHP (última soportada por Laravel y Filament).
- Filament (última mayor estable) para el CRM interno.
- Blade + Tailwind CSS para la página pública de donación.
- PostgreSQL (datos) y Redis (colas y caché).
- Pest (pruebas), Laravel Pint (formato), Larastan nivel ≥ 8.
- Docker (Dockerfile propio) para Coolify con 5 recursos: `app`, `worker`, `scheduler`, `postgres`, `redis`.
- GitHub Actions: Pint, Larastan y Pest en cada push.
- Cualquier paquete fuera de esta lista requiere ADR y autorización del usuario.

## Datos de la organización

- Nombre corto: Fundación Don Bosco — sitio: https://www.fdonbosco.org/
- Colores: primario `#162562` (azul marino), secundario `#FF9D2F` (naranja). Configurables.
- Logo: https://www.fdonbosco.org/theme/img/logo.png (configurable).
- Dominios propuestos: `crm.fdonbosco.org` (panel) y `donar.fdonbosco.org` (página pública). Servidor Coolify: por definir.
- Administrador inicial: `licarroyogarfias@gmail.com`. **La contraseña nunca se escribe en código, seeders, `.env.example` ni git**; se captura con comando interactivo.

## Roles y permisos (aprobados)

| Módulo | Administrador | Gestores | Voluntario recolector |
|---|---|---|---|
| Usuarios, roles, configuración | Todo | — | — |
| Donantes | Todo | Crear, editar, ver | Crear; ver solo los que registró (datos personales solo de esos) |
| Campañas | Todo | Crear, editar, ver | Solo ver |
| Donativos manuales | Todo | Todo excepto borrar | Registrar; ver solo los suyos |
| Pagos, suscripciones, reembolsos | Todo | Ver; pausar/cancelar suscripciones | — |
| CFDI | Todo | Reenviar (no cancelar) | — |
| Reportes y tablero | Sí | Sí | — |
| Plantillas de correo | Sí | Sí | — |
| Bitácora de auditoría | Sí | — | — |

No existe rol Contador: el reporte de CFDI (Fase 5) lo ven Administrador y Gestores.
Implementación con Policies de Laravel.

## Convenciones

- Código, clases, tablas y columnas en **inglés**. Interfaz, textos, correos y documentación en **español de México**.
- Locale `es_MX`, zona horaria `America/Mexico_City`, moneda `MXN`.
- Commits en español con prefijos `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `chore:`.
- Repositorio: https://github.com/CarlosArroyoG/CRMDona.

## Reglas contra la deuda técnica

1. Nada de código muerto ni `TODO` sueltos; lo pendiente va en `docs/pendientes.md` con su fase objetivo.
2. Cada funcionalidad lleva pruebas (feature para flujos, unit para dominio). Nada se cierra en rojo.
3. Pint, Larastan y Pest al 100% antes de cerrar una fase.
4. Lógica de negocio en `app/Actions`, servicios y Jobs; nunca en controladores ni Filament Resources.
5. Integraciones externas detrás de interfaces (`PaymentGateway`, `CfdiProvider`…) con driver real y driver fake. Las pruebas nunca llaman APIs reales.
6. Idempotencia en todo lo externo (webhooks, timbrado).
7. Secretos solo en variables de entorno; `.env.example` siempre actualizado y documentado.
8. Migraciones reversibles; seeders con datos de demostración realistas y ficticios.
9. Reutilizar lo existente; si se cambia, refactorizar y documentar por qué.
10. Refactorización al final de cada fase.

## Forma de trabajar por fases

- Al iniciar una fase: leer este archivo y el último `docs/fases/FASE-XX-resumen.md`.
- Al cerrarla: cumplir la Definición de terminado, escribir el resumen de fase, actualizar `CHANGELOG.md` y este archivo, mostrar el resumen y **esperar aprobación**.
- Antes de la Fase 2 preguntar la pasarela de pago; antes de la Fase 3, el PAC.
- Decisiones de negocio ambiguas: preguntar, no asumir.

## Comandos útiles

No hay PHP ni Composer en el equipo: todo corre en Docker. Detalle en `docs/tecnico/entorno-local.md`.

- `docker compose up -d` — levanta app (http://localhost:8000), worker, scheduler, postgres y redis.
- `docker compose exec app vendor/bin/pest` — pruebas (usan la base `crm_testing`).
- `docker compose exec app vendor/bin/pint` — formato.
- `docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G` — Larastan.
- `docker build --target prod -t crm-donataria:prod .` — imagen de producción (Apache en el puerto 8080).
