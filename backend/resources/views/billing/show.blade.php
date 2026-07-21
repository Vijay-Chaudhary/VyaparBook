@extends('layouts.app')

@section('title', __('billing.title') . ' — ' . config('app.name'))

{{-- Billing & plan (docs/frontend-plan.md §7 Phase 6). Owner-only, online-only.
     A dunning (read_only) owner lands here to record a payment and recover — the
     page never blocks, it is the way OUT of the block (PRD §15). --}}

@php
    // One banner at the top, chosen from the subscription state. read_only is the
    // loudest (writes are paused); an expired period warns; a live trial informs;
    // an active Pro plan reassures. Colours use the AA-clear semantic tokens.
    $banner = match ($status) {
        'read_only' => ['tone' => 'danger', 'text' => __('billing.read_only_banner'), 'role' => 'alert'],
        'past_due', 'canceled' => ['tone' => 'warning', 'text' => __('billing.past_due_banner'), 'role' => 'status'],
        'trialing' => ['tone' => 'warning', 'text' => trans_choice('billing.trial_banner', $trialDaysLeft, ['days' => $trialDaysLeft]), 'role' => 'status'],
        'active' => ['tone' => 'success', 'text' => __('billing.active_banner'), 'role' => 'status'],
        default => null,
    };

    $tone = [
        'danger' => 'border-danger bg-red-50 text-danger',
        'warning' => 'border-warning bg-amber-50 text-warning',
        'success' => 'border-success bg-green-50 text-success',
    ];

    $statusLabels = [
        'pending' => __('billing.status_pending'),
        'verified' => __('billing.status_verified'),
        'rejected' => __('billing.status_rejected'),
    ];
    $statusTone = [
        'pending' => 'text-warning',
        'verified' => 'text-success',
        'rejected' => 'text-danger',
    ];
@endphp

@section('content')
<div class="mx-auto max-w-md space-y-4 px-4 py-6">
    <h1 class="text-2xl font-bold">{{ __('billing.heading') }}</h1>

    {{-- Confirmation after recording a payment. --}}
    @if (session('billing_status') === 'payment_recorded')
        <div role="status" class="rounded-lg border border-success bg-green-50 p-3 text-sm text-success">
            {{ __('billing.payment_recorded') }}
        </div>
    @endif

    {{-- The single state banner. --}}
    @if ($banner)
        <div role="{{ $banner['role'] }}" class="rounded-lg border p-3 text-sm {{ $tone[$banner['tone']] }}">
            {{ $banner['text'] }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert" class="rounded-lg border border-danger bg-red-50 p-3">
            <ul class="list-inside list-disc text-sm text-danger">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Plan + usage. --}}
    <section class="card space-y-3" aria-labelledby="plan-heading">
        <div class="flex items-baseline justify-between gap-2">
            <span class="text-sm text-ink-muted">{{ __('billing.current_plan') }}</span>
            <span id="plan-heading" class="text-lg font-bold">
                {{ $plan === 'pro' ? __('billing.plan_pro') : __('billing.plan_free') }}
            </span>
        </div>

        {{-- When the plan is time-bounded, say until when. --}}
        @if ($status === 'active' && $currentPeriodEnd)
            <p class="text-sm text-ink-muted">
                {{ __('billing.renews_on', ['date' => $currentPeriodEnd->translatedFormat('d M Y')]) }}
            </p>
        @elseif ($status === 'trialing' && $trialEndsAt)
            <p class="text-sm text-ink-muted">
                {{ __('billing.trial_ends_on', ['date' => $trialEndsAt->translatedFormat('d M Y')]) }}
            </p>
        @endif

        <hr class="border-hairline">

        <p class="text-sm font-medium text-ink-muted">{{ __('billing.usage') }}</p>

        {{-- customers: limit is null when unlimited (Pro). --}}
        <div class="flex items-center justify-between gap-2">
            <span>{{ __('billing.customers') }}</span>
            <span class="tabular {{ $overLimit['customers'] ? 'font-bold text-danger' : '' }}">
                {{ $usage['customers'] }}
                @if ($limits['max_customers'] !== null)
                    <span class="text-ink-muted">{{ __('billing.of') }} {{ $limits['max_customers'] }}</span>
                @else
                    <span class="text-ink-muted">/ {{ __('billing.unlimited') }}</span>
                @endif
                @if ($overLimit['customers'])
                    · {{ __('billing.over_limit') }}
                @endif
            </span>
        </div>

        <div class="flex items-center justify-between gap-2">
            <span>{{ __('billing.staff') }}</span>
            <span class="tabular {{ $overLimit['users'] ? 'font-bold text-danger' : '' }}">
                {{ $usage['users'] }}
                @if ($limits['max_users'] !== null)
                    <span class="text-ink-muted">{{ __('billing.of') }} {{ $limits['max_users'] }}</span>
                @else
                    <span class="text-ink-muted">/ {{ __('billing.unlimited') }}</span>
                @endif
                @if ($overLimit['users'])
                    · {{ __('billing.over_limit') }}
                @endif
            </span>
        </div>
    </section>

    {{-- Record a payment to upgrade / renew / recover. Always available: even a
         Pro tenant renews here, and a read_only tenant recovers here. --}}
    <section class="card space-y-3" aria-labelledby="upgrade-heading">
        <h2 id="upgrade-heading" class="text-lg font-bold">{{ __('billing.upgrade_heading') }}</h2>
        <p class="text-sm text-ink-muted">{{ __('billing.upgrade_hint') }}</p>

        <form method="POST" action="{{ route('billing.payment') }}" class="space-y-3" novalidate>
            @csrf
            <input type="hidden" name="business" value="{{ $businessId }}">
            <input type="hidden" name="plan" value="pro">

            <div>
                <label for="amount" class="field-label">{{ __('billing.amount') }}</label>
                <input id="amount" name="amount" type="number" inputmode="decimal" min="1" step="0.01"
                       value="{{ old('amount') }}" required class="field-input tabular">
                <p class="mt-1 text-xs text-ink-muted">{{ __('billing.gst_note') }}</p>
            </div>

            <div>
                <label for="mode" class="field-label">{{ __('billing.mode') }}</label>
                <select id="mode" name="mode" class="field-input">
                    <option value="upi" @selected(old('mode') === 'upi')>{{ __('billing.mode_upi') }}</option>
                    <option value="bank" @selected(old('mode') === 'bank')>{{ __('billing.mode_bank') }}</option>
                    <option value="manual" @selected(old('mode') === 'manual')>{{ __('billing.mode_manual') }}</option>
                </select>
            </div>

            <div>
                <label for="period_months" class="field-label">{{ __('billing.period_months') }}</label>
                <select id="period_months" name="period_months" class="field-input tabular">
                    @foreach ([1, 3, 6, 12] as $months)
                        <option value="{{ $months }}" @selected((int) old('period_months', 12) === $months)>{{ $months }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="reference" class="field-label">{{ __('billing.reference') }}</label>
                <input id="reference" name="reference" type="text" maxlength="100"
                       value="{{ old('reference') }}" class="field-input">
            </div>

            <div>
                <label for="note" class="field-label">{{ __('billing.note') }}</label>
                <input id="note" name="note" type="text" maxlength="255"
                       value="{{ old('note') }}" class="field-input">
            </div>

            <button type="submit" class="btn-primary w-full">{{ __('billing.record_payment') }}</button>
        </form>
    </section>

    {{-- Payment history: append-only, newest first. --}}
    <section class="card space-y-2" aria-labelledby="history-heading">
        <h2 id="history-heading" class="text-lg font-bold">{{ __('billing.history') }}</h2>

        @if ($payments->isEmpty())
            <p class="text-sm text-ink-muted">{{ __('billing.no_payments') }}</p>
        @else
            <ul class="divide-y divide-hairline">
                @foreach ($payments as $payment)
                    <li class="flex items-start justify-between gap-3 py-2">
                        <div>
                            <p class="tabular font-medium">₹{{ number_format((float) $payment->amount, 2) }}</p>
                            <p class="text-xs text-ink-muted">
                                {{ $payment->created_at?->translatedFormat('d M Y') }}
                                · {{ __('billing.' . ($payment->mode === 'bank' ? 'mode_bank' : ($payment->mode === 'manual' ? 'mode_manual' : 'mode_upi'))) }}
                            </p>
                            <p class="text-xs text-ink-muted tabular">
                                {{ __('billing.incl_gst', ['amount' => '₹' . number_format((float) $payment->gst_amount, 2)]) }}
                            </p>
                        </div>
                        <span class="text-sm font-medium {{ $statusTone[$payment->status] ?? 'text-ink-muted' }}">
                            {{ $statusLabels[$payment->status] ?? $payment->status }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <div class="text-center">
        <a href="{{ route('app') }}" class="min-h-tap inline-flex items-center text-sm text-brand">
            ← {{ __('billing.back_to_app') }}
        </a>
    </div>
</div>
@endsection
