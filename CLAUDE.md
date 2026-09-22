# CRM Donataria — Fundación Don Bosco

CRM para una donataria autorizada en México (Fundación Don Bosco, Cuernavaca, Morelos).
Cubre: felicitación de cumpleaños, pagos en línea únicos y recurrentes, reporte de pagos,
CFDI 4.0 con complemento de donatarias timbrado automáticamente y agradecimiento automático con recibo.

El documento de requisitos completo lo entregó el usuario al iniciar el proyecto; este archivo es la memoria operativa.

## Estado actual

- **Fase 0 — Cimientos: cerrada** (2026-09-22), validada con Docker Compose real. Ver `docs/fases/FASE-00-resumen.md`.
- **Siguiente:** Fase 1, solo con aprobación explícita del usuario.
- Docker Desktop con motor libkrun (no WSL2). La carpeta del proyecto se comparte mediante
  `FilesharingDirectories` en `%APPDATA%\Docker\settings-store.json` (la interfaz no lo guardaba).
- Pruebas: siempre en `crm_testing`. `tests/TestCase.php` las detiene si apuntan a otra base.
- Repositorio remoto: https://github.com/CarlosArroyoG/CRMDona (`origin`). Push, merge y rebase solo con autorización explícita.
- Resúmenes de fases: `docs/fases/`.

## Stack

- Laravel (última estable) + PHP (última soportada por Laravel y Filament).
- Filament (última mayor estable) para el CRM interno.
- Blade + Tailwind CSS para la página pública de donación.
- PostgreSQL (datos) y Redis (colas y caché).
- Pest (pruebas), Laravel Pint (formato), Larastan nivel ≥ 8.
- Docker (Dockerfile propio) para Coolify con 5 recursos: `app`, `worker`, `scheduler`, `postgres`, `redis`.
- GitHub Actions: Pint, Larastan, Pest y construcción de la imagen `prod` en cada push.
- Del esqueleto de Laravel se conservan `laravel/tinker` y `laravel/pail` (ADR-001).
- Cualquier paquete fuera de esta lista requiere ADR y autorización del usuario.

## Datos de la organización

- Nombre corto: Fundación Don Bosco — sitio: https://www.fdonbosco.org/
- Colores: primario `#162562` (azul marino), secundario `#FF9D2F` (naranja). Configurables.
- Logo: https://www.fdonbosco.org/theme/img/logo.png (configurable).
- Dominios propuestos: `crm.fdonbosco.org` (panel) y `donar.fdonbosco.org` (página pública). Servidor Coolify: por definir.
- Administrador inicial: `licarroyogarfias@gmail.com`. **La contraseña nunca se escribe en código, seeders, `.env.example` ni git**; se captura con comando interactivo.

## Roles y autorización (ADR-002)

Roles oficiales, según el prompt maestro original, que es la fuente de verdad:

| Rol | Valor en `users.role` | Enum |
|---|---|---|
| Administrador | `administrator` | `Role::Administrator` |
| Coordinador de procuración de fondos | `fundraising_coordinator` | `Role::FundraisingCoordinator` |
| Contador | `accountant` | `Role::Accountant` |
| Solo lectura | `read_only` | `Role::ReadOnly` |

- Un rol por usuario: columna `users.role` + enum `App\Enums\Role`, sin paquetes de permisos.
- Autorización con **Policies de Laravel**, cada una creada junto con su módulo (nunca antes).
- Acceso al panel: `User::canAccessPanel()` → `Role::canAccessPanel()`. Hoy solo Administrador;
  los demás roles se habilitan en la fase de sus primeros módulos.
- Los permisos por módulo de cada rol se toman del prompt maestro al construir cada módulo.
- Quedan sin efecto los roles "Gestores" y "Voluntario recolector" de versiones anteriores de este archivo.

## Convenciones

- Código, clases, tablas y columnas en **inglés**. Interfaz, textos, correos y documentación en **español de México**.
- Idioma `es` (textos en `lang/es`), formato regional `es_MX`, zona horaria `America/Mexico_City`, moneda `MXN`.
- Montos: nunca `float`/`double`; columnas `decimal`/`numeric` en PostgreSQL.
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
- `docker compose exec app php artisan app:create-admin` — crea un administrador (contraseña oculta).
- `docker compose exec app vendor/bin/pest` — pruebas (usan la base `crm_testing`).
- `docker compose exec app vendor/bin/pint` — formato.
- `docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G` — Larastan.
- `docker build --target prod -t crm-donataria:prod .` — imagen de producción (Apache en el puerto 8080).
