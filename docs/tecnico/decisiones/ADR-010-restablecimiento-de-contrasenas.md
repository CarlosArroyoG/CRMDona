# ADR-010 — Restablecimiento de contraseñas

- **Estado:** Aceptado
- **Fecha:** 2026-09-22

## Contexto

Un usuario que olvida su contraseña no tenía forma de recuperar el acceso. La recuperación por
correo queda para la fase de comunicaciones.

## Decisión

1. **Desde el panel** (Usuarios → **Restablecer contraseña**), solo con el permiso `users.manage`
   (Administrador) y **nunca sobre uno mismo** (`UserPolicy::resetPassword`). Es independiente del
   cambio de rol: no toca rol ni estado.
2. La contraseña temporal la **genera el sistema** (`Str::password`, 16 caracteres con letras y
   números, criptográficamente aleatoria) y cumple `Password::defaults()`. Se **muestra una sola
   vez** en una notificación de pantalla, que no se guarda en la base; solo se persiste su hash.
3. **Estado temporal sin guardar secretos:** columna `users.password_change_required_at`.
   - Nula: contraseña definitiva.
   - Con fecha: la contraseña actual es temporal y se generó en ese momento.
   - Vence a las `auth.temporary_password_ttl_hours` horas (72 por defecto; variable
     `AUTH_TEMPORARY_PASSWORD_TTL_HOURS`).
   - Generar otra temporal reemplaza el hash y la fecha: la anterior deja de servir.
4. **Bloqueo efectivo:** el middleware `EnsurePasswordIsCurrent`, registrado como **persistente**
   en el panel (también aplica a las peticiones Livewire, que se abortan si redirige), solo permite
   las rutas `filament.admin.auth.profile` y `filament.admin.auth.logout`. Además,
   `User::hasPermission()` devuelve falso mientras la contraseña sea temporal (defensa en
   profundidad: Policies, recursos y la descarga de exportaciones también lo niegan).
5. **Temporal vencida:** el middleware cierra la sesión, la invalida y avisa: *"Tu contraseña
   temporal venció. Pide al Administrador un nuevo restablecimiento."*
6. **Cambio a contraseña propia** en "Cambiar contraseña": exige la contraseña actual, debe ser
   distinta de ella y, al guardar, pone `password_change_required_at` en nulo (un solo registro en
   la bitácora). El Administrador nunca conoce la contraseña definitiva.
7. **Sesiones:** al cambiar el hash, `AuthenticateSession` (ya incluido en el panel) cierra las
   demás sesiones del usuario; además se renueva `remember_token`.
8. **Recuperación sin Administrador disponible:** `php artisan app:reset-user-password` (terminal del
   servidor). Localiza al usuario por correo exacto, muestra nombre, rol y estado, pide
   confirmación y la contraseña oculta dos veces, y aplica la política. **No crea usuarios ni cambia
   rol o estado.** Decisión: también deja la contraseña como **temporal** (cambio obligatorio al
   entrar), para que quien opera el servidor no conozca la contraseña definitiva.
9. **Bitácora:** evento `password_reset` ("Restablecimiento de contraseña") con usuario objetivo,
   actor (nulo si fue la consola) y fecha. `password` solo se registra como nombre de campo; nunca
   la contraseña anterior, la temporal, la nueva ni el hash.
10. Un usuario **desactivado** sigue sin acceso aunque se restablezca su contraseña.

## Consecuencias

- Migración reversible (una columna), una Action (`ResetUserPassword`), un comando, un middleware,
  un permiso de Policy y un caso de `AuditEvent`. Sin dependencias nuevas.
- La contraseña temporal pasa por la sesión (cifrada, en Redis) solo durante la respuesta que la
  muestra.
