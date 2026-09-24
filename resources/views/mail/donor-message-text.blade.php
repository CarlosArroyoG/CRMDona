{!! $body !!}
@if ($actionUrl)

{!! $actionLabel !!}: {!! $actionUrl !!}
@endif
@if ($signature)

{!! $signature !!}
@endif
@foreach ($notices as $notice)

{!! $notice !!}
@endforeach
@if ($unsubscribeUrl)

Darme de baja: {!! $unsubscribeUrl !!}
@endif
