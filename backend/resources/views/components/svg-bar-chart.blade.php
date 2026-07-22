{{-- resources/views/components/svg-bar-chart.blade.php --}}
<figure class="chart">
    <figcaption class="chart-title">{{ $title }}</figcaption>
    <svg viewBox="0 0 240 120" role="img" aria-label="{{ $title }}" class="w-full">
        @foreach ($this->bars() as $i => $bar)
            @php
                $barWidth = 240 / max(1, count($this->bars())) * 0.6;
                $gap = 240 / max(1, count($this->bars()));
                $x = $i * $gap + ($gap - $barWidth) / 2;
                $h = $bar['heightPct'] / 100 * 100; // usable height 100
                $y = 100 - $h;
            @endphp
            <rect x="{{ $x }}" y="{{ $y }}" width="{{ $barWidth }}" height="{{ $h }}"
                  fill="#4472C4"></rect>
            <text x="{{ $x + $barWidth / 2 }}" y="115" font-size="6"
                  text-anchor="middle" fill="#555">{{ $bar['label'] }}</text>
        @endforeach
    </svg>
</figure>
