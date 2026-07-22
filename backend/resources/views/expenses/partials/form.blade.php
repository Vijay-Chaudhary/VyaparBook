{{-- resources/views/expenses/partials/form.blade.php --}}
{{-- $editing (Expense|null) — when set, the form PUTs an update. --}}
@php $editing = $editing ?? null; @endphp
<form method="POST"
      action="{{ $editing ? route('expenses.update', array_filter(['expense' => $editing->id, 'business' => $businessId, 'year' => $period->year, 'month' => $period->month])) : route('expenses.store') }}"
      class="card grid gap-3 md:grid-cols-5 md:items-end">
    @csrf
    @if ($editing) @method('PUT') @endif
    <input type="hidden" name="business" value="{{ $businessId }}">
    <input type="hidden" name="year" value="{{ $period->year }}">
    <input type="hidden" name="month" value="{{ $period->month }}">

    <label class="text-sm">
        <span class="block text-ink-muted">{{ __('expenses.category') }}</span>
        <select name="category" class="field-input">
            @foreach ($categories as $key)
                <option value="{{ $key }}" @selected($editing && $editing->category === $key)>
                    {{ __('expenses.categories.' . $key) }}
                </option>
            @endforeach
        </select>
    </label>

    <label class="text-sm">
        <span class="block text-ink-muted">{{ __('expenses.amount') }}</span>
        <input type="number" step="0.01" min="0.01" name="amount" class="field-input"
               value="{{ old('amount', $editing?->amount) }}" required>
    </label>

    <label class="text-sm">
        <span class="block text-ink-muted">{{ __('expenses.date') }}</span>
        <input type="date" name="spent_on" class="field-input"
               value="{{ old('spent_on', $editing?->spent_on?->format('Y-m-d')) }}" required>
    </label>

    <label class="text-sm md:col-span-1">
        <span class="block text-ink-muted">{{ __('expenses.note') }}</span>
        <input type="text" name="note" maxlength="255" class="field-input"
               value="{{ old('note', $editing?->note) }}" placeholder="{{ __('expenses.note_other_hint') }}">
    </label>

    <button type="submit" class="btn-primary">{{ $editing ? __('expenses.update') : __('expenses.save') }}</button>

    @error('category') <p class="md:col-span-5 text-sm text-danger">{{ $message }}</p> @enderror
    @error('amount')   <p class="md:col-span-5 text-sm text-danger">{{ $message }}</p> @enderror
    @error('spent_on') <p class="md:col-span-5 text-sm text-danger">{{ $message }}</p> @enderror
    @error('note')     <p class="md:col-span-5 text-sm text-danger">{{ $message }}</p> @enderror
</form>
