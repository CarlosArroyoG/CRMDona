<div class="db-timeline">
    @forelse ($entries as $entry)
        <div class="db-list-row">
            <span class="db-list-row__main">
                <span class="db-chip">{{ $entry->source }}</span>
                {{ $entry->title }}
            </span>
            <span class="db-list-row__meta">
                <span>{{ $entry->occurredAt->format('d/m/Y H:i') }}</span>
                @if ($entry->actorName)
                    <span>{{ $entry->actorName }}</span>
                @endif
                @if ($entry->description)
                    <span>{{ $entry->description }}</span>
                @endif
                @if ($entry->url)
                    <a href="{{ $entry->url }}" class="db-list-row__link">Ver</a>
                @endif
            </span>
        </div>
    @empty
        <div class="db-empty">
            <x-filament::icon icon="heroicon-o-clock" class="db-empty__icon" />
            <p>Sin eventos registrados todavía.</p>
        </div>
    @endforelse
</div>
