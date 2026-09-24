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

    <header style="background: var(--brand)">
        <div class="mx-auto flex max-w-2xl items-center gap-3 px-4 py-4 sm:py-5">
            @if ($logoUrl)
                {{-- El logotipo es oscuro sobre fondo claro: va sobre una placa blanca. --}}
                <span class="inline-flex rounded-lg bg-white px-3 py-1.5 shadow-sm">
                    <img src="{{ $logoUrl }}" alt="{{ $organization }}" class="h-10 w-auto sm:h-12">
                </span>
            @else
                <span class="text-lg font-semibold text-white sm:text-xl">{{ $organization }}</span>
            @endif
        </div>
        <div class="h-1" style="background: var(--brand-accent)"></div>
    </header>

    <main id="contenido" class="mx-auto max-w-2xl px-4 py-6 sm:py-10">
        <div class="rounded-2xl bg-db-surface p-5 shadow-sm ring-1 ring-db-border sm:p-8">
            @yield('content')
        </div>
    </main>

    <footer class="mt-6 border-t border-db-border bg-db-soft-blue">
        <p class="mx-auto max-w-2xl px-4 py-6 text-sm text-db-text-muted">
            {{ $organization }}.
            @if ($privacyUrl)
                <a href="{{ $privacyUrl }}" target="_blank" rel="noopener" class="font-medium text-db-blue underline underline-offset-2">Aviso de privacidad</a>.
            @endif
        </p>
    </footer>
    @stack('scripts')
</body>
</html>
