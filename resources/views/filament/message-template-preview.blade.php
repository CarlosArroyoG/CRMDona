<div style="display:flex;flex-direction:column;gap:12px;">
    @if ($error)
        <p style="color:#b91c1c;">La plantilla tiene un error y no se usaría: {{ $error }} Al enviar, el sistema usaría el texto predeterminado.</p>
    @else
        <p><strong>Asunto:</strong> {{ $subject }}</p>
        <div style="white-space:pre-line;border:1px solid #e5e7eb;border-radius:6px;padding:12px;">{{ $body }}</div>
        <p style="font-size:12px;color:#6b7280;">El sistema agrega la firma y los avisos obligatorios (recibo no fiscal, baja).</p>
    @endif
</div>
