<x-filament-widgets::widget>
    <x-filament::section heading="Relación con donantes" description="Tu trabajo de procuración: tareas, seguimientos y donantes sin próxima acción." icon="heroicon-o-user-group">
        @if ($pending !== [])
            <ul class="db-pending">
                @foreach ($pending as $item)
                    <li>
                        @if ($item['url'])
                            <a href="{{ $item['url'] }}" class="db-pending__item">
                                <span>
                                    <span class="db-pending__label">{{ $item['label'] }}</span>
                                    <span class="db-pending__hint">{{ $item['hint'] }}</span>
                                </span>
                                <span @class(['db-pending__count', 'db-pending__count--zero' => $item['count'] === 0])>{{ number_format($item['count']) }}</span>
                            </a>
                        @else
                            <div class="db-pending__item">
                                <span>
                                    <span class="db-pending__label">{{ $item['label'] }}</span>
                                    <span class="db-pending__hint">{{ $item['hint'] }}</span>
                                </span>
                                <span @class(['db-pending__count', 'db-pending__count--zero' => $item['count'] === 0])>{{ number_format($item['count']) }}</span>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            <div class="db-empty">
                <x-filament::icon icon="heroicon-o-user-group" class="db-empty__icon" />
                <p>Sin pendientes de relación con donantes para mostrar.</p>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
