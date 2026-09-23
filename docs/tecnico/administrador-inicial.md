# Crear el administrador inicial

El primer usuario con rol **Administrador** se crea con un comando interactivo. La contraseña
se escribe en la terminal sin que se vea y nunca se guarda en git, `.env`, seeders,
documentación ni registros: Laravel solo guarda su hash.

## Cuándo usarlo

- Una vez, después del primer `migrate` en cada entorno (local o producción).
- Si hace falta otro administrador más adelante (mientras no exista la pantalla de usuarios).

## Cómo

| Entorno | Comando |
|---|---|
| Local (Docker Compose) | `docker compose exec app php artisan app:create-admin` |
| Producción (Coolify) | Recurso `app` → *Terminal* → `php artisan app:create-admin` |

El comando pide:

1. **Nombre.**
2. **Correo electrónico.** Propone `licarroyogarfias@gmail.com`; Enter para aceptarlo.
3. **Contraseña** (oculta): mínimo 12 caracteres, con letras y números.
4. **Confirmación** de la contraseña (oculta).

## Reglas

- El correo se guarda en minúsculas y debe ser válido.
- **Si el correo ya existe, el comando falla** y no modifica a ese usuario (no lo convierte en
  administrador).
- Si algún dato no es válido, el comando muestra el motivo en español, no crea nada y termina
  con código de salida 1.
- La contraseña no aparece en la salida del comando, ni siquiera cuando hay errores.

## Código

- Lógica y validación: `app/Actions/Users/CreateAdministrator.php`.
- Comando (solo captura datos y muestra el resultado): `app/Console/Commands/CreateAdminCommand.php`.
- Pruebas: `tests/Feature/Users/CreateAdministratorTest.php` y
  `tests/Feature/Console/CreateAdminCommandTest.php`.

## Recuperar el acceso de un usuario (sin otro Administrador disponible)

Si un usuario olvidó su contraseña, lo normal es que un Administrador use **Usuarios → Restablecer
contraseña** en el panel (ADR-010). Si **no hay ningún Administrador que pueda entrar**, desde la
terminal del servidor:

| Entorno | Comando |
|---|---|
| Local | `docker compose exec app php artisan app:reset-user-password` |
| Producción (Coolify) | Recurso `app` → *Terminal* → `php artisan app:reset-user-password` |

El comando:

1. Pide el **correo** del usuario (debe existir; **no crea usuarios**).
2. Muestra nombre, rol y estado, y pide **confirmación**.
3. Pide la **nueva contraseña** dos veces, sin mostrarla (mínimo 12 caracteres, con letras y números).
4. La deja como **contraseña temporal**: al entrar, el usuario debe cambiarla por una propia, y vence
   a las 72 horas (`AUTH_TEMPORARY_PASSWORD_TTL_HOURS`). Así quien opera el servidor no conoce la
   contraseña definitiva.

No cambia el rol ni reactiva a un usuario desactivado. Queda en la bitácora como
"Restablecimiento de contraseña" (sin la contraseña).
