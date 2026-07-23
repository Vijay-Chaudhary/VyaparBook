{{-- resources/views/purchases/partials/list.blade.php --}}
@php use App\Support\Inr; @endphp
<div class="card mt-4 overflow-x-auto">
    <div class="mb-2 flex items-center justify-between">
        <h2 class="font-semibold">{{ __('purchases.heading') }}</h2>
        <p class="tabular font-bold">{{ __('purchases.month_total') }}: {{ Inr::format($total) }}</p>
    </div>

    @if ($purchases->isEmpty())
        <p class="text-sm text-ink-muted">{{ __('purchases.no_purchases') }}</p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-ink-muted">
                    <th>{{ __('purchases.date') }}</th>
                    <th>{{ __('purchases.supplier') }}</th>
                    <th>{{ __('purchases.material') }}</th>
                    <th class="text-right">{{ __('purchases.qty') }}</th>
                    <th class="text-right">{{ __('purchases.unit_cost') }}</th>
                    <th class="text-right">{{ __('purchases.total') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($purchases as $p)
                    <tr>
                        <td class="tabular">{{ $p->purchase_date->format('d M Y') }}</td>
                        <td>{{ $p->supplier?->name ?? '—' }}</td>
                        <td>{{ $p->rawMaterial?->name ?? '—' }}</td>
                        <td class="tabular text-right">{{ $p->qty }}</td>
                        <td class="tabular text-right">{{ Inr::format($p->unit_cost) }}</td>
                        <td class="tabular text-right">{{ Inr::format($p->total) }}</td>
                        <td class="text-right">
                            {{-- Deleting reverses the linked stock-in, so the confirm
                                 says so: on-hand will drop by this quantity. --}}
                            <form method="POST"
                                  action="{{ route('purchases.destroy', array_filter(['purchase' => $p->id, 'business' => $businessId, 'year' => $period->year, 'month' => $period->month])) }}"
                                  onsubmit="return confirm('{{ __('purchases.delete_confirm') }}')">
                                @csrf @method('DELETE')
                                <input type="hidden" name="business" value="{{ $businessId }}">
                                <button type="submit" class="text-danger">{{ __('purchases.delete') }}</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
