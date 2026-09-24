@extends('layouts.public')

@section('title', 'Pago con tarjeta')

@section('content')
    <h1 class="text-2xl font-bold text-db-navy">Pago con tarjeta</h1>
    <p class="mt-2 text-sm text-db-text-muted">El pago se procesa en el formulario seguro de Stripe; nosotros no vemos ni guardamos los datos de tu tarjeta.</p>
    <div id="checkout" class="mt-4" aria-live="polite"></div>
@endsection

@push('scripts')
    <script nonce="{{ Vite::cspNonce() }}" src="https://js.stripe.com/v3/"></script>
    <script nonce="{{ Vite::cspNonce() }}">
        (async function () {
            const stripe = Stripe(@json($publishableKey));
            const checkout = await stripe.initEmbeddedCheckout({ fetchClientSecret: async () => @json($clientSecret) });
            checkout.mount('#checkout');
        })();
    </script>
@endpush
