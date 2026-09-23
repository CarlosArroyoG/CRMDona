# Usuarios

**Para qué sirve:** dar acceso al personal y asignarle un rol.

**Quién:** solo el **Administrador**.

**Ruta:** menú **Administración → Usuarios**.

## Lista

| Columna | Significado |
|---|---|
| Nombre, Correo | Datos del usuario |
| Rol | Administrador, Coordinador de procuración de fondos, Contador o Solo lectura |
| Estado | Activo o Desactivado |

Filtros: **Rol** y **Estado**.

## Crear un usuario

Botón **Crear usuario**: nombre, correo, rol y **contraseña inicial** (dos veces). Mínimo 12
caracteres, con letras y números. Entrégala a la persona por un medio seguro y pídele que la cambie
en **Cambiar contraseña**. El sistema **nunca muestra** contraseñas guardadas.

## Cambiar rol o datos

Botón **Editar**: nombre, correo y rol. Cada persona tiene **un solo rol**.

## Restablecer la contraseña de otra persona

Cuando alguien olvidó su contraseña:

1. En la lista de usuarios (o al editarlo), presiona **Restablecer contraseña** →
   **Generar contraseña temporal**.
2. Aparece un aviso con la **contraseña temporal**. **Cópiala en ese momento: no se vuelve a
   mostrar** y nadie puede consultarla después.
3. Entrégala a la persona por un medio seguro (en persona o por teléfono; evita dejarla escrita).
4. Al entrar, la persona **solo podrá cambiar su contraseña**; hasta hacerlo no verá ninguna otra
   pantalla. Así tú nunca conoces su contraseña definitiva.

Detalles:

- La contraseña temporal **vence en 72 horas**. Si vence, genera otra; la anterior deja de servir.
- Se cierran las demás sesiones abiertas de esa persona.
- No cambia su rol ni su estado: si el usuario está **desactivado**, sigue sin poder entrar hasta
  que lo reactives.
- No puedes restablecer tu propia contraseña con este botón: usa **Cambiar contraseña**.
- Queda registrado en la bitácora (sin la contraseña).

Si **ningún Administrador** puede entrar, el equipo técnico puede restablecer el acceso desde el
servidor.

## Desactivar o reactivar

- **Desactivar:** la persona ya no puede entrar. Su historial se conserva. Los usuarios no se
  eliminan.
- **Reactivar:** vuelve a entrar con su contraseña actual.

No puedes desactivarte a ti mismo, y siempre debe quedar al menos un Administrador activo.

## Errores frecuentes

| Mensaje | Qué hacer |
|---|---|
| "Ese correo ya pertenece a un usuario…" | Usa otro correo o edita el usuario existente |
| "El campo contraseña debe tener al menos 12 caracteres." / "…al menos un número." | Usa una contraseña más larga con letras y números |
| "Debe quedar al menos un Administrador activo." | Crea o activa otro Administrador antes |
| "No puedes desactivar tu propio usuario." | Pide a otro Administrador que lo haga |
