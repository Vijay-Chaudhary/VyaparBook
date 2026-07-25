{{-- resources/views/reminders/index.blade.php --}}
@extends('layouts.app')

@section('title', __('reminders.title') . ' — ' . config('app.name'))

@section('content')
@php use App\Support\Inr; @endphp
<div class="mx-auto max-w-5xl p-4">
    <header class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-bold">{{ __('reminders.heading') }}</h1>
        <a href="{{ route('reports.dashboard', ['business' => $businessId]) }}"
           class="text-sm text-brand">{{ __('reminders.back_to_dashboard') }}</a>
    </header>

    {{-- The message leaves the OWNER's WhatsApp in 4a, so say so plainly rather
         than letting them discover it when a customer replies to them. --}}
    <p class="mb-3 text-xs text-ink-muted">{{ __('reminders.explainer') }}</p>

    @if (session('status'))
        <p class="card mb-3 text-sm">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p class="card mb-3 text-sm text-danger">{{ session('error') }}</p>
    @endif

    {{-- Phase 4c: what the scheduler intends to send, while it can still be
         stopped. Shown above the overdue list because it is time-sensitive. --}}
    <div class="card mt-4">
        <h2 class="mb-2 font-semibold">{{ __('reminders.scheduled_heading') }}</h2>
        @if ($planned->isEmpty())
            <p class="text-sm text-ink-muted">{{ __('reminders.scheduled_none') }}</p>
        @else
            <table class="w-full text-sm">
                <tbody>
                    @foreach ($planned as $item)
                        <tr>
                            <td>{{ $item->customer?->name ?? '—' }}</td>
                            <td class="tabular text-right">{{ Inr::format($item->amount_at_send) }}</td>
                            <td class="text-right">
                                <form method="POST" action="{{ route('reminders.cancel', ['reminder' => $item->id]) }}">
                                    @csrf
                                    <input type="hidden" name="business" value="{{ $businessId }}">
                                    <button type="submit" class="text-xs text-danger">{{ __('reminders.cancel') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Automation settings. The warning is deliberately not fine print: the
         owner is agreeing that messages go out without asking them again. --}}
    <div class="card mt-4">
        <h2 class="mb-2 font-semibold">{{ __('reminders.automation') }}</h2>
        <form method="POST" action="{{ route('reminders.settings') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <input type="hidden" name="business" value="{{ $businessId }}">
            {{-- What counts as overdue. Editable because a daily-settling
                 retailer and a wholesaler on terms genuinely disagree. --}}
            <label class="text-sm">
                <span class="block text-ink-muted">{{ __('reminders.min_days') }}</span>
                <input type="number" name="reminder_min_days" min="1" max="180" class="field-input"
                       value="{{ old('reminder_min_days', $business->reminder_min_days) }}">
            </label>
            <label class="text-sm">
                <span class="block text-ink-muted">{{ __('reminders.min_outstanding') }}</span>
                <input type="number" step="0.01" min="0" name="reminder_min_outstanding" class="field-input"
                       value="{{ old('reminder_min_outstanding', $business->reminder_min_outstanding) }}">
            </label>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="reminder_auto_enabled" value="1" @checked($business->reminder_auto_enabled)>
                {{ __('reminders.auto_enabled') }}
            </label>
            <label class="text-sm">
                <span class="block text-ink-muted">{{ __('reminders.send_at') }}</span>
                <input type="time" name="reminder_send_at" class="field-input"
                       value="{{ \Illuminate\Support\Str::substr((string) $business->reminder_send_at, 0, 5) }}">
            </label>
            <label class="text-sm">
                <span class="block text-ink-muted">{{ __('reminders.cooldown_days') }}</span>
                <input type="number" name="reminder_cooldown_days" min="0" max="90" class="field-input"
                       value="{{ $business->reminder_cooldown_days }}">
            </label>
            <label class="text-sm">
                <span class="block text-ink-muted">{{ __('reminders.daily_cap') }}</span>
                <input type="number" name="reminder_daily_cap" min="1" max="200" class="field-input"
                       value="{{ $business->reminder_daily_cap }}">
            </label>
            <button type="submit" class="btn-primary">{{ __('reminders.save') }}</button>
        </form>
        <p class="mt-2 text-xs text-ink-muted">{{ __('reminders.min_days_hint') }} {{ __('reminders.min_outstanding_hint') }}</p>
        <p class="mt-2 text-xs text-ink-muted">{{ __('reminders.auto_warning') }}</p>
        <p class="text-xs text-ink-muted">{{ __('reminders.send_at_hint') }} {{ __('reminders.cooldown_hint') }}</p>
        @error('reminder_min_days') <p class="text-xs text-danger">{{ $message }}</p> @enderror
        @error('reminder_min_outstanding') <p class="text-xs text-danger">{{ $message }}</p> @enderror
        @error('reminder_send_at') <p class="text-xs text-danger">{{ $message }}</p> @enderror
        @error('reminder_daily_cap') <p class="text-xs text-danger">{{ $message }}</p> @enderror
    </div>

    <div class="card mt-4 overflow-x-auto">
        <p class="mb-3 text-xs text-ink-muted">
            {{ __('reminders.thresholds', ['amount' => Inr::format($minOutstanding), 'days' => $minDays]) }}
        </p>

        @if (empty($rows))
            <p class="text-sm text-ink-muted">
                {{ __('reminders.empty', ['amount' => Inr::format($minOutstanding), 'days' => $minDays]) }}
            </p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-ink-muted">
                        <th>{{ __('reminders.customer') }}</th>
                        <th>{{ __('reminders.village') }}</th>
                        <th>{{ __('reminders.phone') }}</th>
                        <th class="text-right">{{ __('reminders.outstanding') }}</th>
                        <th class="text-right">{{ __('reminders.days_overdue') }}</th>
                        <th class="text-right">{{ __('reminders.action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>{{ $row->name }}</td>
                            <td>{{ $row->village ?? '—' }}</td>
                            <td class="tabular">{{ $row->phone ?? '—' }}</td>
                            <td class="tabular text-right font-bold">{{ Inr::format($row->outstandingRupees) }}</td>
                            <td class="tabular text-right">
                                {{ $row->daysOverdue }}
                                @if ($row->lastPaymentOn === null)
                                    <span class="block text-xs text-ink-muted">{{ __('reminders.never_paid') }}</span>
                                @endif
                            </td>
                            <td class="text-right">
                                {{-- Phase 4b: what happened last time, so the owner is not
                                     re-tapping blind while a send is still in flight. --}}
                                @if ($row->lastReminderStatus !== null)
                                    <span class="block text-xs {{ $row->lastReminderStatus === 'failed' ? 'text-danger' : 'text-ink-muted' }}">
                                        {{ __('reminders.status.' . $row->lastReminderStatus) }}
                                    </span>
                                @endif
                                @if ($row->sendable)
                                    <form method="POST" action="{{ route('reminders.send', ['customer' => $row->customerId]) }}"
                                          class="inline">
                                        @csrf
                                        <input type="hidden" name="business" value="{{ $businessId }}">
                                        <button type="submit" class="btn-primary">{{ __('reminders.remind') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('reminders.opt_out', ['customer' => $row->customerId]) }}"
                                          class="mt-1 inline">
                                        @csrf
                                        <input type="hidden" name="business" value="{{ $businessId }}">
                                        <button type="submit" class="text-xs text-ink-muted">{{ __('reminders.opt_out') }}</button>
                                    </form>
                                @else
                                    {{-- Why, not just a disabled button: the owner needs to know
                                         whether to fix a number or respect a customer's wish. --}}
                                    <span class="text-xs text-ink-muted">{{ __('reminders.blocked.' . $row->blockedReason) }}</span>
                                    @if ($row->blockedReason === 'opted_out')
                                        <form method="POST" action="{{ route('reminders.opt_in', ['customer' => $row->customerId]) }}"
                                              class="mt-1">
                                            @csrf
                                            <input type="hidden" name="business" value="{{ $businessId }}">
                                            <button type="submit" class="text-xs text-brand">{{ __('reminders.opt_in') }}</button>
                                        </form>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@endsection
