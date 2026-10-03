@extends('layouts.app')
@section('title', 'Menu')
@section('eyebrow', 'Administration')
@section('page-title', 'Menu management')

@section('content')
<div class="page-heading"><div><h2>Build your digital menu</h2><p>Organize categories, detailed dishes, photos, pricing, and live availability for every device.</p></div></div>
<section class="stats-grid">
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="menu" /></span></div><div class="stat-card__value">{{ $categories->count() }}</div><div class="stat-card__label">Categories</div></article>
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="order" /></span></div><div class="stat-card__value">{{ $categories->sum(fn($c)=>$c->menuItems->count()) }}</div><div class="stat-card__label">Menu items</div></article>
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="check" /></span></div><div class="stat-card__value">{{ $categories->sum(fn($c)=>$c->menuItems->where('is_available',true)->count()) }}</div><div class="stat-card__label">Available now</div></article>
    <article class="card stat-card"><div class="stat-card__top"><span class="stat-card__icon"><x-icon name="clock" /></span></div><div class="stat-card__value">{{ round($categories->flatMap->menuItems->avg('preparation_minutes') ?: 0) }}</div><div class="stat-card__label">Average prep minutes</div></article>
</section>

<div class="content-grid content-grid--equal">
    <section class="card"><div class="card__header"><div><h2>New category</h2><p>Create a section for related dishes</p></div></div><form class="card__body" method="post" action="{{ route('admin.categories.store') }}">@csrf<div class="form-grid"><label class="field">Category name<input name="name" placeholder="Desserts" value="{{ old('name') }}" required></label><label class="field">Display order<input type="number" name="sort_order" value="{{ old('sort_order',0) }}" min="0" required></label><label class="field field--full">Description<textarea name="description" placeholder="Optional short description">{{ old('description') }}</textarea></label></div><div class="form-actions"><button class="button" type="submit"><x-icon name="plus" :size="16" /> Add category</button></div></form></section>
    <section class="card"><div class="card__header"><div><h2>New menu item</h2><p>Add guest details, photo, pricing, allergens, and choices</p></div></div><form class="card__body" method="post" enctype="multipart/form-data" action="{{ route('admin.menu-items.store') }}">@csrf<div class="form-grid form-grid--3">
        <label class="field">Category<select name="category_id" required><option value="">Select</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('category_id')==$category->id)>{{ $category->name }}</option>@endforeach</select></label>
        <label class="field">Item name<input name="name" value="{{ old('name') }}" required></label>
        <label class="field">Food photo<input type="file" name="image" accept="image/jpeg,image/png,image/webp"><small>JPEG, PNG, or WebP up to 5 MB</small></label>
        <label class="field">Price<div class="input-group"><input type="number" step="0.01" min="0" name="price" value="{{ old('price') }}" required><span class="input-group__suffix">{{ $appSettings?->currency ?: 'INR' }}</span></div></label>
        <label class="field">Offer price<input type="number" step="0.01" min="0" name="discount_price" value="{{ old('discount_price') }}" placeholder="Optional"></label>
        <label class="field">Food type<select name="food_type"><option value="veg">Vegetarian</option><option value="non_veg">Non-vegetarian</option><option value="egg">Egg</option><option value="other">Other</option></select></label>
        <label class="field">Spice level<select name="spice_level"><option value="none">Not spicy</option><option value="mild">Mild</option><option value="medium">Medium</option><option value="hot">Hot</option></select></label>
        <label class="field">Prep time<input type="number" name="preparation_minutes" min="1" max="240" value="{{ old('preparation_minutes',15) }}"></label>
        <label class="field">Calories<input type="number" name="calories" min="0" max="5000" value="{{ old('calories') }}" placeholder="Optional kcal"></label>
        <label class="field field--full">Card description<input name="short_description" maxlength="180" value="{{ old('short_description') }}" placeholder="One concise line shown on menu cards"></label>
        <label class="field field--full">Full description<textarea name="description" placeholder="Guest-facing preparation and flavour description">{{ old('description') }}</textarea></label>
        <label class="field field--full">Ingredients<textarea name="ingredients" placeholder="Paneer, yoghurt, peppers, mint…">{{ old('ingredients') }}</textarea></label>
        <label class="field field--full">Allergens<input name="allergens" value="{{ old('allergens') }}" placeholder="milk, nuts, gluten (comma separated)"></label>
        <label class="field field--full">Customization JSON<textarea name="customizations" placeholder='[{"name":"Spice","choices":[{"name":"Mild","price":0},{"name":"Hot","price":0}]}]'>{{ old('customizations') }}</textarea><small>Optional structured choices used by Customer tablets.</small></label>
        <label class="check"><input type="checkbox" name="is_recommended" value="1" @checked(old('is_recommended'))> Chef recommended</label><label class="check"><input type="checkbox" name="is_bestseller" value="1" @checked(old('is_bestseller'))> Bestseller</label>
    </div><div class="form-actions"><button class="button" type="submit"><x-icon name="plus" :size="16" /> Add menu item</button></div></form></section>
</div>

<div class="stack" style="margin-top:18px">
@forelse($categories as $category)
    <section class="card">
        <div class="card__header"><div><h2>{{ $category->name }}</h2><p>{{ $category->description ?: $category->menuItems->count().' menu items' }}</p></div><x-status :value="$category->is_active ? 'active' : 'disabled'" /></div>
        <div class="table-wrap"><table class="data-table data-table--responsive"><thead><tr><th>Item</th><th>Type</th><th>Prep</th><th>Price</th><th>Availability</th><th>Action</th></tr></thead><tbody>
        @forelse($category->menuItems as $item)
            <tr>
                <td><div style="display:flex;align-items:center;gap:12px;min-width:250px">
                    @if($item->image_url)<img src="{{ $item->image_url }}?v={{ $item->updated_at?->timestamp }}" alt="{{ $item->name }}" loading="lazy" style="width:64px;height:64px;object-fit:cover;border-radius:13px;background:#f4f6f8">@else<span class="stat-card__icon" style="width:64px;height:64px"><x-icon name="menu" /></span>@endif
                    <span class="table-primary"><strong>{{ $item->name }}</strong><small>{{ $item->short_description ?: $item->description ?: 'No description' }}</small></span>
                </div></td>
                <td data-label="Type">{{ str($item->food_type)->replace('_',' ')->title() }}</td>
                <td data-label="Prep">{{ $item->preparation_minutes ?: '—' }}{{ $item->preparation_minutes ? ' min' : '' }}</td>
                <td data-label="Price">{{ $appSettings?->currency ?: 'INR' }} {{ number_format($item->price,2) }}</td>
                <td data-label="Availability"><x-status :value="$item->is_available ? 'available' : 'disabled'" :label="$item->is_available ? 'Available' : 'Unavailable'" /></td>
                <td data-label="Action"><div style="display:flex;gap:8px;flex-wrap:wrap"><button class="button button--small" type="button" data-menu-edit="menu-edit-{{ $item->id }}">Edit</button><form method="post" action="{{ route('admin.menu-items.toggle',$item) }}">@csrf<button class="button button--secondary button--small">{{ $item->is_available ? 'Mark unavailable' : 'Make available' }}</button></form></div></td>
            </tr>
            <tr id="menu-edit-{{ $item->id }}" hidden><td colspan="6">
                <form method="post" enctype="multipart/form-data" action="{{ route('admin.menu-items.update',$item) }}" style="padding:12px 0">@csrf @method('PUT')
                    <div class="form-grid form-grid--3">
                        <label class="field">Category<select name="category_id" required>@foreach($categories as $option)<option value="{{ $option->id }}" @selected($option->id===$item->category_id)>{{ $option->name }}</option>@endforeach</select></label>
                        <label class="field">Item name<input name="name" value="{{ $item->name }}" required></label>
                        <label class="field">Replace photo<input type="file" name="image" accept="image/jpeg,image/png,image/webp"><small>Leave empty to keep it.</small></label>
                        <label class="field">Price<input type="number" step="0.01" min="0" name="price" value="{{ $item->price }}" required></label>
                        <label class="field">Offer price<input type="number" step="0.01" min="0" name="discount_price" value="{{ $item->discount_price }}"></label>
                        <label class="field">Food type<select name="food_type">@foreach(['veg'=>'Vegetarian','non_veg'=>'Non-vegetarian','egg'=>'Egg','other'=>'Other'] as $value=>$label)<option value="{{ $value }}" @selected($item->food_type===$value)>{{ $label }}</option>@endforeach</select></label>
                        <label class="field">Spice level<select name="spice_level">@foreach(['none'=>'Not spicy','mild'=>'Mild','medium'=>'Medium','hot'=>'Hot'] as $value=>$label)<option value="{{ $value }}" @selected($item->spice_level===$value)>{{ $label }}</option>@endforeach</select></label>
                        <label class="field">Prep minutes<input type="number" name="preparation_minutes" min="1" max="240" value="{{ $item->preparation_minutes }}"></label>
                        <label class="field">Calories<input type="number" name="calories" min="0" max="5000" value="{{ $item->calories }}"></label>
                        <label class="field field--full">Card description<input name="short_description" maxlength="180" value="{{ $item->short_description }}"></label>
                        <label class="field field--full">Full description<textarea name="description">{{ $item->description }}</textarea></label>
                        <label class="field field--full">Ingredients<textarea name="ingredients">{{ $item->ingredients }}</textarea></label>
                        <label class="field field--full">Allergens<input name="allergens" value="{{ collect($item->allergens)->implode(', ') }}"></label>
                        <label class="field field--full">Customization JSON<textarea name="customizations">{{ $item->customizations ? json_encode($item->customizations, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) : '' }}</textarea></label>
                        <label class="check"><input type="checkbox" name="is_recommended" value="1" @checked($item->is_recommended)> Chef recommended</label>
                        <label class="check"><input type="checkbox" name="is_bestseller" value="1" @checked($item->is_bestseller)> Bestseller</label>
                        @if($item->image_path)<label class="check"><input type="checkbox" name="remove_image" value="1"> Remove current photo</label>@endif
                    </div>
                    <div class="form-actions"><button class="button" type="submit"><x-icon name="check" :size="16" /> Save item</button><button class="button button--secondary" type="button" data-menu-edit="menu-edit-{{ $item->id }}">Cancel</button></div>
                </form>
            </td></tr>
        @empty
            <tr><td colspan="6"><x-empty-state icon="menu" title="No items in this category" /></td></tr>
        @endforelse
        </tbody></table></div>
    </section>
@empty
    <section class="card"><x-empty-state icon="menu" title="Start with your first category" message="Create a category above, then add dishes to it." /></section>
@endforelse
</div>
@push('scripts')
<script>
document.querySelectorAll('[data-menu-edit]').forEach((button) => button.addEventListener('click', () => {
    const row = document.getElementById(button.dataset.menuEdit);
    if (row) row.hidden = !row.hidden;
}));
</script>
@endpush
@endsection
