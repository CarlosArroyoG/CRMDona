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
