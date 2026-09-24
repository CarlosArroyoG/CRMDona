@extends('layouts.public')

@section('title', 'Estado de tu donativo')

@use('App\PublicDonations\PublicDonationStatus')

@php
    $monthly = $payload['frequency'] === 'monthly';
    $amount = \App\Support\Money::format($payload['amount']);
@endphp

@if ($state === PublicDonationStatus::PROCESSING)
    @push('head')
        <meta http-equiv="refresh" content="8">
    @endpush
@endif

@section('content')
    <section class="text-center" aria-live="polite" aria-labelledby="titulo">
        @switch($state)
            @case(PublicDonationStatus::CONFIRMED)
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-db-soft-yellow text-3xl">🙏</div>
                <h1 id="titulo" class="mt-4 text-2xl font-bold text-db-navy sm:text-3xl">¡Gracias por tu donativo!</h1>
                <p class="mt-3 text-db-text">
                    Confirmamos tu donativo de <strong>{{ $amount }} MXN</strong>{{ $monthly ? ' mensual' : '' }}{{ ($campaign ?? $program) ? ' para '.($campaign ?? $program)->name : '' }}.
                </p>
                <p class="mt-2 text-db-text-muted">Te enviaremos por correo tu agradecimiento con el recibo.{{ $payload['tax'] !== null ? ' Tu solicitud de comprobante fiscal (CFDI) pasa a nuestra área de contabilidad, que lo emite por separado.' : '' }}</p>
                @if ($monthly)
                    <p class="mt-4 rounded-lg bg-db-soft-blue p-3 text-sm text-db-text">Tu donativo se cobrará cada mes. Si quieres cancelarlo, escríbenos.</p>
                @endif
                @break

            @case(PublicDonationStatus::PROCESSING)
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-db-soft-blue text-3xl">⏳</div>
                <h1 id="titulo" class="mt-4 text-2xl font-bold text-db-navy sm:text-3xl">Estamos confirmando tu pago</h1>
                <p class="mt-3 text-db-text">El proveedor de pago aún no confirma tu donativo de {{ $amount }} MXN. Esta página se actualiza sola.</p>
                <p class="mt-2 text-sm text-db-text-muted">Si cierras esta ventana no pasa nada: al confirmarse recibirás un correo.</p>
                @break

            @case(PublicDonationStatus::DECLINED)
            @case(PublicDonationStatus::FAILED)
                <h1 id="titulo" class="text-2xl font-bold text-red-700">No se pudo completar el pago</h1>
                <p class="mt-3 text-db-text">El pago de {{ $amount }} MXN no se realizó. No se hizo ningún cargo por este intento.</p>
                <form method="post" action="{{ route('donate.retry', ['token' => $token]) }}" class="mt-4">
                    @csrf
                    <button type="submit" class="min-h-14 w-full rounded-xl px-4 py-3 font-semibold text-white shadow-sm transition hover:brightness-110 sm:w-auto" style="background: var(--brand)">Intentar de nuevo</button>
                </form>
                @break

            @default
                <h1 id="titulo" class="text-2xl font-bold text-db-navy">Tu donativo aún no se envía</h1>
                <p class="mt-3"><a href="{{ route('donate.summary', ['token' => $token]) }}" class="font-medium text-db-blue underline underline-offset-2">Volver al resumen para pagar</a></p>
        @endswitch
    </section>
@endsection
