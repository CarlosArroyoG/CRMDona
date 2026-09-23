<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Baja de comunicaciones</title>
</head>
<body style="margin:0;padding:24px;background:#f4f5f9;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <main style="max-width:480px;margin:40px auto;background:#fff;border-top:4px solid #162562;padding:24px;">
        <h1 style="font-size:20px;margin-top:0;color:#162562;">{{ $organization }}</h1>
        @if ($done)
            <p>Listo. Ya no te enviaremos comunicaciones informativas, como felicitaciones.</p>
            <p style="font-size:13px;color:#4b5563;">Seguirás recibiendo, si corresponde, los correos relacionados con tus donativos (agradecimiento, recibo y comprobante fiscal).</p>
        @else
            <p>¿Quieres dejar de recibir comunicaciones informativas, como felicitaciones?</p>
            <form method="post" action="{{ route('communications.unsubscribe.store', ['token' => $token]) }}">
                @csrf
                <button type="submit" style="background:#162562;color:#fff;border:0;padding:10px 18px;border-radius:4px;cursor:pointer;">Darme de baja</button>
            </form>
        @endif
    </main>
</body>
</html>
