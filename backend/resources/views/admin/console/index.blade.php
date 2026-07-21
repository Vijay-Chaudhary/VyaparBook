@extends('admin.layout')

@section('title', __('admin.console') . ' — ' . config('app.name'))

@section('console')
<h1 class="mb-4 text-2xl font-bold">{{ __('admin.tenants') }}</h1>

<form method="GET" action="{{ route('admin.console') }}" class="mb-4 flex gap-2">
    <label for="q" class="sr-only">{{ __('admin.search') }}</label>
    <input id="q" name="q" type="search" value="{{ $q }}"
           placeholder="{{ __('admin.search_placeholder') }}" class="field-input flex-1">
    <button type="submit" class="btn-secondary">{{ __('admin.search') }}</button>
</form>

@if ($tenants->isEmpty())
    <p class="text-ink-muted">{{ __('admin.no_tenants') }}</p>
@else
    {{-- Table scrolls inside its own container so the page body never scrolls
         sideways on a narrow screen. --}}
    <div class="overflow-x-auto rounded-xl border border-hairline">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-hairline bg-canvas text-ink-muted">
                <tr>
                    <th scope="col" class="px-3 py-2 font-medium">{{ __('admin.col_shop') }}</th>
                    <th scope="col" class="px-3 py-2 font-medium">{{ __('admin.col_city') }}</th>
                    <th scope="col" class="px-3 py-2 font-medium">{{ __('admin.col_plan') }}</th>
                    <th scope="col" class="px-3 py-2 font-medium">{{ __('admin.col_status') }}</th>
                    <th scope="col" class="px-3 py-2 font-medium">{{ __('admin.col_joined') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-hairline">
                @foreach ($tenants as $tenant)
                    <tr class="hover:bg-canvas">
                        <td class="px-3 py-2">
                            <a href="{{ route('admin.console.show', $tenant->id) }}"
                               class="font-medium text-brand hover:underline">
                                {{ $tenant->name }}
                            </a>
                        </td>
                        <td class="px-3 py-2 text-ink-muted">{{ $tenant->city ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $tenant->subscription_plan ?? $tenant->plan }}</td>
                        <td class="px-3 py-2">
                            @if ($tenant->subscription_status)
                                <x-admin.status-badge :status="$tenant->subscription_status" />
                            @else
                                <span class="text-ink-muted">{{ __('admin.no_subscription') }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 tabular text-ink-muted">
                            {{ \Illuminate\Support\Carbon::parse($tenant->created_at)->format('d M Y') }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $tenants->links() }}
    </div>
@endif
@endsection
