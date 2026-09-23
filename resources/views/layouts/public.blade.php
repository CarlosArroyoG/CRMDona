<!DOCTYPE html>
<html lang="es-MX">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Donar') · {{ $organization }}</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css'])
    @endif
    <style nonce="{{ Vite::cspNonce() }}">
        :root { --brand: {{ $colors['primary'] }}; --brand-accent: {{ $colors['secondary'] }}; }
        .sr-only { position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); border:0; }
    </style>
    @stack('head')
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    <a href="#contenido" class="sr-only focus:not-sr-only">Saltar al contenido</a>
    <header class="bg-white shadow-sm" style="border-top: 4px solid var(--brand)">
        <div class="mx-auto flex max-w-2xl items-center gap-3 px-4 py-3">
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $organization }}" class="h-10 w-auto">
            @else
                <span class="text-lg font-semibold" style="color: var(--brand)">{{ $organization }}</span>
            @endif
        </div>
    </header>

    <main id="contenido" class="mx-auto max-w-2xl px-4 py-6 sm:py-10">
        @yield('content')
    </main>

    <footer class="mx-auto max-w-2xl px-4 pb-10 text-sm text-slate-600">
        <p>{{ $organization }}.
            @if ($privacyUrl)
                <a href="{{ $privacyUrl }}" target="_blank" rel="noopener" class="underline">Aviso de privacidad</a>.
            @endif
        </p>
    </footer>
    @stack('scripts')
</body>
</html>
