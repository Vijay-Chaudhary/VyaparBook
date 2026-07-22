{{-- resources/views/components/svg-bar-chart.blade.php --}}
@php
    // A class component exposes its public methods as callable closures ($bars,
    // $zeroBaselinePct), not via $this — the sub-view has no object context.
    $barsData = $bars();
    $baseline = $zeroBaselinePct();     // y (0..100) of the zero line
    $count = max(1, count($barsData));
    $barWidth = 240 / $count * 0.6;
    $gap = 240 / $count;
@endphp
<figure class="chart">
    <figcaption class="chart-title">{{ $title }}</figcaption>
    <svg viewBox="0 0 240 120" role="img" aria-label="{{ $title }}" class="w-full">
        {{-- Zero baseline: bars grow up (profit) or down (loss) from this line.
             For an all-positive series it sits at the bottom edge. --}}
        <line x1="0" y1="{{ $baseline }}" x2="240" y2="{{ $baseline }}"
              stroke="#d1d5db" stroke-width="0.4"></line>
        @foreach ($barsData as $i => $bar)
            @php
                $x = $i * $gap + ($gap - $barWidth) / 2;
            @endphp
            <rect x="{{ $x }}" y="{{ $bar['yPct'] }}" width="{{ $barWidth }}" height="{{ $bar['heightPct'] }}"
                  fill="{{ $bar['negative'] ? '#dc2626' : '#4472C4' }}"></rect>
            <text x="{{ $x + $barWidth / 2 }}" y="115" font-size="6"
                  text-anchor="middle" fill="#555">{{ $bar['label'] }}</text>
        @endforeach
    </svg>
</figure>
