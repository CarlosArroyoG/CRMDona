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
                <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-db-soft-yellow text-db-navy shadow-inner">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-10 w-10" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l2.25 2.25L15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <h1 id="titulo" class="mt-5 text-3xl font-extrabold tracking-tight text-db-navy sm:text-4xl">¡Gracias por tu donativo!</h1>
                <p class="mt-3 text-db-text">
                    Confirmamos tu donativo de <strong>{{ $amount }} MXN</strong>{{ $monthly ? ' mensual' : '' }}{{ ($campaign ?? $program) ? ' para '.($campaign ?? $program)->name : '' }}.
                </p>
                <p class="mt-2 text-db-text-muted">Te enviaremos por correo tu agradecimiento con el recibo.{{ $payload['tax'] !== null ? ' Tu solicitud de comprobante fiscal (CFDI) pasa a nuestra área de contabilidad, que lo emite por separado.' : '' }}</p>
                @if ($monthly)
                    <p class="mt-4 rounded-lg bg-db-soft-blue p-3 text-sm text-db-text">Tu donativo se cobrará cada mes. Si quieres cancelarlo, escríbenos.</p>
                @endif
                @break

            @case(PublicDonationStatus::PROCESSING)
                <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-db-soft-blue text-db-blue shadow-inner">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-10 w-10" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <h1 id="titulo" class="mt-5 text-3xl font-extrabold tracking-tight text-db-navy sm:text-4xl">Estamos confirmando tu pago</h1>
                <p class="mt-3 text-db-text">El proveedor de pago aún no confirma tu donativo de {{ $amount }} MXN. Esta página se actualiza sola.</p>
                <p class="mt-2 text-sm text-db-text-muted">Si cierras esta ventana no pasa nada: al confirmarse recibirás un correo.</p>
                @break

            @case(PublicDonationStatus::DECLINED)
            @case(PublicDonationStatus::FAILED)
                <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-red-50 text-red-600 shadow-inner">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-10 w-10" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.502-3.032-1.502-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                </div>
                <h1 id="titulo" class="mt-5 text-3xl font-extrabold tracking-tight text-red-700 sm:text-4xl">No se pudo completar el pago</h1>
                <p class="mt-3 text-db-text">El pago de {{ $amount }} MXN no se realizó. No se hizo ningún cargo por este intento.</p>
                <form method="post" action="{{ route('donate.retry', ['token' => $token]) }}" class="mt-4">
                    @csrf
                    <button type="submit" class="group inline-flex min-h-14 w-full items-center justify-center gap-2 rounded-xl px-4 py-3 font-semibold text-white shadow-md shadow-db-navy/20 transition hover:shadow-lg hover:brightness-110 sm:w-auto" style="background: var(--brand)">
                        Intentar de nuevo
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5 transition-transform group-hover:translate-x-0.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                        </svg>
                    </button>
                </form>
                @break

            @default
                <h1 id="titulo" class="text-2xl font-bold tracking-tight text-db-navy sm:text-3xl">Tu donativo aún no se envía</h1>
                <p class="mt-3"><a href="{{ route('donate.summary', ['token' => $token]) }}" class="db-link">Volver al resumen para pagar</a></p>
        @endswitch
    </section>
@endsection
