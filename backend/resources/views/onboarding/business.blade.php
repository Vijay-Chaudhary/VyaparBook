@extends('onboarding.layout', ['step' => 1])

@section('title', __('onboarding.create_business') . ' — ' . config('app.name'))
@section('heading', __('onboarding.create_business'))

@section('step')
<form method="POST" action="{{ route('onboarding.business') }}" class="card space-y-4" novalidate>
    @csrf

    <div>
        <label for="name" class="field-label">{{ __('onboarding.business_name') }}</label>
        <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus
               class="field-input @error('name') border-danger @enderror">
        @error('name')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="city" class="field-label">{{ __('onboarding.city') }}</label>
        <input id="city" name="city" type="text" value="{{ old('city') }}" class="field-input">
    </div>

    <div>
        <label for="gstin" class="field-label">{{ __('onboarding.gstin') }}</label>
        <input id="gstin" name="gstin" type="text" value="{{ old('gstin') }}"
               maxlength="15" class="field-input tabular uppercase">
        @error('gstin')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <button type="submit" class="btn-primary w-full">{{ __('onboarding.continue') }}</button>
</form>
@endsection
