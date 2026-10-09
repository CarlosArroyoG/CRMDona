<x-filament-widgets::widget>
    <div class="db-daily-work">
        <section class="db-panel db-panel--brand">
            <p class="db-eyebrow">Fundación Don Bosco</p>
            <h2 class="db-daily-work__greeting">Hola, {{ $name }}</h2>
            <p class="db-muted">¿Qué necesitas hacer hoy?</p>

            @if ($actions !== [])
                <div class="db-daily-work__actions">
                    @foreach ($actions as $action)
                        <x-filament::button
                            tag="a"
                            :href="$action['url']"
                            :icon="$action['icon']"
                            :color="$action['primary'] ? 'primary' : 'gray'"
                            :outlined="! $action['primary']"
                        >
                            {{ $action['label'] }}
                        </x-filament::button>
                    @endforeach
                </div>
            @endif
        </section>

        @if ($pending !== [])
            <section class="db-panel">
                <h2 class="db-panel__title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="db-panel__title-icon" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Pendientes
                </h2>
                @include('filament.widgets.partials.pending-list', ['pending' => $pending])
            </section>
        @endif
    </div>
</x-filament-widgets::widget>
