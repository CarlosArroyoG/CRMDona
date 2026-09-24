# CFDI y Contabilidad

**El CRM no emite CFDI.** La contadora emite todos los CFDI (individuales y globales) fuera del
sistema, y también los cancela o sustituye fuera. El CRM avisa a Contabilidad de cada donativo y
permite guardar después el CFDI como antecedente.

## Qué pasa al confirmar un donativo

Pasa igual con los donativos a mano, los pagos en línea y cada mensualidad:

1. Se genera el **recibo simple**. No es un CFDI.
2. El donante recibe el **agradecimiento** con su recibo. Nunca espera un CFDI.
3. Contabilidad recibe un **aviso** por correo con:
   - folio del recibo, número de donativo, donante, fecha, importe y destino;
   - la forma de pago;
   - **CFDI solicitado: SÍ o NO**.

Si el donante **solicitó CFDI**, el aviso incluye los datos fiscales que ya están en su ficha:

- RFC;
- nombre o razón social;
- régimen fiscal;
- código postal;
- uso del CFDI.

Si no los tiene, el aviso lo indica.

Si **no solicitó CFDI**, el aviso lo dice. El CRM no decide si va a una factura global: eso lo decide
Contabilidad.

Si falla el envío del aviso, el donativo sigue confirmado y el agradecimiento sale igual.

## Quién recibe los avisos

El Administrador lo activa en **Usuarios → Editar → "Recibe avisos a Contabilidad"**. Solo se puede
activar para un Administrador o un Contador, porque los avisos llevan datos fiscales.

Si nadie los tiene activados, los Administradores reciben una alerta. Después, los avisos pendientes se
reenvían desde **Control contable**.

## Control contable

Está en **Reportes → Control contable**. Hay una fila por donativo confirmado, con:

- el recibo;
- si el donante solicitó CFDI;
- el estado del aviso;
- el procesamiento contable (pendiente o procesado);
- el CFDI externo adjunto, con su UUID y fechas.

Filtra por:

- CFDI solicitado o no solicitado;
- CFDI externo adjunto;
- procesamiento pendiente o procesado;
- estado del aviso, fechas o donante.

El Administrador y el Contador pueden hacer estas acciones:

- **Marcar procesado**, con una nota opcional. También en lote.
- **Reabrir**, con un motivo.
- **Reenviar aviso**, si falló o no se envió.

El Coordinador puede consultar y exportar. Solo lectura no tiene acceso.

## Adjuntar el CFDI externo a un donativo

1. Abre el donativo confirmado.
2. En **CFDI externo / antecedentes fiscales**, pulsa **Adjuntar CFDI externo**. Lo pueden hacer el
   Administrador y el Contador.
3. Sube el **XML**, que es obligatorio. Puedes agregar el **PDF** y una nota interna.

El sistema lee del XML el UUID, las fechas y el total, y guarda los archivos en privado. Rechaza el
archivo en estos casos:

- no es un CFDI timbrado;
- trae contenido peligroso;
- pesa demasiado;
- su RFC emisor no es el de la Fundación;
- ya está adjunto a ese donativo.

- **Reemplazar:** el CFDI anterior queda como "Retirado" y se conserva.
- **Retirar:** pide un motivo; el registro y los archivos se conservan.

Ninguna de estas acciones cancela nada ante el SAT.

Adjuntar el CFDI no marca el donativo como procesado: márcalo en Control contable cuando corresponda.

El CRM **no valida** la vigencia del CFDI ante el SAT. Solo lo guarda como evidencia.

## CFDI anteriores

Los CFDI que el sistema llegó a timbrar antes de este cambio aparecen como "Registro anterior del CRM"
en la misma sección del donativo.
