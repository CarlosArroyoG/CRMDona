<!DOCTYPE html>
<html lang="es-MX">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Donar') · {{ $organization }}</title>
    <link rel="icon" type="image/png" href="{{ $faviconUrl }}">
    <link rel="apple-touch-icon" href="{{ $faviconUrl }}">
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css'])
    @endif
    <style nonce="{{ Vite::cspNonce() }}">
        :root { --brand: {{ $colors['primary'] }}; --brand-accent: {{ $colors['secondary'] }}; }
        .sr-only { position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); border:0; }
    </style>
    @stack('head')
</head>
<body class="min-h-screen bg-db-bg-blue font-sans text-db-text antialiased">
    <a href="#contenido" class="sr-only focus:not-sr-only">Saltar al contenido</a>

    <header style="background: linear-gradient(135deg, var(--brand), color-mix(in srgb, var(--brand) 78%, black))">
        <div class="mx-auto flex max-w-2xl items-center gap-3 px-4 py-5 sm:py-7">
            @if ($logoUrl)
                {{-- El logotipo es oscuro sobre fondo claro: va sobre una placa blanca. --}}
                <span class="inline-flex rounded-xl bg-white px-3.5 py-2 shadow-md">
                    <img src="{{ $logoUrl }}" alt="{{ $organization }}" class="h-10 w-auto sm:h-12">
                </span>
            @else
                <span class="text-xl font-bold tracking-tight text-white sm:text-2xl">{{ $organization }}</span>
            @endif
        </div>
        <div class="h-1.5" style="background: var(--brand-accent)"></div>
    </header>

    <main id="contenido" class="mx-auto max-w-2xl px-4 py-8 sm:py-12">
        <div class="rounded-2xl bg-db-surface p-5 shadow-lg shadow-db-navy/10 ring-1 ring-db-border sm:p-8">
            @yield('content')
        </div>
    </main>

    <footer class="mt-6 border-t border-db-border bg-db-soft-blue">
        <p class="mx-auto max-w-2xl px-4 py-6 text-sm text-db-text-muted">
            {{ $organization }}.
            @if ($privacyUrl)
                <a href="{{ $privacyUrl }}" target="_blank" rel="noopener" class="db-link">Aviso de privacidad</a>.
            @endif
        </p>
    </footer>
    @stack('scripts')
</body>
</html>
