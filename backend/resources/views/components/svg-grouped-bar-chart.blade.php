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

{{-- Custom hover tooltip: shipped once with the component, regardless of how
     many charts are on the page. No CSP in this app; a scoped inline script is
     fine and keeps the chart itself dependency-free. Colour-coded to the bar. --}}
@once
@push('head')
<style>
    .chart-bar { transition: opacity .1s; }
    .chart-bar:hover { opacity: .8; cursor: default; }
    .chart-tooltip {
        position: fixed; z-index: 60; display: none; pointer-events: none;
        background: #111827; color: #fff; font-size: 12px; line-height: 1.3;
        padding: 6px 9px; border-radius: 6px; box-shadow: 0 2px 10px rgba(0,0,0,.28);
        white-space: nowrap; transform: translate(-50%, -128%);
    }
    .chart-tooltip .sw { display:inline-block; width:9px; height:9px; border-radius:2px; margin-right:7px; vertical-align:middle; }
    .chart-tooltip .val { font-weight:600; }
    @media (prefers-color-scheme: dark) { .chart-tooltip { background:#e5e7eb; color:#111827; } }
</style>
@endpush
@push('scripts')
<script>
(function () {
    var tip = document.createElement('div');
    tip.className = 'chart-tooltip';
    document.body.appendChild(tip);

    function place(e) { tip.style.left = e.clientX + 'px'; tip.style.top = e.clientY + 'px'; }

    document.addEventListener('mouseover', function (e) {
        var bar = e.target.closest && e.target.closest('.chart-bar');
        if (!bar) return;
        var text = bar.getAttribute('data-tip') || '';
        var color = bar.getAttribute('data-color') || '#4472C4';
        var sep = text.indexOf(': ');
        var head = sep === -1 ? text : text.slice(0, sep + 2);
        var val = sep === -1 ? '' : text.slice(sep + 2);
        tip.textContent = '';
        var sw = document.createElement('span'); sw.className = 'sw'; sw.style.background = color;
        tip.appendChild(sw);
        tip.appendChild(document.createTextNode(head));
        var v = document.createElement('span'); v.className = 'val'; v.textContent = val;
        tip.appendChild(v);
        tip.style.display = 'block';
        place(e);
    });
    document.addEventListener('mousemove', function (e) { if (tip.style.display === 'block') place(e); });
    document.addEventListener('mouseout', function (e) {
        if (e.target.closest && e.target.closest('.chart-bar')) tip.style.display = 'none';
    });
})();
</script>
@endpush
@endonce

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
                <rect class="chart-bar" x="{{ $slotX + $bi * $barW }}" y="{{ $bar['yPct'] }}"
                      width="{{ $barW * 0.9 }}" height="{{ $bar['heightPct'] }}"
                      fill="{{ $bar['color'] }}"
                      data-tip="{{ $group['label'] }} · {{ $bar['label'] }}: {{ $bar['value'] }}"
                      data-color="{{ $bar['color'] }}"
                      aria-label="{{ $group['label'] }} {{ $bar['label'] }} {{ $bar['value'] }}"></rect>
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
