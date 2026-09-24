# Acceso al panel del CRM

## Dirección

- Producción: `https://crm.fdonbosco.org/admin` (dominio propuesto; aún no está publicado).
- Pruebas en la computadora de desarrollo: `http://localhost:8000/admin`.

## Iniciar sesión

1. Abre la dirección del panel. Si no has iniciado sesión verás la pantalla **Entre a su cuenta**.
2. Escribe tu **correo electrónico** y tu **contraseña**.
3. Presiona **Entrar**.

## ¿Quién puede entrar?

Cualquier persona con usuario **activo** y uno de los cuatro roles: Administrador, Coordinador de
procuración de fondos, Contador o Solo lectura. Lo que cada rol puede hacer está en el
[índice del manual](README.md).

## Olvidé mi contraseña

1. En la pantalla de entrada pulsa **¿Olvidaste tu contraseña?**.
2. Escribe tu correo y pulsa el botón para enviar el enlace.
3. Abre el correo y sigue el enlace para elegir una contraseña nueva (mínimo 12 caracteres, con letras y números).

Si no llega, revisa el spam o pide al Administrador que restablezca tu contraseña desde **Usuarios**. El correo sale por el servidor configurado en **Correo saliente**.

## Cambiar tu contraseña

1. Haz clic en tu nombre o iniciales (esquina superior derecha) → **Cambiar contraseña**.
2. Escribe tu **Contraseña actual**.
3. Escribe la **Nueva contraseña** y repítela en **Confirmar nueva contraseña**.
4. Presiona **Guardar cambios**. Verás el aviso "Contraseña actualizada".

La nueva contraseña debe tener **mínimo 12 caracteres, con letras y números**. Ejemplo de
estructura válida: dos palabras y un número, como "girasol-azul-2026" (no uses este ejemplo).

## Errores frecuentes

| Mensaje | Qué pasa | Qué hacer |
|---|---|---|
| "Estas credenciales no coinciden con nuestros registros." | Correo o contraseña incorrectos | Revisa el correo y vuelve a intentarlo |
| "Demasiados intentos…" | Varios intentos fallidos seguidos | Espera los segundos indicados |
| Página de **acceso prohibido (403)** | Tu usuario está desactivado o no tiene rol | Pide al Administrador que lo revise |
| "La contraseña es incorrecta." (al cambiarla) | La contraseña actual no coincide | Escríbela de nuevo con cuidado |

## Olvidé mi contraseña

Todavía no hay recuperación por correo. Pide al Administrador que **restablezca tu contraseña**: te
entregará una **contraseña temporal**.

1. Entra con tu correo y la contraseña temporal.
2. Verás directamente **Cambiar contraseña** con el aviso "Tu contraseña es temporal…". No podrás
   usar otras pantallas hasta terminar.
3. Escribe la temporal en **Contraseña actual** y elige una **Nueva contraseña** que solo tú
   conozcas (distinta de la temporal). Presiona **Guardar cambios**.

La contraseña temporal **vence en 72 horas**. Si vence verás "Tu contraseña temporal venció. Pide al
Administrador un nuevo restablecimiento." y tendrás que pedir otra.
