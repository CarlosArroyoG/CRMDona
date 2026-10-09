@extends('layouts.public')

@section('title', 'Confirma tu donativo')

@php
    $monthly = $payload['frequency'] === 'monthly';
    $donor = $payload['donor'];
    $name = $donor['type'] === 'organization' ? $donor['legal_name'] : trim(($donor['first_name'] ?? '').' '.($donor['last_name'] ?? '').' '.($donor['second_last_name'] ?? ''));
    $destination = $campaign?->name ?? $program?->name ?? 'Donativo general a '.$organization;
    $amount = \App\Support\Money::format($payload['amount']);
@endphp

@section('content')
    <h1 class="text-3xl font-extrabold tracking-tight text-db-navy sm:text-4xl">Confirma tu donativo</h1>

    @if ($errors->any())
        <div role="alert" class="mt-4 rounded-lg border border-red-300 bg-red-50 p-4 text-red-800">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <section class="mt-6 rounded-2xl border border-db-navy/15 bg-[linear-gradient(135deg,var(--color-db-soft-blue),var(--color-db-surface))] p-5 sm:p-6" aria-labelledby="resumen">
        <h2 id="resumen" class="text-xs font-bold tracking-wider text-db-text-muted uppercase">Resumen de tu donativo</h2>
        <p class="mt-2 text-4xl font-extrabold tracking-tight text-db-navy sm:text-5xl">{{ $amount }} MXN{{ $monthly ? ' al mes' : '' }}</p>
        <dl class="mt-5 grid grid-cols-1 gap-3 border-t border-db-border pt-4 sm:grid-cols-2">
            <div><dt class="text-sm text-db-text-muted">Frecuencia</dt><dd class="font-medium">{{ $monthly ? 'Mensual (recurrente)' : 'Una sola vez' }}</dd></div>
            <div><dt class="text-sm text-db-text-muted">Destino</dt><dd class="font-medium">{{ $destination }}</dd></div>
            <div><dt class="text-sm text-db-text-muted">A nombre de</dt><dd class="font-medium">{{ $name }}</dd></div>
            @if (filled($donor['email']))
                <div><dt class="text-sm text-db-text-muted">Correo</dt><dd class="font-medium">{{ $donor['email'] }}</dd></div>
            @endif
            <div><dt class="text-sm text-db-text-muted">Comprobante fiscal a tu nombre</dt><dd class="font-medium">{{ $payload['tax'] !== null ? ($fromRequest ? 'Sí, con los datos fiscales que tiene registrados la Fundación' : 'Sí, con los datos fiscales que capturaste') : 'No' }}</dd></div>
        </dl>
        @if ($monthly)
            <p class="mt-4 rounded-lg bg-db-soft-yellow p-3 text-sm text-db-text">
                Es un donativo <strong>recurrente</strong>: se cobrarán {{ $amount }} MXN <strong>cada mes</strong> a la misma tarjeta hasta que lo canceles.
                Si un cobro no se logra, el proveedor de pago puede volver a intentarlo según sus propias reglas.
            </p>
        @endif
        @if ($fromRequest)
            <p class="mt-4 text-sm text-db-text-muted">La Fundación preparó este donativo para ti. Si algún dato no es correcto, no pagues y comunícate con nosotros.</p>
        @else
            <p class="mt-4 text-sm"><a href="{{ $campaign !== null ? route('donate.campaign', ['campaign' => $campaign->slug]) : route('donate.create') }}" class="db-link">Corregir mis datos</a></p>
        @endif
    </section>

    <section class="mt-6 rounded-xl border border-db-border bg-db-bg-blue p-5" aria-labelledby="pago">
        <h2 id="pago" class="flex items-center gap-2 font-semibold text-db-navy">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5 shrink-0" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3M4.5 19.5h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" />
            </svg>
            Pago
        </h2>

        @if ($provider === \App\Enums\PaymentProvider::MercadoPago)
            <div id="cardPaymentBrick_container" class="mt-3" aria-live="polite"></div>
            <p class="mt-3 flex items-start gap-1.5 text-sm text-db-text-muted">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                </svg>
                <span>El pago se procesa en el formulario seguro de Mercado Pago; nosotros no vemos ni guardamos los datos de tu tarjeta.</span>
            </p>
        @else
            <form method="post" action="{{ route('donate.pay', ['token' => $token]) }}" class="mt-3 space-y-4" data-loading-form>
                @csrf
                @if ($fakeScenarios !== [])
                    <div class="rounded-lg border border-dashed border-db-text-muted p-3">
                        <label for="fake_scenario" class="block text-sm font-medium text-db-text">Proveedor simulado (solo ambiente local): resultado del pago</label>
                        <select id="fake_scenario" name="fake_scenario" class="mt-1 block w-full rounded-lg border border-db-border px-3 py-2">
                            @foreach ($fakeScenarios as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <button type="submit" class="group flex min-h-14 w-full items-center justify-center gap-2 rounded-xl px-4 py-4 text-base font-semibold text-white shadow-md shadow-db-navy/20 transition hover:shadow-lg hover:brightness-110 sm:text-lg" style="background: var(--brand)" data-loading-text="Procesando…">
                    {{ $monthly ? 'Donar '.$amount.' MXN cada mes' : 'Donar '.$amount.' MXN' }}
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5 shrink-0" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                    </svg>
                </button>
                <p class="flex items-start gap-1.5 text-sm text-db-text-muted">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                    </svg>
                    <span>Si presionas dos veces o recargas la página no se hará un cargo doble.</span>
                </p>
            </form>
        @endif
    </section>
@endsection

@push('scripts')
    <script nonce="{{ Vite::cspNonce() }}">
        (function () {
            const form = document.querySelector('[data-loading-form]');
            if (form) {
                form.addEventListener('submit', () => {
                    const button = form.querySelector('button[type="submit"]');
                    button.disabled = true;
                    button.setAttribute('aria-busy', 'true');
                    button.textContent = button.dataset.loadingText;
                });
            }
        })();
    </script>
    @if ($provider === \App\Enums\PaymentProvider::MercadoPago && $mercadoPagoPublicKey)
        <script nonce="{{ Vite::cspNonce() }}" src="https://sdk.mercadopago.com/js/v2"></script>
        <script nonce="{{ Vite::cspNonce() }}">
            (function () {
                const mp = new MercadoPago(@json($mercadoPagoPublicKey), { locale: 'es-MX' });
                mp.bricks().create('cardPayment', 'cardPaymentBrick_container', {
                    initialization: { amount: Number(@json($payload['amount'])) },
                    callbacks: {
                        onReady: () => {},
                        onError: () => {},
                        onSubmit: (data) => fetch(@json(route('donate.pay', ['token' => $token])), {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
                            body: JSON.stringify({ card_token: data.token, payment_method_id: data.payment_method_id }),
                        }).then((response) => response.json()).then((result) => {
                            if (result.redirect) { window.location.assign(result.redirect); }
                        }),
                    },
                });
            })();
        </script>
    @endif
@endpush
