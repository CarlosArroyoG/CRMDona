<x-filament-widgets::widget>
    <x-filament::section heading="Relación con donantes" description="Tu trabajo de procuración: tareas, seguimientos y donantes sin próxima acción." icon="heroicon-o-user-group">
        @if ($pending !== [])
            @include('filament.widgets.partials.pending-list', ['pending' => $pending])
        @else
            <div class="db-empty">
                <x-filament::icon icon="heroicon-o-user-group" class="db-empty__icon" />
                <p>Sin pendientes de relación con donantes para mostrar.</p>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
