# Aviso de privacidad — contenido funcional del CRM (base para la institución)

> **No es un dictamen jurídico ni el aviso definitivo.** Este documento describe qué datos trata
> realmente el CRM y para qué. Sirve para que la institución redacte o actualice su aviso de privacidad,
> con su asesoría legal, y lo publique en su sitio.
>
> **Actualización 2026-09-25:** el CRM publica un aviso basado en este contenido en `/aviso-de-privacidad`
> (`resources/views/public/privacy.blade.php`). Se activa en **Organización** capturando el domicilio del
> responsable y el correo de privacidad y presionando **Publicar aviso del CRM**, que fija la URL y la
> versión (fecha del día). Debe revisarlo la asesoría legal de la institución. También se puede seguir usando
> un aviso propio publicado en otro sitio.
>
> En **Administración → Organización → Aviso de privacidad** se configuran:
> - la **URL** donde está publicado;
> - su **versión vigente** (por ejemplo, `2026-09`).
>
> Cómo lo usa el CRM:
> - la página pública lo enlaza y exige aceptarlo antes de enviar datos;
> - sin URL y versión, la página no acepta donativos;
> - se registra la versión aceptada y la fecha en el donante;
> - al publicar un aviso nuevo, hay que cambiar la versión.

## Datos pendientes de la institución (no inventar)

| Dato | Estado | Dónde está o irá |
|---|---|---|
| Nombre o razón social del responsable | Se configura en Organización (`legal_name`); confirmar que coincide con el aviso | Organización → Datos fiscales |
| Domicilio del responsable | **PENDIENTE DE LA INSTITUCIÓN** | Solo en el aviso publicado |
| Medio de contacto para privacidad (correo o área) | **PENDIENTE DE LA INSTITUCIÓN** | Solo en el aviso publicado |
| Procedimiento y plazos para derechos ARCO y revocación del consentimiento | **PENDIENTE DE LA INSTITUCIÓN** (proceso interno, pendiente #6) | Aviso publicado |
| Transferencias de datos (si las hay) | **PENDIENTE DE LA INSTITUCIÓN** | Aviso publicado |
| Plazos de conservación | **PENDIENTE DE VALIDACIÓN LEGAL/FISCAL** (pendiente #5) | Aviso publicado |
| URL pública del aviso y versión | **PENDIENTE DE LA INSTITUCIÓN** (hoy hay un valor de desarrollo) | Organización → Aviso de privacidad |

## Qué datos trata el CRM y para qué

1. **Identificación y contacto del donante.**
   - **Datos:** nombre (o razón social y persona de contacto), correo electrónico, teléfono opcional y
     fecha de nacimiento si se captura.
   - **Para qué:**
     - registrar el donativo y su historial;
     - enviar el agradecimiento y el recibo simple;
     - atender al donante.
2. **Datos fiscales, solo si el donante solicita CFDI.**
   - **Datos:** RFC, nombre o razón social como aparece en la constancia, régimen fiscal, código postal
     fiscal y, si se indica, el uso del CFDI.
   - **El CRM no genera el CFDI.** Estos datos quedan disponibles solo para el **personal autorizado de
     Contabilidad**, en el panel y en un aviso interno por correo, para que emita el CFDI **fuera del
     CRM**.
   - El recibo simple y el correo al donante no incluyen estos datos.
   - Contabilidad puede **conservar como antecedente** del donativo el XML y el PDF del CFDI emitido
     externamente, en almacenamiento privado y con acceso restringido.
3. **Datos de pago.**
   - Los pagos en línea se procesan mediante las **pasarelas de pago habilitadas** por la institución.
   - El CRM **no almacena** el número completo de la tarjeta, el código de seguridad (CVV) ni la fecha
     de vencimiento.
   - Solo conserva lo necesario para conciliar: marca, últimos 4 dígitos, tipo de tarjeta, resultado,
     importe e identificadores de la pasarela.
4. **Comunicaciones.**
   - **Transaccionales:** agradecimiento y recibo del donativo. Forman parte de la relación del
     donativo y se envían aunque el donante no acepte comunicaciones informativas.
   - **Informativas o promocionales:** felicitación de cumpleaños y noticias. Solo con **consentimiento**
     expreso, que es opcional en el formulario. Cada correo informativo incluye un enlace de **baja**
     inmediata.
5. **Registro de actividad (bitácora).**
   - Quién cambió qué y cuándo.
   - No guarda los valores de datos personales ni fiscales sensibles: solo el nombre del campo.

## Mecanismos ya implementados en el CRM

- Aceptación obligatoria del aviso, con versión y fecha registradas en el donante.
- Consentimiento opcional y separado para comunicaciones informativas, con fecha del cambio.
- Baja de comunicaciones informativas desde el enlace del correo, sin iniciar sesión.
- Acceso a los datos por rol: los datos fiscales los ven solo los roles autorizados, y Solo lectura no
  tiene acceso a nada fiscal.
- Almacenamiento privado de recibos y CFDI externos, con descarga solo desde el panel autorizado.
- No se implementa, porque es un proceso institucional: la atención de solicitudes ARCO (pendiente #6).

## Qué NO debe decir el aviso (ya no aplica)

- Que el CRM genera, timbra o cancela CFDI, o que usa un proveedor de facturación: la emisión es externa
  (ADR-012).
- Direcciones, responsables, transferencias o plazos que la institución no haya confirmado.
