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
    <section class="rounded-lg bg-white p-6 shadow-sm" aria-live="polite" aria-labelledby="titulo">
        @switch($state)
            @case(PublicDonationStatus::CONFIRMED)
                <h1 id="titulo" class="text-2xl font-bold" style="color: var(--brand)">¡Gracias por tu donativo!</h1>
                <p class="mt-3">
                    Confirmamos tu donativo de <strong>{{ $amount }} MXN</strong>{{ $monthly ? ' mensual' : '' }}{{ $campaign ? ' para '.$campaign->name : '' }}.
                </p>
                <p class="mt-2">Te enviaremos por correo tu agradecimiento con el recibo{{ $payload['tax'] !== null ? ' y, cuando esté listo, tu comprobante fiscal' : '' }}.</p>
                @if ($monthly)
                    <p class="mt-2 text-sm text-slate-600">Tu donativo se cobrará cada mes. Si quieres cancelarlo, escríbenos.</p>
                @endif
                @break

            @case(PublicDonationStatus::PROCESSING)
                <h1 id="titulo" class="text-2xl font-bold" style="color: var(--brand)">Estamos confirmando tu pago</h1>
                <p class="mt-3">El proveedor de pago aún no confirma tu donativo de {{ $amount }} MXN. Esta página se actualiza sola.</p>
                <p class="mt-2 text-sm text-slate-600">Si cierras esta ventana no pasa nada: al confirmarse recibirás un correo.</p>
                @break

            @case(PublicDonationStatus::DECLINED)
            @case(PublicDonationStatus::FAILED)
                <h1 id="titulo" class="text-2xl font-bold text-red-700">No se pudo completar el pago</h1>
                <p class="mt-3">El pago de {{ $amount }} MXN no se realizó. No se hizo ningún cargo por este intento.</p>
                <form method="post" action="{{ route('donate.retry', ['token' => $token]) }}" class="mt-4">
                    @csrf
                    <button type="submit" class="w-full rounded-md px-4 py-3 font-semibold text-white sm:w-auto" style="background: var(--brand)">Intentar de nuevo</button>
                </form>
                @break

            @default
                <h1 id="titulo" class="text-2xl font-bold" style="color: var(--brand)">Tu donativo aún no se envía</h1>
                <p class="mt-3"><a href="{{ route('donate.summary', ['token' => $token]) }}" class="underline">Volver al resumen para pagar</a></p>
        @endswitch
    </section>
@endsection
