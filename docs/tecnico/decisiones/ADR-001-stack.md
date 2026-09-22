# ADR-001 — Stack tecnológico

- **Estado:** Aceptado
- **Fecha:** 2026-09-22

## Contexto

La Fundación Don Bosco necesita un CRM para gestionar donantes, donativos, pagos en línea y
recibos deducibles (CFDI 4.0 con complemento de donatarias). Lo mantendrá un equipo pequeño,
por lo que el sistema debe ser fácil de actualizar y tener pocas dependencias.

## Decisión

| Capa | Elección |
|---|---|
| Framework | Laravel (última versión estable) |
| Lenguaje | PHP (última versión soportada por Laravel y Filament) |
| Panel interno | Filament (última versión mayor estable) |
| Página pública | Blade + Tailwind CSS en el mismo proyecto |
| Base de datos | PostgreSQL |
| Colas y caché | Redis |
| Pruebas | Pest |
| Calidad | Laravel Pint y Larastan (nivel 8 o superior) |
| Contenedores | Dockerfile propio + `docker-compose.yml` para desarrollo |
| Despliegue | Coolify con 5 recursos: `app`, `worker`, `scheduler`, `postgres`, `redis` |
| CI | GitHub Actions (Pint, Larastan, Pest) |
| Entorno local | Docker Desktop en Windows 11 (el motor instalado usa la VM libkrun, no WSL2) |

Las versiones exactas instaladas se registran en `CHANGELOG.md` al crear el proyecto.

### Paquetes del esqueleto de Laravel (revisión del 2026-09-22)

| Paquete | Decisión | Motivo |
|---|---|---|
| `laravel/tinker` | Se conserva | Consola para diagnóstico en desarrollo y en la terminal de Coolify |
| `laravel/pail` (dev) | Se conserva | Ver registros en vivo durante el desarrollo |
| `laravel/pao` (dev) | Eliminado | Salida de pruebas para agentes; nadie lo usaba |
| `concurrently`, `@laravel/multiplex` (npm) | Eliminados | Solo los usaba el script `composer dev`, que suponía PHP y npm en el equipo |
| Scripts `composer setup` y `composer dev` | Eliminados | Suponían PHP y npm instalados en el equipo; el flujo real es Docker |

Roles y autorización: ver ADR-002.

## Consecuencias

- Un solo proyecto y un solo lenguaje para panel, página pública, colas y tareas programadas.
- El entorno local usa las mismas imágenes que producción, lo que reduce las diferencias entre ambos.
- Cualquier paquete adicional necesita su propio ADR y la autorización del responsable del proyecto.
