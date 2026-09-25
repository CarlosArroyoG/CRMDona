@extends('layouts.public')

@section('title', 'Aviso de privacidad')

@section('content')
    <article class="space-y-6 text-db-text [&_h2]:mt-2 [&_h2]:text-lg [&_h2]:font-semibold [&_h2]:text-db-navy [&_li]:ml-5 [&_li]:list-disc [&_p]:leading-relaxed [&_ul]:space-y-1">
        <header>
            <h1 class="text-2xl font-bold text-db-navy sm:text-3xl">Aviso de privacidad</h1>
            <p class="mt-2 text-sm text-db-text-muted">
                Versión {{ $version ?? 'sin publicar' }}. Aplica a los donativos y comunicaciones que gestiona {{ $organization }}.
            </p>
        </header>

        <section>
            <h2>1. Responsable de tus datos</h2>
            <p>
                <strong>{{ $organization }}</strong>{{ $rfc ? ', RFC '.$rfc : '' }}{{ $address ? ', con domicilio en '.$address : '' }},
                es responsable del tratamiento de los datos personales que nos proporcionas al donar.
            </p>
            @if ($contactEmail)
                <p>Para cualquier asunto de privacidad escríbenos a <a href="mailto:{{ $contactEmail }}" class="font-medium text-db-blue underline underline-offset-2">{{ $contactEmail }}</a>.</p>
            @endif
        </section>

        <section>
            <h2>2. Datos que tratamos</h2>
            <ul>
                <li><strong>Identificación y contacto:</strong> nombre (o razón social y persona de contacto), correo electrónico y, si los proporcionas, teléfono y fecha de nacimiento.</li>
                <li><strong>Datos fiscales, solo si solicitas comprobante fiscal (CFDI):</strong> RFC, nombre o razón social, régimen fiscal, código postal fiscal y uso del CFDI.</li>
                <li><strong>Datos del donativo y del pago:</strong> importe, fecha, destino y resultado del pago. <strong>No guardamos el número completo de tu tarjeta, su código de seguridad (CVV) ni su fecha de vencimiento</strong>: los escribes directamente en la página segura del proveedor de pago. Solo conservamos la marca, los últimos 4 dígitos y los identificadores necesarios para conciliar el pago.</li>
            </ul>
            <p>No tratamos datos personales sensibles.</p>
        </section>

        <section>
            <h2>3. Para qué usamos tus datos</h2>
            <p><strong>Finalidades necesarias</strong> para tu donativo:</p>
            <ul>
                <li>Registrar tu donativo y su historial, y procesar el pago único o mensual.</li>
                <li>Enviarte el agradecimiento y el recibo simple (acuse de agradecimiento, no comprobante fiscal).</li>
                <li>Si lo solicitas, que nuestra área de Contabilidad emita tu CFDI. Tus datos fiscales solo los ve el personal autorizado de Contabilidad.</li>
                <li>Enviarte, cuando lo pidas o lo preparemos contigo, el enlace seguro para completar un donativo.</li>
                <li>Atender tus dudas y cumplir obligaciones legales y fiscales.</li>
            </ul>
            <p><strong>Finalidades adicionales</strong>, solo si nos das tu consentimiento (es opcional):</p>
            <ul>
                <li>Felicitarte en tu cumpleaños por correo o por WhatsApp y enviarte noticias de la Fundación.</li>
            </ul>
            <p>Puedes dejar de recibirlas en cualquier momento con el enlace de baja que viene en cada correo o escribiéndonos. Negarte no afecta tu donativo.</p>
        </section>

        <section>
            <h2>4. Con quién compartimos tus datos</h2>
            <p>No vendemos ni cedemos tus datos. Para operar, los compartimos únicamente con proveedores que actúan por nuestra cuenta:</p>
            <ul>
                <li>El proveedor de pago que procesa tu tarjeta (por ejemplo, Stripe).</li>
                <li>Los servicios de correo electrónico y de alojamiento del sistema.</li>
            </ul>
            <p>También podemos proporcionar información a autoridades cuando la ley lo exija.</p>
        </section>

        <section>
            <h2>5. Tus derechos (ARCO) y cómo retirar tu consentimiento</h2>
            <p>
                Puedes acceder a tus datos, rectificarlos, cancelarlos u oponerte a su uso, así como retirar tu consentimiento o limitar su uso.
                @if ($contactEmail)
                    Envía tu solicitud a <a href="mailto:{{ $contactEmail }}" class="font-medium text-db-blue underline underline-offset-2">{{ $contactEmail }}</a>
                @else
                    Envía tu solicitud a la Fundación
                @endif
                con tu nombre, un medio para responderte, lo que solicitas y una identificación. Te responderemos en un plazo máximo de 20 días hábiles.
            </p>
        </section>

        <section>
            <h2>6. Conservación y seguridad</h2>
            <p>
                Conservamos tus datos mientras sean necesarios para las finalidades descritas y durante los plazos que exigen las obligaciones fiscales y contables aplicables.
                Protegemos la información con acceso por roles, verificación en dos pasos para el personal, almacenamiento privado de documentos y registro de actividad.
            </p>
        </section>

        <section>
            <h2>7. Cookies</h2>
            <p>Esta página solo usa cookies técnicas necesarias para que funcione el formulario de donación y tu sesión. No usamos cookies de publicidad.</p>
        </section>

        <section>
            <h2>8. Cambios a este aviso</h2>
            <p>Cualquier cambio se publicará en esta misma dirección, con una nueva versión. Al donar se registra la versión que aceptaste.</p>
        </section>
    </article>
@endsection
