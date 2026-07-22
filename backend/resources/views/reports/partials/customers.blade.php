{{-- resources/views/reports/partials/customers.blade.php --}}
@php use App\Support\Inr; @endphp
<div class="card mt-4 overflow-x-auto">
    <h2 class="mb-2 font-semibold">{{ __('reports.customer_outstanding_list') }}</h2>
    @if ($report->outstanding->customers === [])
        <p class="text-sm text-ink-muted">{{ __('reports.no_customers') }}</p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-ink-muted">
                    <th>{{ __('reports.customer') }}</th>
                    <th>{{ __('reports.village') }}</th>
                    <th class="text-right">{{ __('reports.amount_due') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report->outstanding->customers as $row)
                    <tr>
                        <td>{{ $row->name }}</td>
                        <td>{{ $row->village ?? '—' }}</td>
                        <td class="tabular text-right">{{ Inr::format($row->outstandingRupees) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
