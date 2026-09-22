# ADR-002 — Roles y autorización

- **Estado:** Aceptado
- **Fecha:** 2026-09-22

## Contexto

El CRM tiene cuatro roles oficiales (definidos en el prompt maestro):

| Rol (interfaz) | Valor guardado (`users.role`) | Caso del enum |
|---|---|---|
| Administrador | `administrator` | `Role::Administrator` |
| Coordinador de procuración de fondos | `fundraising_coordinator` | `Role::FundraisingCoordinator` |
| Contador | `accountant` | `Role::Accountant` |
| Solo lectura | `read_only` | `Role::ReadOnly` |

Una versión anterior de `CLAUDE.md` mencionaba los roles "Gestores" y "Voluntario recolector", y
decía que no existía el rol Contador. Eso quedó **sin efecto**.

Cada usuario tiene un solo rol. En la Fase 0 todavía no existen módulos de negocio.

## Decisión

1. **Columna `role` en `users`** (texto, nulo permitido, indexada) con los valores del enum
   propio `App\Enums\Role`. Los valores guardados están en inglés; las etiquetas en español
   salen de `Role::getLabel()`. Un usuario sin rol no tiene acceso.
2. **Sin paquetes de permisos** (no se instala `spatie/laravel-permission`).
3. **Policies de Laravel** para autorizar cada módulo. Cada Policy se crea **junto con su
   módulo**, en la fase que lo construya; no se crean Policies para módulos que aún no existen.
4. **Acceso al panel:** `User` implementa `FilamentUser`, y `canAccessPanel()` delega en
   `Role::canAccessPanel()`. Por ahora solo `Administrator` devuelve `true`. Los demás roles se
   habilitan en la fase que construya los primeros módulos que usarán, junto con sus Policies.
5. `role` **no es asignable en masa** (no está en `#[Fillable]`); se asigna de forma explícita
   (por ejemplo, en `App\Actions\Users\CreateAdministrator`).

## Consecuencias

- Simple de entender, sin tablas extra ni caché de permisos.
- Las Policies consultan `$user->role`; así la regla de cada módulo vive en un solo lugar y
  tiene pruebas.
- **Migración futura:** si aparecen requisitos reales de varios roles por usuario o permisos
  granulares configurables desde la interfaz, se reemplaza la columna por tablas de roles y
  permisos (o un paquete, con su propio ADR). Como toda la autorización pasa por Policies y por
  `canAccessPanel()`, el cambio queda en esos puntos y no en controladores ni Resources.
