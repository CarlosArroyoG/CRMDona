# CRM Donataria — Fundación Don Bosco

CRM para una donataria autorizada en México (Fundación Don Bosco, Cuernavaca, Morelos).
Cubre: felicitación de cumpleaños, pagos en línea únicos y recurrentes, reporte de pagos,
CFDI 4.0 con complemento de donatarias timbrado automáticamente y agradecimiento automático con recibo.

El documento de requisitos completo lo entregó el usuario al iniciar el proyecto; este archivo es la memoria operativa.

## Estado actual

- **Fase 0 — Cimientos: cerrada** (2026-09-22). Ver `docs/fases/FASE-00-resumen.md`.
- **Fase 1 — Núcleo del CRM: implementada y validada localmente** (2026-09-22); **pendiente de
  aprobación del usuario**. Ver `docs/fases/FASE-01-resumen.md`.
- **Siguiente:** Fase 2 (pasarela de pagos), solo con aprobación explícita. Antes preguntar la pasarela.
- Modelo de datos y reglas: `docs/tecnico/modelo-de-datos.md` y ADR-002 a ADR-009.
- Decisiones fiscales pendientes (uso de CFDI, régimen, especie): `docs/pendientes.md`. No codificarlas sin confirmación.
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
- **Matriz única** en `App\Enums\Permission` (tabla aprobada en ADR-002 y en
  `tests/Unit/Enums/PermissionMatrixTest.php`); Policies por módulo la consultan y agregan reglas de estado.
- Acceso al panel: `User::canAccessPanel()` = tiene rol y está activo (los cuatro roles entran).
- Módulos nuevos: agregar sus permisos a la matriz y su Policy junto con el módulo.
- Quedan sin efecto los roles "Gestores" y "Voluntario recolector" de versiones anteriores de este archivo.

## Reglas de dominio (Fase 1)

- `Donation` ≠ `Payment` ≠ `DonationReceipt` ≠ `Cfdi` (ADR-005). Las fases futuras agregan tablas propias que apuntan a `donations`.
- Donativos: siempre nacen `pending`; `confirmed` no se edita; errores → cancelar con motivo. Nunca se eliminan (trigger).
- Destino único del donativo: campaña, programa o fondo general. Programa para reportes = directo o el de la campaña.
- Auditoría: cada modelo declara `auditValueFields()` y `auditNameOnlyFields()`; datos personales/fiscales sin valor (ADR-006).
- Escribir datos de negocio solo mediante `app/Actions` (los `update` masivos no se auditan).
- Búsquedas de texto con `App\Support\Search::unaccent()` (lista cerrada de columnas).

## Requisitos para fases futuras (no perder)

Detalle completo en `docs/tecnico/requisitos-fases-futuras.md`. Leerlo antes de diseñar la Fase 2.

- Separación obligatoria: `Donation ≠ Payment ≠ PaymentAttempt ≠ Subscription ≠ DonationReceipt ≠ Cfdi`.
  Método de pago en línea, marca, últimos 4 dígitos y códigos de rechazo pertenecen al pago/intento, nunca al donativo.
- **RF-01 — Alertas de pagos y donativos con problemas** (diseño en Fase 2, correo en la fase de comunicaciones):
  - Flujo: `PaymentAttempt → fallo → registro persistente → incidencia → notificación CRM → correo → seguimiento`. El CRM es la fuente de verdad; el correo solo avisa.
  - Incidencias `new → reviewing → resolved` (Nueva → En revisión → Resuelta) con causa, pago/intento, quién y cuándo revisó/resolvió, notas y resolución. Leer ≠ resolver.
  - Destinatarios por **usuarios/roles** (mínimo Administrador; solución mínima); nunca correos en el código.
  - Webhook inbox con identificador externo único: un reintento no duplica pago, donativo, incidencia ni alerta; un intento nuevo sí se registra.
  - Fallos normalizados (categoría interna) + código del proveedor + mensaje sanitizado, por separado. Nunca PAN, CVV, banda ni datos de autenticación.
  - Recurrentes: un intento fallido no cancela la suscripción; historial completo de intentos.
- **Sin migrar antes de aprobar el diseño de la Fase 2:** origen manual/automático del donativo; actores humano vs sistema
  (**sin "usuario sistema" ficticio**); significado de `donations.payment_method` (no agregar métodos en línea por suposición);
  reembolsos (**no** equivalen a cancelar; nunca borrar pago ni donativo).

## Convenciones

- Código, clases, tablas y columnas en **inglés**. Interfaz, textos, correos y documentación en **español de México**.
- Idioma `es` (textos en `lang/es`), formato regional `es_MX`, zona horaria `America/Mexico_City`, moneda `MXN`.
- Montos: nunca `float`/`double`; `numeric(12,2)` en PostgreSQL, strings + bcmath en PHP (`App\Support\Money`, ADR-004).
- Los CHECK de PostgreSQL que deben fallar se prueban dentro de `DB::transaction()` (savepoint).
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
- `docker compose exec -e DB_DATABASE=crm_validation app php artisan migrate:fresh --seed` — datos de demostración en una base desechable (`migrate:fresh` en `crm` borra tu administrador local).
- `docker compose exec app vendor/bin/pest` — pruebas (usan la base `crm_testing`).
- `docker compose exec app vendor/bin/pint` — formato.
- `docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G` — Larastan.
- `docker build --target prod -t crm-donataria:prod .` — imagen de producción (Apache en el puerto 8080).
