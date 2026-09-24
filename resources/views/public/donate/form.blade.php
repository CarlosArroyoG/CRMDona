@extends('layouts.public')

@section('title', $campaign?->name ?? 'Donar')

@php
    $input = 'mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2';
    $money = fn (string $amount): string => \App\Support\Money::format($amount);
@endphp

@section('content')
    <h1 class="text-2xl font-bold" style="color: var(--brand)">
        {{ $campaign !== null ? $campaign->name : 'Haz tu donativo a '.$organization }}
    </h1>
    @if ($campaign?->description)
        <p class="mt-2 text-slate-700">{{ $campaign->description }}</p>
    @endif

    @if ($errors->any())
        <div role="alert" class="mt-4 rounded-md border border-red-300 bg-red-50 p-4 text-red-800" tabindex="-1" id="errores">
            <p class="font-semibold">Revisa lo siguiente:</p>
            <ul class="mt-2 list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="post" action="{{ $action }}" class="mt-6 space-y-6" data-loading-form novalidate>
        @csrf

        {{-- Campo trampa para robots: las personas no lo ven ni lo llenan. --}}
        <div aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden">
            <label for="website">No llenar este campo</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
        </div>

        <fieldset class="rounded-lg bg-white p-4 shadow-sm">
            <legend class="px-1 font-semibold">¿Cada cuándo?</legend>
            <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                <label class="flex items-center gap-2 rounded-md border p-3">
                    <input type="radio" name="frequency" value="one_time" @checked(old('frequency', 'one_time') === 'one_time')>
                    <span>Una sola vez</span>
                </label>
                @if ($acceptsMonthly)
                    <label class="flex items-center gap-2 rounded-md border p-3">
                        <input type="radio" name="frequency" value="monthly" @checked(old('frequency') === 'monthly')>
                        <span>Cada mes (donativo recurrente)</span>
                    </label>
                @endif
            </div>
            @if ($acceptsMonthly)
                <p class="mt-2 text-sm text-slate-600" id="ayuda-mensual">El donativo mensual se cobra automáticamente cada mes a la misma tarjeta hasta que decidas cancelarlo.</p>
            @endif
        </fieldset>

        <fieldset class="rounded-lg bg-white p-4 shadow-sm">
            <legend class="px-1 font-semibold">¿Cuánto quieres donar? (MXN)</legend>
            <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                @foreach ($suggested as $amount)
                    <label class="flex items-center justify-center gap-2 rounded-md border p-3 text-center">
                        <input type="radio" name="amount" value="{{ $amount }}" @checked(old('amount', $suggested[1] ?? $suggested[0] ?? null) === $amount)>
                        <span>{{ $money($amount) }}</span>
                    </label>
                @endforeach
                <label class="col-span-2 flex items-center gap-2 rounded-md border p-3 sm:col-span-4">
                    <input type="radio" name="amount" value="otro" @checked(old('amount') === 'otro') data-other-amount>
                    <span>Otra cantidad</span>
                </label>
            </div>
            <div class="mt-3" data-custom-amount>
                <label for="custom_amount" class="block text-sm font-medium">Importe (si elegiste otra cantidad)</label>
                <input type="text" inputmode="decimal" id="custom_amount" name="custom_amount" value="{{ old('custom_amount') }}" class="{{ $input }}"
                       aria-describedby="ayuda-limites" autocomplete="off">
                <p id="ayuda-limites" class="mt-1 text-sm text-slate-600">
                    @if ($limits['min'] !== null) Mínimo {{ $money($limits['min']) }}. @endif
                    @if ($limits['max'] !== null) Máximo {{ $money($limits['max']) }}. @endif
                </p>
            </div>
        </fieldset>

        <fieldset class="rounded-lg bg-white p-4 shadow-sm">
            <legend class="px-1 font-semibold">Tus datos</legend>
            <div class="mt-2 flex flex-wrap gap-4">
                <label class="flex items-center gap-2"><input type="radio" name="donor_type" value="individual" @checked(old('donor_type', 'individual') === 'individual')> Persona</label>
                <label class="flex items-center gap-2"><input type="radio" name="donor_type" value="organization" @checked(old('donor_type') === 'organization')> Empresa u organización</label>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2" data-individual>
                <div>
                    <label for="first_name" class="block text-sm font-medium">Nombre(s)</label>
                    <input id="first_name" name="first_name" value="{{ old('first_name') }}" class="{{ $input }}" autocomplete="given-name" maxlength="100">
                </div>
                <div>
                    <label for="last_name" class="block text-sm font-medium">Apellido paterno</label>
                    <input id="last_name" name="last_name" value="{{ old('last_name') }}" class="{{ $input }}" autocomplete="family-name" maxlength="100">
                </div>
                <div>
                    <label for="second_last_name" class="block text-sm font-medium">Apellido materno (opcional)</label>
                    <input id="second_last_name" name="second_last_name" value="{{ old('second_last_name') }}" class="{{ $input }}" maxlength="100">
                </div>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2" data-organization>
                <div class="sm:col-span-2">
                    <label for="legal_name" class="block text-sm font-medium">Razón social</label>
                    <input id="legal_name" name="legal_name" value="{{ old('legal_name') }}" class="{{ $input }}" autocomplete="organization" maxlength="255">
                </div>
                <div class="sm:col-span-2">
                    <label for="contact_name" class="block text-sm font-medium">Persona de contacto (opcional)</label>
                    <input id="contact_name" name="contact_name" value="{{ old('contact_name') }}" class="{{ $input }}" autocomplete="name" maxlength="150">
                </div>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="email" class="block text-sm font-medium">Correo electrónico</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" class="{{ $input }}" autocomplete="email" required maxlength="255" aria-describedby="ayuda-correo">
                    <p id="ayuda-correo" class="mt-1 text-sm text-slate-600">Ahí te enviaremos tu agradecimiento y tus comprobantes.</p>
                </div>
                <div>
                    <label for="phone" class="block text-sm font-medium">Teléfono (opcional)</label>
                    <input type="tel" id="phone" name="phone" value="{{ old('phone') }}" class="{{ $input }}" autocomplete="tel" maxlength="30">
                </div>
            </div>
        </fieldset>

        <fieldset class="rounded-lg bg-white p-4 shadow-sm">
            <legend class="px-1 font-semibold">Comprobante fiscal (opcional)</legend>
            <label class="mt-2 flex items-start gap-2">
                <input type="checkbox" name="wants_tax_receipt" value="1" @checked(old('wants_tax_receipt')) data-tax-toggle class="mt-1">
                <span>Quiero mi comprobante fiscal (CFDI) a mi nombre. Necesitamos los datos de tu constancia de situación fiscal.</span>
            </label>
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2" data-tax-fields>
                <div>
                    <label for="rfc" class="block text-sm font-medium">RFC</label>
                    <input id="rfc" name="rfc" value="{{ old('rfc') }}" class="{{ $input }} uppercase" maxlength="13" autocomplete="off">
                </div>
                <div>
                    <label for="tax_postal_code" class="block text-sm font-medium">Código postal fiscal</label>
                    <input id="tax_postal_code" name="tax_postal_code" value="{{ old('tax_postal_code') }}" class="{{ $input }}" inputmode="numeric" maxlength="5" autocomplete="off">
                </div>
                <div class="sm:col-span-2">
                    <label for="tax_name" class="block text-sm font-medium">Nombre o razón social (como en la constancia)</label>
                    <input id="tax_name" name="tax_name" value="{{ old('tax_name') }}" class="{{ $input }}" maxlength="255" autocomplete="off">
                </div>
                <div class="sm:col-span-2">
                    <label for="tax_regime" class="block text-sm font-medium">Régimen fiscal</label>
                    <select id="tax_regime" name="tax_regime" class="{{ $input }}">
                        <option value="">Elige tu régimen</option>
                        @foreach ($regimes as $value => $label)
                            <option value="{{ $value }}" @selected(old('tax_regime') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <p class="mt-3 text-sm text-slate-600">Si no los capturas, tu donativo se registra igual y la Fundación cumple sus obligaciones fiscales.</p>
        </fieldset>

        <fieldset class="rounded-lg bg-white p-4 shadow-sm">
            <legend class="px-1 font-semibold">Privacidad</legend>
            <label class="mt-2 flex items-start gap-2">
                <input type="checkbox" name="privacy_accepted" value="1" required @checked(old('privacy_accepted')) class="mt-1">
                <span>He leído y acepto el <a href="{{ $privacyUrl }}" target="_blank" rel="noopener" class="underline">aviso de privacidad</a>. (Obligatorio)</span>
            </label>
            <label class="mt-3 flex items-start gap-2">
                <input type="checkbox" name="accepts_communications" value="1" @checked(old('accepts_communications')) class="mt-1">
                <span>Acepto recibir comunicaciones informativas de {{ $organization }}, como felicitaciones y noticias. (Opcional; puedes darte de baja cuando quieras.)</span>
            </label>
            <p class="mt-2 text-sm text-slate-600">Siempre te enviaremos el agradecimiento y el recibo de tu donativo. Si pides comprobante fiscal (CFDI), nuestra área de contabilidad lo emite por separado.</p>
        </fieldset>

        <button type="submit" class="w-full rounded-md px-4 py-3 font-semibold text-white" style="background: var(--brand)" data-loading-text="Revisando…">
            Continuar al resumen
        </button>
    </form>
@endsection

@push('scripts')
    <script nonce="{{ Vite::cspNonce() }}">
        (function () {
            const form = document.querySelector('[data-loading-form]');
            if (!form) { return; }
            const toggle = (selector, show) => document.querySelectorAll(selector).forEach((el) => { el.hidden = !show; });
            const sync = () => {
                const other = form.querySelector('[data-other-amount]');
                toggle('[data-custom-amount]', other && other.checked);
                const type = (form.querySelector('input[name="donor_type"]:checked') || {}).value;
                toggle('[data-individual]', type !== 'organization');
                toggle('[data-organization]', type === 'organization');
                const tax = form.querySelector('[data-tax-toggle]');
                toggle('[data-tax-fields]', tax && tax.checked);
            };
            form.addEventListener('change', sync);
            sync();
            form.addEventListener('submit', () => {
                const button = form.querySelector('button[type="submit"]');
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
                button.textContent = button.dataset.loadingText;
            });
            const errors = document.getElementById('errores');
            if (errors) { errors.focus(); }
        })();
    </script>
@endpush
