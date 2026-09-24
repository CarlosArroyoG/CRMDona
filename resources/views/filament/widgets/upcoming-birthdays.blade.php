<x-filament-widgets::widget>
    <x-filament::section heading="Cumpleaños próximos" description="Donantes que cumplen años hoy y en los próximos 7 días." icon="heroicon-o-cake">
        @forelse ($birthdays as $birthday)
            <div class="db-list-row">
                <span class="db-list-row__main">{{ $birthday['name'] }}</span>
                <span class="db-list-row__meta">
                    <span @class(['db-chip', 'db-chip--accent' => $birthday['days'] === 0])>
                        {{ $birthday['days'] === 0 ? 'Hoy' : ($birthday['days'] === 1 ? 'Mañana' : $birthday['date']) }}
                    </span>
                    {{ $birthday['greeted'] ? 'Recibirá felicitación por correo' : 'Sin felicitación automática' }}
                    @if ($birthday['whatsapp'])
                        {{ ($this->prepareWhatsAppAction)(['donor' => $birthday['id']])->link()->size('sm') }}
                    @endif
                </span>
            </div>
        @empty
            <div class="db-empty">
                <x-filament::icon icon="heroicon-o-cake" class="db-empty__icon" />
                <p>Nadie cumple años en los próximos 7 días.</p>
            </div>
        @endforelse
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
