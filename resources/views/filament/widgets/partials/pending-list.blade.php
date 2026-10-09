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
