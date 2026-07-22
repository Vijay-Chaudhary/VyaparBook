{{-- resources/views/components/svg-bar-chart.blade.php --}}
@php
    // A class component exposes its public method as a callable closure ($bars),
    // not via $this — the sub-view is rendered without an object context.
    $barsData = $bars();
    $count = max(1, count($barsData));
    $barWidth = 240 / $count * 0.6;
    $gap = 240 / $count;
@endphp
<figure class="chart">
    <figcaption class="chart-title">{{ $title }}</figcaption>
    <svg viewBox="0 0 240 120" role="img" aria-label="{{ $title }}" class="w-full">
        @foreach ($barsData as $i => $bar)
            @php
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
