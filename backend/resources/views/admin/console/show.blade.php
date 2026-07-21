@extends('admin.layout')

@section('title', $business->name . ' — ' . __('admin.console'))

@section('console')
<a href="{{ route('admin.console') }}" class="mb-3 inline-flex min-h-tap items-center text-sm text-brand">
    ← {{ __('admin.back_to_console') }}
</a>

<h1 class="text-2xl font-bold">{{ $business->name }}</h1>
<p class="mb-4 text-ink-muted">{{ $business->city ?? '—' }}</p>

<div class="grid gap-4 sm:grid-cols-2">
    {{-- Business detail. --}}
    <section class="card space-y-2" aria-labelledby="detail-heading">
        <h2 id="detail-heading" class="font-bold">{{ __('admin.col_shop') }}</h2>
        <dl class="space-y-1 text-sm">
            <div class="flex justify-between gap-2">
                <dt class="text-ink-muted">{{ __('admin.gstin') }}</dt>
                <dd>{{ $business->gstin ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-2">
                <dt class="text-ink-muted">{{ __('admin.language') }}</dt>
                <dd>{{ $business->default_language }}</dd>
            </div>
            <div class="flex justify-between gap-2">
                <dt class="text-ink-muted">{{ __('admin.created') }}</dt>
                <dd class="tabular">{{ \Illuminate\Support\Carbon::parse($business->created_at)->format('d M Y') }}</dd>
            </div>
        </dl>
    </section>

    {{-- Subscription + suspend/reactivate lever. --}}
    <section class="card space-y-3" aria-labelledby="sub-heading">
        <h2 id="sub-heading" class="font-bold">{{ __('admin.subscription') }}</h2>

        @if ($subscription)
            <div class="flex items-center gap-2">
                <x-admin.status-badge :status="$subscription->status" />
                <span class="text-sm text-ink-muted">{{ $subscription->plan }}</span>
            </div>

            @if ($subscription->status === 'trialing' && $subscription->trial_ends_at)
                <p class="text-sm text-ink-muted">
                    {{ __('admin.trial_ends') }}: {{ \Illuminate\Support\Carbon::parse($subscription->trial_ends_at)->format('d M Y') }}
                </p>
            @elseif ($subscription->current_period_end)
                <p class="text-sm text-ink-muted">
                    {{ __('admin.valid_until') }}: {{ \Illuminate\Support\Carbon::parse($subscription->current_period_end)->format('d M Y') }}
                </p>
            @endif

            {{-- read_only → offer reactivate; anything else → offer suspend. --}}
            @if ($subscription->status === 'read_only')
                <form method="POST" action="{{ route('admin.console.reactivate', $business->id) }}"
                      onsubmit="return confirm('{{ __('admin.reactivate_confirm') }}')">
                    @csrf
                    <button type="submit" class="btn-secondary w-full">{{ __('admin.reactivate') }}</button>
                </form>
            @else
                <form method="POST" action="{{ route('admin.console.suspend', $business->id) }}"
                      onsubmit="return confirm('{{ __('admin.suspend_confirm') }}')" class="space-y-2">
                    @csrf
                    <label for="suspend-reason" class="sr-only">{{ __('admin.reason_optional') }}</label>
                    <input id="suspend-reason" name="reason" type="text" maxlength="255"
                           placeholder="{{ __('admin.reason_optional') }}" class="field-input">
                    <button type="submit" class="btn-secondary w-full text-danger">{{ __('admin.suspend') }}</button>
                </form>
            @endif
        @else
            <p class="text-sm text-ink-muted">{{ __('admin.no_subscription') }}</p>
        @endif
    </section>
</div>

{{-- Members + impersonate. --}}
<section class="card mt-4 space-y-3" aria-labelledby="members-heading">
    <h2 id="members-heading" class="font-bold">{{ __('admin.members') }}</h2>

    @if ($members->isEmpty())
        <p class="text-sm text-ink-muted">{{ __('admin.no_members') }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-hairline text-ink-muted">
                    <tr>
                        <th scope="col" class="py-2 pr-3 font-medium">{{ __('admin.col_name') }}</th>
                        <th scope="col" class="py-2 pr-3 font-medium">{{ __('admin.col_email') }}</th>
                        <th scope="col" class="py-2 pr-3 font-medium">{{ __('admin.col_phone') }}</th>
                        <th scope="col" class="py-2 font-medium">{{ __('admin.col_role') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($members as $member)
                        <tr>
                            <td class="py-2 pr-3">{{ $member->name }}</td>
                            <td class="py-2 pr-3 text-ink-muted">{{ $member->email ?? '—' }}</td>
                            <td class="py-2 pr-3 tabular text-ink-muted">{{ $member->phone ?? '—' }}</td>
                            <td class="py-2">{{ $member->role }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Impersonate-for-support: only roles that actually exist are offered. --}}
        <form method="POST" action="{{ route('admin.console.impersonate', $business->id) }}"
              class="flex flex-wrap items-end gap-2 border-t border-hairline pt-3">
            @csrf
            <div>
                <label for="imp-role" class="field-label">{{ __('admin.role') }}</label>
                <select id="imp-role" name="role" class="field-input">
                    @foreach ($roles as $role)
                        <option value="{{ $role }}" @selected($role === 'owner')>{{ $role }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex-1">
                <label for="imp-reason" class="field-label">{{ __('admin.reason_optional') }}</label>
                <input id="imp-reason" name="reason" type="text" maxlength="255" class="field-input">
            </div>
            <button type="submit" class="btn-secondary">{{ __('admin.impersonate_action') }}</button>
        </form>
        <p class="text-xs text-ink-muted">{{ __('admin.impersonate_hint') }}</p>
        <p class="text-xs text-warning">{{ __('admin.impersonate_readonly_note') }}</p>
    @endif
</section>

{{-- Payments + verify/reject. --}}
<section class="card mt-4 space-y-3" aria-labelledby="payments-heading">
    <h2 id="payments-heading" class="font-bold">{{ __('admin.payments') }}</h2>

    @if ($payments->isEmpty())
        <p class="text-sm text-ink-muted">{{ __('admin.no_payments') }}</p>
    @else
        <ul class="divide-y divide-hairline">
            @foreach ($payments as $payment)
                <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div>
                        <p class="tabular font-medium">₹{{ number_format((float) $payment->amount, 2) }}
                            <span class="text-xs font-normal text-ink-muted">
                                {{ __('admin.incl_gst', ['amount' => '₹' . number_format((float) $payment->gst_amount, 2)]) }}
                            </span>
                        </p>
                        <p class="text-xs text-ink-muted tabular">
                            {{ \Illuminate\Support\Carbon::parse($payment->created_at)->format('d M Y') }}
                            · {{ $payment->mode }}
                            · {{ trans_choice('admin.period_months', $payment->period_months, ['count' => $payment->period_months]) }}
                            @if ($payment->reference) · {{ $payment->reference }} @endif
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        @php
                            $statusLabel = __('admin.status_' . $payment->status);
                        @endphp
                        <span class="text-sm font-medium
                            {{ $payment->status === 'verified' ? 'text-success' : ($payment->status === 'rejected' ? 'text-danger' : 'text-warning') }}">
                            {{ $statusLabel }}
                        </span>

                        {{-- Pending is the only actionable state — verify or reject. --}}
                        @if ($payment->status === 'pending')
                            <form method="POST" action="{{ route('admin.console.payment.verify', [$business->id, $payment->id]) }}"
                                  onsubmit="return confirm('{{ __('admin.verify_confirm') }}')">
                                @csrf
                                <button type="submit" class="btn-secondary px-3 text-success">{{ __('admin.verify') }}</button>
                            </form>
                            <form method="POST" action="{{ route('admin.console.payment.reject', [$business->id, $payment->id]) }}"
                                  onsubmit="return confirm('{{ __('admin.reject_confirm') }}')">
                                @csrf
                                <button type="submit" class="btn-secondary px-3 text-danger">{{ __('admin.reject') }}</button>
                            </form>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
@endsection
