{{-- resources/views/purchases/partials/form.blade.php --}}
{{--
    A purchase needs both a supplier and a material to exist. Rather than render
    a form that can only fail validation, point the owner at what is missing.
--}}
@if ($suppliers->isEmpty() || $materials->isEmpty())
    <div class="card">
        <p class="text-sm text-ink-muted">
            @if ($suppliers->isEmpty())
                {{ __('purchases.no_suppliers') }}
                <a href="{{ route('suppliers', ['business' => $businessId]) }}"
                   class="text-brand">{{ __('suppliers.add') }}</a>
            @else
                {{ __('purchases.no_materials') }}
            @endif
        </p>
    </div>
@else
<form method="POST" action="{{ route('purchases.store') }}" class="card grid gap-3 md:grid-cols-6 md:items-end">
    @csrf
    <input type="hidden" name="business" value="{{ $businessId }}">
    <input type="hidden" name="year" value="{{ $period->year }}">
    <input type="hidden" name="month" value="{{ $period->month }}">

    <label class="text-sm">
        <span class="block text-ink-muted">{{ __('purchases.supplier') }}</span>
        <select name="supplier_id" class="field-input" required>
            @foreach ($suppliers as $s)
                <option value="{{ $s->id }}" @selected(old('supplier_id') === $s->id)>{{ $s->name }}</option>
            @endforeach
        </select>
    </label>

    <label class="text-sm">
        <span class="block text-ink-muted">{{ __('purchases.material') }}</span>
        <select name="raw_material_id" class="field-input" required>
            @foreach ($materials as $m)
                <option value="{{ $m->id }}" @selected(old('raw_material_id') === $m->id)>{{ $m->name }}</option>
            @endforeach
        </select>
    </label>

    <label class="text-sm">
        <span class="block text-ink-muted">{{ __('purchases.date') }}</span>
        <input type="date" name="purchase_date" class="field-input"
               value="{{ old('purchase_date', now()->format('Y-m-d')) }}" required>
    </label>

    <label class="text-sm">
        <span class="block text-ink-muted">{{ __('purchases.qty') }}</span>
        <input type="number" step="0.001" min="0.001" name="qty" class="field-input"
               value="{{ old('qty') }}" required>
    </label>

    <label class="text-sm">
        <span class="block text-ink-muted">{{ __('purchases.unit_cost') }}</span>
        <input type="number" step="0.01" min="0.01" name="unit_cost" class="field-input"
               value="{{ old('unit_cost') }}" required>
    </label>

    <button type="submit" class="btn-primary">{{ __('purchases.save') }}</button>

    <label class="text-sm md:col-span-5">
        <span class="block text-ink-muted">{{ __('purchases.note') }}</span>
        <input type="text" name="note" maxlength="255" class="field-input" value="{{ old('note') }}">
    </label>

    @error('supplier_id')     <p class="md:col-span-6 text-sm text-danger">{{ $message }}</p> @enderror
    @error('raw_material_id') <p class="md:col-span-6 text-sm text-danger">{{ $message }}</p> @enderror
    @error('purchase_date')   <p class="md:col-span-6 text-sm text-danger">{{ $message }}</p> @enderror
    @error('qty')             <p class="md:col-span-6 text-sm text-danger">{{ $message }}</p> @enderror
    @error('unit_cost')       <p class="md:col-span-6 text-sm text-danger">{{ $message }}</p> @enderror
    @error('note')            <p class="md:col-span-6 text-sm text-danger">{{ $message }}</p> @enderror
</form>
@endif
