{{-- Lista de pendientes compartida por los widgets del Escritorio (DailyWork, DonorRelationsWork). --}}
<ul class="db-pending">
    @foreach ($pending as $item)
        <li>
            @if ($item['url'])
                <a href="{{ $item['url'] }}" class="db-pending__item">
                    <span>
                        <span class="db-pending__label">{{ $item['label'] }}</span>
                        <span class="db-pending__hint">{{ $item['hint'] }}</span>
                    </span>
                    <span class="inline-flex items-center gap-2">
                        <span @class(['db-pending__count', 'db-pending__count--zero' => $item['count'] === 0])>{{ number_format($item['count']) }}</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="db-pending__chevron" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                    </span>
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
