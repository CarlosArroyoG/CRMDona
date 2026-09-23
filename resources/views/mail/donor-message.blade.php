<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f4f5f9;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border-top:4px solid #162562;padding:24px;">
        @foreach ($paragraphs as $paragraph)
            <p style="line-height:1.5;margin:0 0 14px;">{!! nl2br(e($paragraph)) !!}</p>
        @endforeach

        @if ($signature)
            <p style="line-height:1.5;margin:18px 0 0;color:#374151;">{!! nl2br(e($signature)) !!}</p>
        @endif

        @if ($notices !== [])
            <div style="margin-top:20px;padding-top:12px;border-top:1px solid #e5e7eb;font-size:13px;color:#4b5563;">
                @foreach ($notices as $notice)
                    <p style="margin:0 0 6px;">{{ $notice }}</p>
                @endforeach
            </div>
        @endif

        @if ($unsubscribeUrl)
            <p style="margin-top:16px;font-size:12px;"><a href="{{ $unsubscribeUrl }}" style="color:#162562;">Darme de baja de estas comunicaciones</a></p>
        @endif
    </div>
</body>
</html>
