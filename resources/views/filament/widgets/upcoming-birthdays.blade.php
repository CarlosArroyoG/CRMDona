<x-filament-widgets::widget>
    <x-filament::section heading="Cumpleaños próximos (7 días)">
        @forelse ($birthdays as $birthday)
            <div style="display:flex;justify-content:space-between;gap:12px;padding:6px 0;border-bottom:1px solid rgba(128,128,128,.15);">
                <span>{{ $birthday['name'] }}</span>
                <span style="white-space:nowrap;opacity:.8;">
                    {{ $birthday['days'] === 0 ? 'Hoy' : ($birthday['days'] === 1 ? 'Mañana' : $birthday['date']) }}
                    · {{ $birthday['greeted'] ? 'Recibirá felicitación' : 'Sin felicitación automática' }}
                </span>
            </div>
        @empty
            <p style="opacity:.7;">Nadie cumple años en los próximos 7 días.</p>
        @endforelse
    </x-filament::section>
</x-filament-widgets::widget>
