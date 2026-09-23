# ADR-002 — Roles y autorización

- **Estado:** Aceptado (actualizado en la Fase 1)
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

Cada usuario tiene un solo rol.

## Decisión

1. **Columna `role` en `users`** con los valores del enum propio `App\Enums\Role`. Sin paquetes
   de permisos. Un usuario sin rol no tiene acceso.
2. **Matriz única de permisos** en `App\Enums\Permission`: cada permiso sabe qué roles lo tienen
   (`Permission::roles()`). `User::hasPermission()` la consulta y además exige que el usuario esté
   activo.
3. **Policies de Laravel** por módulo (`app/Policies`). Consultan la matriz y agregan reglas de
   estado (por ejemplo, solo se edita un donativo "Por confirmar"). No hay `Gate::before`: el
   Administrador aparece explícitamente en la matriz.
4. **Acceso al panel:** `User::canAccessPanel()` = tiene rol **y** está activo. Desde la Fase 1
   entran los cuatro roles.
5. `role` y `deactivated_at` **no son asignables en masa**; los cambian solo las Actions de
   usuarios. Siempre debe quedar al menos un Administrador activo.

## Matriz (Fase 1)

A = Administrador · C = Coordinador de procuración de fondos · Co = Contador · L = Solo lectura

| Permiso (`Permission`) | A | C | Co | L |
|---|---|---|---|---|
| Ver donantes (`donors.view`) | ✔ | ✔ | ✔ | ✔ |
| Crear, editar y archivar donantes (`donors.manage`) | ✔ | ✔ | — | — |
| Eliminar donantes sin donativos (`donors.delete`) | ✔ | — | — | — |
| Ver y editar datos fiscales (`donors.tax_profile`) | ✔ | ✔ | ✔ | — |
| Exportar donantes (`donors.export`) | ✔ | ✔ | ✔ | — |
| Crear etiquetas (`tags.manage`) | ✔ | ✔ | — | — |
| Ver y exportar programas y campañas | ✔ | ✔ | ✔ | ✔ |
| Crear, editar y archivar programas y campañas | ✔ | ✔ | — | — |
| Eliminar programas y campañas sin donativos | ✔ | — | — | — |
| Ver donativos (`donations.view`) | ✔ | ✔ | ✔ | ✔ |
| Registrar y editar donativos "Por confirmar" (`donations.register`) | ✔ | ✔ | ✔ | — |
| Confirmar y cancelar donativos (`donations.confirm`) | ✔ | — | ✔ | — |
| Exportar donativos (`donations.export`) | ✔ | ✔ | ✔ | — |
| Ver configuración de la organización | ✔ | — | ✔ | — |
| Editar configuración de la organización | ✔ | — | — | — |
| Bitácora de auditoría | ✔ | — | — | — |
| Usuarios, incluido restablecer contraseñas de otros (`users.manage`) | ✔ | — | — | — |

Todos los roles pueden cambiar su propia contraseña; mientras tengan una contraseña temporal no
ejercen ningún permiso (ADR-010). Nadie elimina donativos ni registros de la
bitácora (ADR-008). La prueba `tests/Unit/Enums/PermissionMatrixTest.php` compara el código con
esta tabla: cambiarla exige cambiar ambas a propósito.

## Consecuencias

- Una sola fuente de verdad, fácil de revisar y probar.
- **Migración futura:** si aparecen varios roles por usuario o permisos configurables desde la
  interfaz, se reemplaza `Permission::roles()` por tablas de roles y permisos (o un paquete, con su
  propio ADR). Como toda la autorización pasa por `hasPermission()` y las Policies, el cambio queda
  en esos puntos.
