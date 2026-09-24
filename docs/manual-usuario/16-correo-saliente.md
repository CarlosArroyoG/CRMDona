# Correo saliente (solo Administrador)

Menú **Administración → Correo saliente**. Aquí se indica por qué servidor salen **todos** los correos
del CRM:

- agradecimientos y recibos;
- avisos a Contabilidad;
- cumpleaños;
- alertas;
- recuperación de contraseña.

Funciona con cualquier proveedor que ofrezca **SMTP**.

## Configurar

1. Pide a tu proveedor de correo estos datos:
   - servidor SMTP;
   - puerto;
   - tipo de seguridad;
   - usuario y contraseña.
2. Llena **Servidor SMTP** y **Remitente**.

   | Tipo | Puerto típico | Seguridad |
   |---|---|---|
   | SMTP STARTTLS | 587 | TLS / STARTTLS |
   | SMTPS | 465 | SSL / TLS |

   Los valores exactos los da tu proveedor.
3. **Guardar.**
4. Pulsa **Enviar correo de prueba** (arriba a la derecha) y revisa la bandeja de entrada y el spam del
   destinatario.
5. Si llegó, activa **Usar este servidor SMTP para todos los correos del CRM** y guarda.

Mientras esté apagado, el CRM usa la configuración técnica del servidor (variables `MAIL_*`).

## La contraseña

- **Nunca se muestra**, ni siquiera al Administrador.
- Para conservarla, deja el campo **vacío**.
- Para cambiarla, escribe la nueva.
- Para quitarla (servidor sin autenticación), marca **Eliminar la contraseña guardada** y deja vacío
  el usuario.

## Estado

- **Configurado / No configurado:** si ya tiene servidor, puerto y remitente.
- **Habilitado / Deshabilitado:** si el CRM está usando este servidor.
- **Última prueba aceptada:** fecha y persona. "Aceptada" quiere decir que el servidor recibió el
  mensaje, no que haya llegado al buzón.

## Si la prueba falla

El CRM explica el tipo de problema:

- conexión;
- tiempo de espera;
- usuario o contraseña;
- conexión segura;
- remitente o destinatario rechazado.

La configuración no cambia. Por seguridad, solo se permiten 5 pruebas cada 10 minutos.

## Para que los correos no lleguen a spam

Configura **SPF, DKIM y DMARC** del dominio con tu proveedor y en el DNS. El CRM no los modifica ni
los verifica. La pantalla incluye una breve explicación.
