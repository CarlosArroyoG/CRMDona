@extends('layouts.public')

@section('title', $campaign?->name ?? 'Donar')

@php
    $input = 'mt-1 block w-full rounded-lg border border-db-border px-3 py-2.5 focus:border-db-navy focus:outline-none focus:ring-2 focus:ring-db-navy/20';
    $money = fn (string $amount): string => \App\Support\Money::format($amount);
    $recommended = $suggested[1] ?? $suggested[0] ?? null;
@endphp

@section('content')
    <h1 class="text-2xl font-bold text-db-navy sm:text-3xl">
        {{ $campaign !== null ? $campaign->name : 'Haz tu donativo a '.$organization }}
    </h1>
    @if ($campaign?->description)
        <p class="mt-2 text-db-text-muted">{{ $campaign->description }}</p>
    @endif

    @if ($errors->any())
        <div role="alert" class="mt-4 rounded-lg border border-red-300 bg-red-50 p-4 text-red-800" tabindex="-1" id="errores">
            <p class="font-semibold">Revisa lo siguiente:</p>
            <ul class="mt-2 list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="post" action="{{ $action }}" class="mt-6 space-y-5" data-loading-form novalidate>
        @csrf

        {{-- Campo trampa para robots: las personas no lo ven ni lo llenan. --}}
        <div aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden">
            <label for="website">No llenar este campo</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
        </div>

        <fieldset class="rounded-xl border border-db-border bg-db-bg-blue p-4 sm:p-5">
            <legend class="px-1 font-semibold text-db-navy">¿Cada cuándo?</legend>
            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label class="flex min-h-14 cursor-pointer items-center gap-3 rounded-xl border border-db-border bg-db-surface px-4 py-3 text-base transition has-checked:border-db-navy has-checked:ring-2 has-checked:ring-db-navy/30">
                    <input type="radio" name="frequency" value="one_time" class="h-5 w-5 accent-[var(--brand)]" @checked(old('frequency', 'one_time') === 'one_time')>
                    <span>Una sola vez</span>
                </label>
                @if ($acceptsMonthly)
                    <label class="flex min-h-14 cursor-pointer items-center gap-3 rounded-xl border border-db-border bg-db-surface px-4 py-3 text-base transition has-checked:border-db-navy has-checked:ring-2 has-checked:ring-db-navy/30">
                        <input type="radio" name="frequency" value="monthly" class="h-5 w-5 accent-[var(--brand)]" @checked(old('frequency') === 'monthly')>
                        <span>Cada mes (donativo recurrente)</span>
                    </label>
                @endif
            </div>
            @if ($acceptsMonthly)
                <p class="mt-3 text-sm text-db-text-muted" id="ayuda-mensual">El donativo mensual se cobra automáticamente cada mes a la misma tarjeta hasta que decidas cancelarlo.</p>
            @endif
        </fieldset>

        <fieldset class="rounded-xl border border-db-border bg-db-bg-blue p-4 sm:p-5">
            <legend class="px-1 font-semibold text-db-navy">¿Cuánto quieres donar? (MXN)</legend>
            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ($suggested as $amount)
                    <label class="relative flex min-h-16 cursor-pointer items-center justify-center rounded-xl border border-db-border bg-db-surface px-3 py-3 text-center text-base font-semibold transition has-checked:border-db-navy has-checked:bg-db-soft-blue has-checked:ring-2 has-checked:ring-db-navy/30">
                        @if ($amount === $recommended)
                            <span class="absolute -top-2.5 left-1/2 -translate-x-1/2 rounded-full bg-db-yellow px-2 py-0.5 text-xs font-semibold text-db-navy shadow-sm">Sugerido</span>
                        @endif
                        <input type="radio" name="amount" value="{{ $amount }}" class="sr-only" @checked(old('amount', $recommended) === $amount)>
                        <span>{{ $money($amount) }}</span>
                    </label>
                @endforeach
                <label class="col-span-2 flex min-h-14 cursor-pointer items-center justify-center gap-2 rounded-xl border border-db-border bg-db-surface px-4 py-3 text-base transition has-checked:border-db-navy has-checked:ring-2 has-checked:ring-db-navy/30 sm:col-span-4">
                    <input type="radio" name="amount" value="otro" class="h-5 w-5 accent-[var(--brand)]" @checked(old('amount') === 'otro') data-other-amount>
                    <span>Otra cantidad</span>
                </label>
            </div>
            <div class="mt-3" data-custom-amount>
                <label for="custom_amount" class="block text-sm font-medium text-db-text">Importe (si elegiste otra cantidad)</label>
                <input type="text" inputmode="decimal" id="custom_amount" name="custom_amount" value="{{ old('custom_amount') }}" class="{{ $input }}"
                       aria-describedby="ayuda-limites" autocomplete="off">
                <p id="ayuda-limites" class="mt-1 text-sm text-db-text-muted">
                    @if ($limits['min'] !== null) Mínimo {{ $money($limits['min']) }}. @endif
                    @if ($limits['max'] !== null) Máximo {{ $money($limits['max']) }}. @endif
                </p>
            </div>
        </fieldset>

        <fieldset class="rounded-xl border border-db-border bg-db-bg-blue p-4 sm:p-5">
            <legend class="px-1 font-semibold text-db-navy">Tus datos</legend>
            <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label class="flex min-h-12 cursor-pointer items-center gap-2 rounded-xl border border-db-border bg-db-surface px-4 py-2.5 transition has-checked:border-db-navy has-checked:ring-2 has-checked:ring-db-navy/30">
                    <input type="radio" name="donor_type" value="individual" class="h-4 w-4 accent-[var(--brand)]" @checked(old('donor_type', 'individual') === 'individual')> Persona
                </label>
                <label class="flex min-h-12 cursor-pointer items-center gap-2 rounded-xl border border-db-border bg-db-surface px-4 py-2.5 transition has-checked:border-db-navy has-checked:ring-2 has-checked:ring-db-navy/30">
                    <input type="radio" name="donor_type" value="organization" class="h-4 w-4 accent-[var(--brand)]" @checked(old('donor_type') === 'organization')> Empresa u organización
                </label>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2" data-individual>
                <div>
                    <label for="first_name" class="block text-sm font-medium text-db-text">Nombre(s)</label>
                    <input id="first_name" name="first_name" value="{{ old('first_name') }}" class="{{ $input }}" autocomplete="given-name" maxlength="100">
                </div>
                <div>
                    <label for="last_name" class="block text-sm font-medium text-db-text">Apellido paterno</label>
                    <input id="last_name" name="last_name" value="{{ old('last_name') }}" class="{{ $input }}" autocomplete="family-name" maxlength="100">
                </div>
                <div>
                    <label for="second_last_name" class="block text-sm font-medium text-db-text">Apellido materno (opcional)</label>
                    <input id="second_last_name" name="second_last_name" value="{{ old('second_last_name') }}" class="{{ $input }}" maxlength="100">
                </div>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2" data-organization>
                <div class="sm:col-span-2">
                    <label for="legal_name" class="block text-sm font-medium text-db-text">Razón social</label>
                    <input id="legal_name" name="legal_name" value="{{ old('legal_name') }}" class="{{ $input }}" autocomplete="organization" maxlength="255">
                </div>
                <div class="sm:col-span-2">
                    <label for="contact_name" class="block text-sm font-medium text-db-text">Persona de contacto (opcional)</label>
                    <input id="contact_name" name="contact_name" value="{{ old('contact_name') }}" class="{{ $input }}" autocomplete="name" maxlength="150">
                </div>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="email" class="block text-sm font-medium text-db-text">Correo electrónico</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" class="{{ $input }}" autocomplete="email" required maxlength="255" aria-describedby="ayuda-correo">
                    <p id="ayuda-correo" class="mt-1 text-sm text-db-text-muted">Ahí te enviaremos tu agradecimiento y tus comprobantes.</p>
                </div>
                <div>
                    <label for="phone" class="block text-sm font-medium text-db-text">Teléfono (opcional)</label>
                    <input type="tel" id="phone" name="phone" value="{{ old('phone') }}" class="{{ $input }}" autocomplete="tel" maxlength="30">
                </div>
            </div>
        </fieldset>

        <fieldset class="rounded-xl border border-db-border bg-db-bg-blue p-4 sm:p-5">
            <legend class="px-1 font-semibold text-db-navy">Comprobante fiscal (opcional)</legend>
            <label class="mt-2 flex items-start gap-2">
                <input type="checkbox" name="wants_tax_receipt" value="1" @checked(old('wants_tax_receipt')) data-tax-toggle class="mt-1 h-4 w-4 accent-[var(--brand)]">
                <span>Quiero mi comprobante fiscal (CFDI) a mi nombre. Necesitamos los datos de tu constancia de situación fiscal.</span>
            </label>
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2" data-tax-fields>
                <div>
                    <label for="rfc" class="block text-sm font-medium text-db-text">RFC</label>
                    <input id="rfc" name="rfc" value="{{ old('rfc') }}" class="{{ $input }} uppercase" maxlength="13" autocomplete="off">
                </div>
                <div>
                    <label for="tax_postal_code" class="block text-sm font-medium text-db-text">Código postal fiscal</label>
                    <input id="tax_postal_code" name="tax_postal_code" value="{{ old('tax_postal_code') }}" class="{{ $input }}" inputmode="numeric" maxlength="5" autocomplete="off">
                </div>
                <div class="sm:col-span-2">
                    <label for="tax_name" class="block text-sm font-medium text-db-text">Nombre o razón social (como en la constancia)</label>
                    <input id="tax_name" name="tax_name" value="{{ old('tax_name') }}" class="{{ $input }}" maxlength="255" autocomplete="off">
                </div>
                <div class="sm:col-span-2">
                    <label for="tax_regime" class="block text-sm font-medium text-db-text">Régimen fiscal</label>
                    <select id="tax_regime" name="tax_regime" class="{{ $input }}">
                        <option value="">Elige tu régimen</option>
                        @foreach ($regimes as $value => $label)
                            <option value="{{ $value }}" @selected(old('tax_regime') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <p class="mt-3 text-sm text-db-text-muted">Solo los necesitamos si quieres un CFDI a tu nombre; sin ellos tu donativo se registra igual. Estos datos solo los consulta el personal autorizado de Contabilidad, que emite el CFDI fuera de esta plataforma; podemos conservar una copia del CFDI emitido como antecedente de tu donativo.</p>
        </fieldset>

        <fieldset class="rounded-xl border border-db-border bg-db-soft-blue p-4 sm:p-5">
            <legend class="px-1 font-semibold text-db-navy">Privacidad</legend>
            <div class="mt-1 text-sm text-db-text" data-privacy-summary>
                <p>En resumen, así usamos tus datos (el detalle está en el aviso de privacidad):</p>
                <ul class="mt-1 list-disc space-y-1 pl-5">
                    <li>Tu nombre y datos de contacto, para registrar tu donativo y enviarte el agradecimiento y el recibo.</li>
                    <li>Tus datos fiscales, solo si pides CFDI: los recibe el personal autorizado de Contabilidad para emitirlo fuera de esta plataforma.</li>
                    <li>El pago lo procesa la pasarela de pago; no guardamos el número completo de tu tarjeta ni su código de seguridad (CVV).</li>
                    <li>Los mensajes informativos, como felicitaciones y noticias, solo si los aceptas abajo; puedes darte de baja con el enlace de cada correo.</li>
                </ul>
            </div>
            <label class="mt-3 flex items-start gap-2">
                <input type="checkbox" name="privacy_accepted" value="1" required @checked(old('privacy_accepted')) class="mt-1 h-4 w-4 accent-[var(--brand)]">
                <span>He leído y acepto el <a href="{{ $privacyUrl }}" target="_blank" rel="noopener" class="font-medium text-db-blue underline underline-offset-2">aviso de privacidad</a>@if ($privacyVersion) (versión {{ $privacyVersion }})@endif. (Obligatorio)</span>
            </label>
            <label class="mt-3 flex items-start gap-2">
                <input type="checkbox" name="accepts_communications" value="1" @checked(old('accepts_communications')) class="mt-1 h-4 w-4 accent-[var(--brand)]">
                <span>Acepto recibir comunicaciones informativas de {{ $organization }}, como felicitaciones y noticias. (Opcional; puedes darte de baja cuando quieras.)</span>
            </label>
            <p class="mt-2 text-sm text-db-text-muted">El agradecimiento y el recibo de tu donativo te llegan siempre, aunque no aceptes mensajes informativos, porque son parte del donativo. Si pides comprobante fiscal (CFDI), nuestra área de contabilidad lo emite por separado.</p>
        </fieldset>

        <button type="submit" class="min-h-14 w-full rounded-xl px-4 py-4 text-base font-semibold text-white shadow-sm transition hover:brightness-110 sm:text-lg" style="background: var(--brand)" data-loading-text="Revisando…">
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
