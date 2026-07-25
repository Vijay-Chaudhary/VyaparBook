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
