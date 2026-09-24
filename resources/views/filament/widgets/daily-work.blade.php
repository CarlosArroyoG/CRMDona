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
                <h2 class="db-panel__title">Pendientes</h2>
                <ul class="db-pending">
                    @foreach ($pending as $item)
                        <li>
                            <a href="{{ $item['url'] }}" class="db-pending__item">
                                <span>
                                    <span class="db-pending__label">{{ $item['label'] }}</span>
                                    <span class="db-pending__hint">{{ $item['hint'] }}</span>
                                </span>
                                <span @class(['db-pending__count', 'db-pending__count--zero' => $item['count'] === 0])>{{ number_format($item['count']) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-filament-widgets::widget>
