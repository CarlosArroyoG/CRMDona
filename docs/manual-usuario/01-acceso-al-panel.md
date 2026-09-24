# Acceso al panel del CRM

## Dirección

- Producción: `https://<dominio>/admin` (mismo dominio que `/donar` y `/up`; aún no está publicado).
- Pruebas en la computadora de desarrollo: `http://localhost:8000/admin`.

## Iniciar sesión

1. Abre la dirección del panel. Si no has iniciado sesión verás la pantalla **Entre a su cuenta**.
2. Escribe tu **correo electrónico** y tu **contraseña**.
3. Presiona **Entrar**.
4. Escribe el **código de 6 dígitos** que muestra tu aplicación autenticadora y presiona **Entrar**
   otra vez (ver la sección siguiente).

## Verificación en dos pasos (obligatoria para todos)

Para entrar al panel se piden dos cosas: tu contraseña y un código de 6 dígitos que cambia cada
30 segundos en una **aplicación autenticadora** de tu celular. Así, aunque alguien conozca tu
contraseña, no puede entrar sin tu celular. Aplica a los cuatro roles.

Sirve cualquier aplicación autenticadora estándar, por ejemplo Google Authenticator, Microsoft
Authenticator o Authy. Instálala en tu celular antes de empezar.

### Primera configuración

La primera vez que entres (o si la verificación estaba apagada) el CRM te lleva a la pantalla de
configuración y no te deja usar otras pantallas hasta terminar.

1. En **Aplicación de autenticación** pulsa **Configurar**.
2. **Escanea el código QR** con tu aplicación autenticadora. Si no puedes escanearlo, escribe en la
   aplicación la clave de texto que aparece debajo del QR.
3. **Guarda los códigos de recuperación** que aparecen en pantalla (ver abajo). Puedes copiarlos o
   descargarlos.
4. Escribe el **código de 6 dígitos** que muestra tu aplicación y pulsa **Habilitar aplicación de
   autenticación**.
5. Pulsa **Continuar**.

Si tu contraseña es temporal, primero configuras la verificación y después cambias la contraseña.

### Códigos de recuperación

Son códigos de un solo uso para entrar si no tienes tu celular a la mano.

- **Guárdalos fuera del CRM**: en un gestor de contraseñas o impresos en un lugar seguro. Nunca en
  el mismo celular de la aplicación autenticadora, ni en un correo o chat.
- Para usarlos: en la pantalla del código pulsa **Use un código de recuperación en su lugar**, escribe
  uno y presiona **Entrar**. Cada código sirve **una sola vez**.
- Si te quedan pocos, entra a tu perfil (tu nombre, arriba a la derecha → **Cambiar contraseña**) y
  en **Aplicación de autenticación** pulsa **Regenerar códigos de recuperación**. Los anteriores
  dejan de servir.

### Si pierdes o cambias de celular

- **Si todavía tienes códigos de recuperación:**
  1. Entra con uno de ellos.
  2. En tu perfil, en **Aplicación de autenticación**, pulsa **Apagar** y confirma con otro código
     de recuperación.
  3. El CRM te pedirá de inmediato configurar la verificación de nuevo: escanea el QR nuevo con tu
     celular nuevo y guarda los códigos nuevos.
- **Si perdiste el celular y también los códigos de recuperación:** no hay forma de entrar a esa
  cuenta. Restablecer la contraseña no quita la verificación en dos pasos, y el CRM no permite que
  otra persona la apague. Avisa de inmediato al Administrador: puede **desactivar** tu usuario en
  **Usuarios** para protegerlo. El procedimiento para recuperar esa cuenta está pendiente.
- **Si es el único Administrador**, el equipo técnico puede crear otro Administrador desde el
  servidor con el comando `app:create-admin` (ver `docs/tecnico/administrador-inicial.md`).

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

## Contraseña temporal (restablecida por el Administrador)

Si el correo de recuperación no te llega, pide al Administrador que **restablezca tu contraseña**:
te entregará una **contraseña temporal**. Tu verificación en dos pasos no cambia.

1. Entra con tu correo, la contraseña temporal y el código de tu aplicación autenticadora.
2. Verás directamente **Cambiar contraseña** con el aviso "Tu contraseña es temporal…". No podrás
   usar otras pantallas hasta terminar.
3. Escribe la temporal en **Contraseña actual** y elige una **Nueva contraseña** que solo tú
   conozcas (distinta de la temporal). Presiona **Guardar cambios**.

La contraseña temporal **vence en 72 horas**. Si vence verás "Tu contraseña temporal venció. Pide al
Administrador un nuevo restablecimiento." y tendrás que pedir otra.
