# Acceso al panel del CRM

## Dirección

- Producción: `https://crm.fdonbosco.org/admin` (dominio propuesto; aún no está publicado).
- Pruebas en la computadora de desarrollo: `http://localhost:8000/admin`.

## Iniciar sesión

1. Abre la dirección del panel. Si no has iniciado sesión, verás la pantalla **Entre a su cuenta**.
2. Escribe tu **correo electrónico** y tu **contraseña**.
3. Presiona **Entrar**.

Si los datos no son correctos verás el mensaje *"Estas credenciales no coinciden con nuestros
registros."* Revisa que el correo esté bien escrito y vuelve a intentarlo. Tras varios intentos
fallidos, el sistema te pedirá esperar unos segundos.

## ¿Quién puede entrar?

Cada persona tiene un **rol**:

| Rol | Acceso al panel hoy |
|---|---|
| Administrador | Sí |
| Coordinador de procuración de fondos | Se habilitará cuando existan sus módulos |
| Contador | Se habilitará cuando existan sus módulos |
| Solo lectura | Se habilitará cuando existan sus módulos |

Si tu usuario todavía no tiene acceso, verás un mensaje de **acceso prohibido (403)**.
Pide al Administrador que revise tu rol.

## Primer administrador

El primer Administrador lo crea el equipo técnico desde el servidor; su contraseña la escribe
la propia persona en ese momento y nadie más la conoce. Si la olvidas, contacta al equipo técnico.
