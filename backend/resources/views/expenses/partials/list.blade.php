{{-- resources/views/expenses/partials/list.blade.php --}}
@php use App\Support\Inr; @endphp
<div class="card mt-4 overflow-x-auto">
    <div class="mb-2 flex items-center justify-between">
        <h2 class="font-semibold">{{ __('expenses.heading') }}</h2>
        <p class="tabular font-bold">{{ __('expenses.month_total') }}: {{ Inr::format($total) }}</p>
    </div>

    @if ($expenses->isEmpty())
        <p class="text-sm text-ink-muted">{{ __('expenses.no_expenses') }}</p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-ink-muted">
                    <th>{{ __('expenses.date') }}</th>
                    <th>{{ __('expenses.category') }}</th>
                    <th>{{ __('expenses.note') }}</th>
                    <th class="text-right">{{ __('expenses.amount') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($expenses as $e)
                    <tr>
                        <td class="tabular">{{ $e->spent_on->format('d M Y') }}</td>
                        <td>{{ __('expenses.categories.' . $e->category) }}</td>
                        <td>{{ $e->note ?? '—' }}</td>
                        <td class="tabular text-right">{{ Inr::format($e->amount) }}</td>
                        <td class="text-right">
                            <form method="POST"
                                  action="{{ route('expenses.destroy', array_filter(['expense' => $e->id, 'business' => $businessId, 'year' => $period->year, 'month' => $period->month])) }}"
                                  onsubmit="return confirm('{{ __('expenses.delete') }}?')">
                                @csrf @method('DELETE')
                                <input type="hidden" name="business" value="{{ $businessId }}">
                                <button type="submit" class="text-danger">{{ __('expenses.delete') }}</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
