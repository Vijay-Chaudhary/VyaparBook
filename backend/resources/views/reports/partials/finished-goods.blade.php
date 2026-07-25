{{-- resources/views/reports/partials/finished-goods.blade.php --}}
@php use App\Support\Inr; @endphp
<div class="card mt-4 overflow-x-auto">
    <h2 class="mb-2 font-semibold">{{ __('reports.finished_goods') }}</h2>
    <p class="mb-3 text-xs text-ink-muted">{{ __('reports.finished_goods_caption') }}</p>

    @if (empty($report->finishedGoods))
        <p class="text-sm text-ink-muted">{{ __('reports.finished_goods_empty') }}</p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-ink-muted">
                    <th>{{ __('reports.product') }}</th>
                    <th class="text-right">{{ __('reports.produced_kg') }}</th>
                    <th class="text-right">{{ __('reports.sold_kg') }}</th>
                    <th class="text-right">{{ __('reports.on_hand_kg') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report->finishedGoods as $row)
                    <tr>
                        <td>{{ $row->name }}</td>
                        <td class="tabular text-right">{{ rtrim(rtrim($row->producedKg, '0'), '.') ?: '0' }}</td>
                        <td class="tabular text-right">{{ rtrim(rtrim($row->soldKg, '0'), '.') ?: '0' }}</td>
                        {{-- Negative = more sold than recorded as produced. Shown in
                             danger colour because it is a data error worth chasing,
                             not a number to quietly clamp at zero. --}}
                        <td class="tabular text-right font-bold {{ bccomp($row->onHandKg, '0', 3) < 0 ? 'text-danger' : '' }}">
                            {{ rtrim(rtrim($row->onHandKg, '0'), '.') ?: '0' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
