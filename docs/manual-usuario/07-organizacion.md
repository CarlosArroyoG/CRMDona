# Configuración de la organización

**Para qué sirve:** guardar los datos de la Fundación que usarán los recibos y correos de fases
posteriores, y el aviso de privacidad vigente.

**Quién:** el **Contador** la consulta; solo el **Administrador** la edita.

**Ruta:** menú **Administración → Organización**.

| Sección / campo | Significado |
|---|---|
| Razón social, RFC, régimen fiscal, código postal fiscal | Datos fiscales de la Fundación |
| Número de oficio o carta de autorización, fecha de autorización | Autorización como donataria |
| Leyenda de donativo | Texto que acompañará a los recibos |
| URL del aviso de privacidad, versión vigente | **Obligatorias juntas.** Sin ellas la página de donación no acepta donativos |
| Domicilio del responsable y correo de privacidad | Datos del aviso que publica el CRM. Con ambos, el botón **Publicar aviso del CRM** (arriba a la derecha) publica el aviso en `/aviso-de-privacidad` y llena la URL y la versión automáticamente |
| Logotipo | PNG o JPG, máximo 2 MB. Es el único logotipo del CRM: aparece en el panel, la página de donación, el ícono de la pestaña, el recibo PDF y los correos |
| Firma de correo | Texto simple para los correos futuros |
| Importe mínimo y máximo por donativo en línea | Reglas propias de la Fundación. **Vacío = sin límite propio.** Además siempre aplica el límite técnico del proveedor de pago (por ejemplo, Stripe no acepta cargos menores a $10 MXN); se usa el más restrictivo |

Presiona **Guardar**. Cuando publiquen un aviso de privacidad nuevo, cambia la **versión**: las
aceptaciones siguientes quedarán registradas con la versión nueva.

## Errores frecuentes

| Mensaje | Qué hacer |
|---|---|
| "El campo versión del aviso de privacidad es obligatorio cuando URL…" | Captura la URL y la versión juntas |
| "El campo RFC no tiene la estructura de un RFC de Persona moral (12 caracteres)." | Revisa el RFC de la Fundación |
