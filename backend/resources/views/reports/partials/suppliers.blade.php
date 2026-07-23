{{-- resources/views/reports/partials/suppliers.blade.php --}}
{{-- The buy-side mirror of partials/customers: what the shop owes, not what is
     owed to it. Names link through to the supplier's payables ledger. --}}
@php use App\Support\Inr; @endphp
<div class="card mt-4 overflow-x-auto">
    <div class="mb-2 flex items-center justify-between">
        <h2 class="font-semibold">{{ __('reports.supplier_outstanding_list') }}</h2>
        <p class="tabular font-bold text-danger">
            {{ Inr::format($report->supplierOutstanding->totalRupees) }}
        </p>
    </div>

    @if ($report->supplierOutstanding->suppliers === [])
        <p class="text-sm text-ink-muted">{{ __('reports.no_suppliers') }}</p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-ink-muted">
                    <th>{{ __('reports.supplier') }}</th>
                    <th>{{ __('reports.village') }}</th>
                    <th class="text-right">{{ __('reports.amount_payable') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report->supplierOutstanding->suppliers as $row)
                    <tr>
                        <td>
                            <a class="text-brand"
                               href="{{ route('suppliers.show', ['supplier' => $row->id, 'business' => $businessId]) }}">
                                {{ $row->name }}
                            </a>
                        </td>
                        <td>{{ $row->village ?? '—' }}</td>
                        <td class="tabular text-right">{{ Inr::format($row->outstandingRupees) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
