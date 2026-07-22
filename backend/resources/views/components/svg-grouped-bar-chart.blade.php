{{-- resources/views/components/svg-grouped-bar-chart.blade.php --}}
@php
    // Class-component public methods are exposed as callable closures, not $this.
    $groupData = $groups();
    $tickData = $ticks();
    $baseline = $zeroBaselinePct();
    $legendData = $legend();

    $plotLeft = 36; $plotRight = 298; $plotW = $plotRight - $plotLeft;
    $count = max(1, count($groupData));
    $slot = $plotW / $count;
    $seriesCount = max(1, count($groupData[0]['bars'] ?? [1]));
    $barW = ($slot * 0.7) / $seriesCount;
    $groupW = $barW * $seriesCount;
@endphp
<figure class="chart">
    <figcaption class="chart-title">{{ $title }}</figcaption>
    {{-- top inset (-6) gives the max y-axis label room so it doesn't clip. --}}
    <svg viewBox="0 -6 300 138" role="img" aria-label="{{ $title }}" class="w-full">
        {{-- y-axis gridlines + labels --}}
        @foreach ($tickData as $t)
            <line x1="{{ $plotLeft }}" y1="{{ $t['yPct'] }}" x2="{{ $plotRight }}" y2="{{ $t['yPct'] }}"
                  stroke="#e5e7eb" stroke-width="0.3"></line>
            <text x="{{ $plotLeft - 2 }}" y="{{ $t['yPct'] + 2 }}" font-size="5"
                  text-anchor="end" fill="#6b7280">{{ $t['label'] }}</text>
        @endforeach
        {{-- zero baseline (bold) --}}
        <line x1="{{ $plotLeft }}" y1="{{ $baseline }}" x2="{{ $plotRight }}" y2="{{ $baseline }}"
              stroke="#9ca3af" stroke-width="0.5"></line>

        {{-- grouped bars --}}
        @foreach ($groupData as $gi => $group)
            @php $slotX = $plotLeft + $gi * $slot + ($slot - $groupW) / 2; @endphp
            @foreach ($group['bars'] as $bi => $bar)
                <rect x="{{ $slotX + $bi * $barW }}" y="{{ $bar['yPct'] }}"
                      width="{{ $barW * 0.9 }}" height="{{ $bar['heightPct'] }}"
                      fill="{{ $bar['color'] }}"></rect>
            @endforeach
            <text x="{{ $plotLeft + $gi * $slot + $slot / 2 }}" y="108" font-size="5"
                  text-anchor="middle" fill="#555">{{ $group['label'] }}</text>
        @endforeach

        {{-- legend --}}
        @php $lx = $plotLeft; @endphp
        @foreach ($legendData as $item)
            <rect x="{{ $lx }}" y="120" width="5" height="5" fill="{{ $item['color'] }}"></rect>
            <text x="{{ $lx + 7 }}" y="124.5" font-size="5" fill="#374151">{{ $item['label'] }}</text>
            @php $lx += 7 + strlen($item['label']) * 3 + 8; @endphp
        @endforeach
    </svg>
</figure>
