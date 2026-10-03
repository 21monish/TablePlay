@php($editing = filled($plan))
<div class="form-grid">
    <label class="field field--full">Plan name<input name="name" value="{{ old('name', $plan?->name) }}" maxlength="80" placeholder="Example: Growth" required></label>
    <label class="field field--full">Customer-facing description<textarea name="description" maxlength="500" placeholder="Describe who this package is designed for.">{{ old('description', $plan?->description) }}</textarea></label>
    <label class="field">Monthly price (INR)<input type="number" name="monthly_price" value="{{ old('monthly_price', $plan?->monthly_price) }}" min="0" step="0.01" placeholder="Custom"></label>
    <label class="field">Annual price (INR)<input type="number" name="annual_price" value="{{ old('annual_price', $plan?->annual_price) }}" min="0" step="0.01" placeholder="Custom"></label>
    <label class="field">Table tablet limit<input type="number" name="max_paired_tables" value="{{ old('max_paired_tables', $plan?->max_paired_tables) }}" min="0" max="100000" placeholder="Unlimited"><small>Use 0 for staff-only, or leave blank for unlimited.</small></label>
    <label class="field">Trial days<input type="number" name="trial_days" value="{{ old('trial_days', $plan?->trial_days ?? 0) }}" min="0" max="3650" required></label>
    <label class="field">Grace-period days<input type="number" name="grace_days" value="{{ old('grace_days', $plan?->grace_days ?? 7) }}" min="0" max="365" required><small>Safe renewal window after expiry.</small></label>
    <label class="field">Display order<input type="number" name="sort_order" value="{{ old('sort_order', $plan?->sort_order ?? 50) }}" min="0" max="9999" required></label>
    <label class="field">Sales status<select name="is_active"><option value="1" @selected(old('is_active', $plan?->is_active ?? true))>Published</option><option value="0" @selected(!old('is_active', $plan?->is_active ?? true))>Archived</option></select></label>
    <label class="field field--full">Positioning<select name="is_featured"><option value="0" @selected(!old('is_featured', $plan?->is_featured ?? false))>Standard plan</option><option value="1" @selected(old('is_featured', $plan?->is_featured ?? false))>Recommended plan</option></select><small>Only one plan can be marked recommended.</small></label>
    <fieldset class="feature-picker field--full"><legend>Included features</legend><div>@foreach($featureCatalog as $key => $label)<label class="check-row"><input type="checkbox" name="features[]" value="{{ $key }}" @checked(in_array($key, old('features', collect($plan?->features ?? [])->filter()->keys()->all())))><span>{{ $label }}</span></label>@endforeach</div></fieldset>
</div>
