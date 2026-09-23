@extends('layouts.public')

@section('title', 'Confirma tu donativo')

@php
    $monthly = $payload['frequency'] === 'monthly';
    $donor = $payload['donor'];
    $name = $donor['type'] === 'organization' ? $donor['legal_name'] : trim($donor['first_name'].' '.$donor['last_name'].' '.($donor['second_last_name'] ?? ''));
    $amount = \App\Support\Money::format($payload['amount']);
@endphp

@section('content')
    <h1 class="text-2xl font-bold" style="color: var(--brand)">Confirma tu donativo</h1>

    @if ($errors->any())
        <div role="alert" class="mt-4 rounded-md border border-red-300 bg-red-50 p-4 text-red-800">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <section class="mt-6 rounded-lg bg-white p-4 shadow-sm" aria-labelledby="resumen">
        <h2 id="resumen" class="font-semibold">Resumen</h2>
        <dl class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
            <div><dt class="text-sm text-slate-600">Importe</dt><dd class="text-lg font-semibold">{{ $amount }} MXN{{ $monthly ? ' al mes' : '' }}</dd></div>
            <div><dt class="text-sm text-slate-600">Frecuencia</dt><dd>{{ $monthly ? 'Mensual (recurrente)' : 'Una sola vez' }}</dd></div>
            <div><dt class="text-sm text-slate-600">Destino</dt><dd>{{ $campaign?->name ?? 'Donativo general a '.$organization }}</dd></div>
            <div><dt class="text-sm text-slate-600">A nombre de</dt><dd>{{ $name }}</dd></div>
            <div><dt class="text-sm text-slate-600">Correo</dt><dd>{{ $donor['email'] }}</dd></div>
            <div><dt class="text-sm text-slate-600">Comprobante fiscal a tu nombre</dt><dd>{{ $payload['tax'] !== null ? 'Sí, con los datos fiscales que capturaste' : 'No' }}</dd></div>
        </dl>
        @if ($monthly)
            <p class="mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-900">
                Es un donativo <strong>recurrente</strong>: se cobrarán {{ $amount }} MXN <strong>cada mes</strong> a la misma tarjeta hasta que lo canceles.
                Si un cobro no se logra, el proveedor de pago puede volver a intentarlo según sus propias reglas.
            </p>
        @endif
        <p class="mt-4 text-sm"><a href="{{ $campaign !== null ? route('donate.campaign', ['campaign' => $campaign->slug]) : route('donate.create') }}" class="underline">Corregir mis datos</a></p>
    </section>

    <section class="mt-6 rounded-lg bg-white p-4 shadow-sm" aria-labelledby="pago">
        <h2 id="pago" class="font-semibold">Pago</h2>

        @if ($provider === \App\Enums\PaymentProvider::MercadoPago)
            <div id="cardPaymentBrick_container" class="mt-3" aria-live="polite"></div>
            <p class="mt-2 text-sm text-slate-600">El pago se procesa en el formulario seguro de Mercado Pago; nosotros no vemos ni guardamos los datos de tu tarjeta.</p>
        @else
            <form method="post" action="{{ route('donate.pay', ['token' => $token]) }}" class="mt-3 space-y-4" data-loading-form>
                @csrf
                @if ($fakeScenarios !== [])
                    <div class="rounded-md border border-dashed border-slate-400 p-3">
                        <label for="fake_scenario" class="block text-sm font-medium">Proveedor simulado (solo ambiente local): resultado del pago</label>
                        <select id="fake_scenario" name="fake_scenario" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2">
                            @foreach ($fakeScenarios as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <button type="submit" class="w-full rounded-md px-4 py-3 font-semibold text-white" style="background: var(--brand)" data-loading-text="Procesando…">
                    {{ $monthly ? 'Donar '.$amount.' MXN cada mes' : 'Donar '.$amount.' MXN' }}
                </button>
                <p class="text-sm text-slate-600">Si presionas dos veces o recargas la página no se hará un cargo doble.</p>
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
