# Guía rápida del CRM — Fundación Don Bosco

Explicación sencilla de cada función del CRM, en una sola lectura. Para el paso a paso detallado de cada
pantalla, abre el capítulo que aparece al final de cada sección.

## ¿Qué es el CRM?

Es el sistema donde la Fundación guarda a sus **donantes** y sus **donativos**. Con él:

- recibe donativos en línea con tarjeta, únicos o mensuales;
- registra los donativos que llegan en efectivo, transferencia, cheque o especie;
- manda por correo el agradecimiento y el recibo;
- avisa a Contabilidad para que emita el CFDI **fuera del sistema**.

Tiene dos partes:

| Parte | Dirección | Quién la usa |
|---|---|---|
| **Panel** | `/admin` | El personal de la Fundación, con usuario y contraseña |
| **Página de donativos** | `/donar` | El público, sin cuenta |

> **Importante:** el CRM **no emite facturas (CFDI)**. Las emite la contadora por fuera. El CRM
> solo le avisa y guarda una copia del CFDI como antecedente.

## Los cuatro roles

| Rol | En pocas palabras |
|---|---|
| **Administrador** | Puede hacer todo: usuarios, configuración, bitácora y pasarelas de pago |
| **Coordinador de procuración de fondos** | Donantes, campañas, programas, plantillas de correo y donativos mensuales |
| **Contador** | Confirma donativos, maneja datos fiscales, reembolsos, CFDI externos y control contable |
| **Solo lectura** | Consulta; no modifica nada |

Si no ves un botón, es porque tu rol no tiene esa acción. La tabla completa está en el
[índice del manual](README.md#qué-puede-hacer-cada-rol).

---

## 1. Entrar al panel

- Entra con tu correo y contraseña.
- Si olvidaste la contraseña, usa **"¿Olvidaste tu contraseña?"** o pide al Administrador una
  temporal.
- Una contraseña temporal te obliga a cambiarla en cuanto entras. Vence en 72 horas.
- Después de 5 intentos fallidos, el sistema te hace esperar un minuto.

→ [Capítulo 1](01-acceso-al-panel.md)

## 2. Tablero (pantalla de inicio)

Muestra de un vistazo lo recaudado en el mes, comparado con los mismos días del mes anterior.
También muestra los donativos mensuales activos, los pagos pendientes y los **próximos
cumpleaños** de donantes.

→ [Capítulo 14](14-tablero-y-reportes.md)

## 3. Donantes

La ficha de cada persona o empresa que dona.

- **Registrar:** persona física (nombre y apellidos) o persona moral (razón social).
- **Datos fiscales** (RFC, régimen, código postal, uso de CFDI): solo si el donante pide CFDI.
- **Etiquetas:** para agrupar, por ejemplo "Exalumno" o "Empresa aliada".
- **Consentimientos:** si aceptó el aviso de privacidad y si acepta recibir correos.
- **Duplicados:** el sistema avisa si el correo o el RFC ya existen.
- **Archivar:** oculta al donante sin perder su historial.
- **Eliminar:** solo el Administrador, y solo si el donante nunca ha donado.

→ [Capítulo 2](02-donantes.md)

## 4. Donativos

Cada entrada de dinero o especie.

```
Registrar  →  Por confirmar  →  Confirmado
                     │               │
                     └── Cancelado ◄─┘  (siempre con motivo)
```

- Los **manuales** (efectivo, transferencia, cheque, depósito o especie) nacen **Por confirmar**.
- El **Contador o el Administrador los confirma** cuando verifica que el dinero llegó.
- Un donativo **confirmado ya no se edita**. Si tiene un error, se cancela con motivo y se
  registra uno nuevo.
- **Nada se borra.** Los cancelados quedan en el historial.
- Los donativos **en línea** los crea el sistema solo, ya confirmados, cuando el pago con tarjeta
  sale bien.
- Cada donativo va a **un solo destino**: una campaña, un programa o el fondo general.

→ [Capítulo 3](03-donativos.md)

## 5. Qué pasa al confirmar un donativo

El sistema hace tres cosas solo, sin que nadie intervenga:

1. Genera el **recibo simple**: un PDF con folio (por ejemplo, `R-000123`). **No es factura.**
2. Manda al donante el **correo de agradecimiento** con el recibo adjunto.
3. Manda a Contabilidad un **aviso** que dice si el donante pidió CFDI (**SÍ** o **NO**). Si dijo
   que sí, el aviso lleva sus datos fiscales.

Si algo falla (por ejemplo, el correo), el donativo sigue confirmado y el paso se puede reintentar.

→ [Capítulo 12](12-cfdi.md) y [capítulo 13](13-comunicaciones.md)

## 6. Programas y campañas

- **Programa:** una línea de trabajo permanente de la Fundación, por ejemplo "Becas".
- **Campaña:** una colecta con fechas y, si se quiere, una meta en pesos. Puede pertenecer a un
  programa.
- Cada campaña tiene **su propia liga de donación** para compartirla en redes sociales.

→ [Capítulo 4](04-programas-y-campanas.md)

## 7. Página pública de donativos (`/donar`)

Lo que ve el donante:

1. Elige el importe y si dona **una vez** o **cada mes**.
2. Escribe sus datos y, si quiere CFDI, sus datos fiscales.
3. Acepta el aviso de privacidad y paga con tarjeta en la página segura del proveedor.
4. Ve el resultado: aprobado, en proceso o rechazado (y puede reintentar).

Protecciones: el sistema limita los envíos por minuto y detecta formularios llenados por robots.
Si el correo del donante ya existe, **no cambia** los datos guardados; solo deja una nota para
que el personal la revise.

→ [Capítulo 15](15-pagina-publica.md)

## 8. Pagos en línea

Cada cobro con tarjeta hecho con **Stripe** o **Mercado Pago**.

- Estados: en proceso, exitoso, rechazado, reembolsado.
- Solo un pago **exitoso** crea un donativo.
- **Reembolsos** (Administrador y Contador): se pide con un motivo del catálogo. **No** cancela
  el donativo.
- **Disputas y contracargos:** cuando el tarjetahabiente reclama al banco. El sistema las registra
  para darles seguimiento.
- Cada 15 minutos el sistema revisa solo los pagos que se quedaron a medias.

→ [Capítulo 9](09-pagos-en-linea.md)

## 9. Donativos mensuales

Un cargo automático cada mes a la tarjeta del donante.

- Cada mes exitoso se vuelve un donativo confirmado, con su recibo y su agradecimiento.
- Si un cobro falla, **el proveedor reintenta solo**. La suscripción no se cancela por un rechazo.
- El Administrador y el Coordinador pueden **pausarla**, **reanudarla** o **cancelarla**.

→ [Capítulo 10](10-donativos-mensuales.md)

## 10. Incidencias de pagos

Son avisos de algo que requiere atención: un pago exitoso sin donativo, un reembolso sin respuesta,
una disputa…

- Llegan a la **campana** del panel y por correo a los responsables.
- Se **toman** ("lo reviso yo"), se comentan y se **resuelven**.
- Leer una incidencia **no** la resuelve.

→ [Capítulo 11](11-incidencias.md)

## 11. Control contable y CFDI externo

- **Control contable:** una fila por donativo confirmado. Muestra el recibo, si se pidió CFDI, el
  estado del aviso y si Contabilidad ya lo **procesó**. Se puede filtrar y exportar.
- **Adjuntar CFDI externo:** el Contador sube el **XML** (obligatorio) y el **PDF** (opcional) que
  emitió por fuera. El sistema revisa que sea un CFDI timbrado de la Fundación.
- **Reemplazar o retirar** un CFDI conserva el anterior. Nada de esto cancela nada ante el SAT.
- Adjuntar un CFDI **no** marca el donativo como procesado; eso se hace aparte.

→ [Capítulo 12](12-cfdi.md)

## 12. Comunicaciones

- **Correos automáticos:** agradecimiento al confirmar y felicitación de cumpleaños a las 9:00.
- **Plantillas:** el texto de cada correo se edita con variables como `{{ nombre }}`.
- **Historial de envíos:** cada correo con su estado (enviado, fallido, no enviado…). Desde ahí
  se puede **reenviar**.
- **Baja:** los correos traen un enlace para que el donante deje de recibirlos, sin necesidad
  de cuenta.

→ [Capítulo 13](13-comunicaciones.md)

## 13. Exportar a Excel o CSV

- Botón **Exportar** en las listas. Respeta los filtros que tengas puestos.
- El archivo llega a la **campana** y **se borra a los 7 días**. Solo quien lo generó puede
  descargarlo.
- Por seguridad, si un dato empieza con `=`, `+`, `-` o `@`, se exporta con un apóstrofo delante
  (`'=`). Así Excel lo muestra como texto y nunca lo ejecuta como fórmula.

→ [Capítulo 5](05-exportar.md)

## 14. Administración (solo Administrador)

| Pantalla | Para qué sirve |
|---|---|
| **Usuarios** | Crear usuarios, cambiar su rol, desactivarlos y darles una contraseña temporal |
| **Organización** | Datos fiscales, logotipo (PNG o JPG), aviso de privacidad, límites de donativos y correos automáticos |
| **Correo saliente** | Datos del servidor de correo (SMTP) y botón para enviar un correo de prueba |
| **Pasarelas de pago** | Revisar si Stripe y Mercado Pago están activos, y en qué modo (prueba o real) |
| **Bandeja de webhooks** | Avisos técnicos que mandan los proveedores de pago |
| **Bitácora** | Quién cambió qué y cuándo. Los datos personales y fiscales aparecen sin su valor |

→ Capítulos [6](06-usuarios.md), [7](07-organizacion.md), [8](08-bitacora.md) y
[16](16-correo-saliente.md)

---

## Reglas de oro

1. **Nada se borra:** los donativos se cancelan con motivo y los CFDI se retiran.
2. **El recibo simple no es factura.** La factura (CFDI) la emite Contabilidad por fuera.
3. **Nadie escribe datos de tarjeta en el CRM:** el pago ocurre en la página del proveedor.
4. **Las contraseñas y las llaves nunca se comparten** por correo ni por chat.
5. **Si algo falla, revisa la campana:** ahí llegan las incidencias y los avisos.
