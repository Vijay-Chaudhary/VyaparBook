@props(['status'])

{{-- Subscription status as a colour-coded badge. Tones use the AA-clear semantic
     tokens: read_only/canceled read as danger, past_due warns, active/trialing are
     positive. An unknown status degrades to a neutral grey rather than throwing. --}}

@php
    $tone = match ($status) {
        'active', 'trialing' => 'border-success text-success bg-green-50',
        'past_due' => 'border-warning text-warning bg-amber-50',
        'read_only', 'canceled' => 'border-danger text-danger bg-red-50',
        default => 'border-hairline text-ink-muted bg-canvas',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium $tone"]) }}>
    {{ $status }}
</span>
