# TallStackUI Conventions

Flux uses [TallStackUI](https://tallstackui.com) exclusively for its Blade component layer. **Do not mix in WireUI, Mary UI, raw form controls, or other component libraries** — the styling, the modal infrastructure, the toast integration, and the broadcast wiring all assume TallStackUI.

This is the most common source of friction for new contributors. Worth memorising up front.

## The core components

| Component | Purpose | Wrong alternative |
|---|---|---|
| `<x-input>` | Text input | `<x-inputs.text>` (WireUI) |
| `<x-number>` | Numeric input | `<x-inputs.number>` |
| `<x-select.native>` | Static dropdown | `<x-select>` |
| `<x-select.styled>` | Async / searchable dropdown | (none) |
| `<x-toggle>` | Boolean switch | `<x-checkbox>` |
| `<x-button>` | Button | `<button>` (no styling) |
| `<x-card>` | Container / surface | manual `<div class="bg-white rounded ...">` |
| `<x-modal>` | Modal dialog | `<x-dialog>` (WireUI) |
| `<x-badge>` | State / status pill | manual span |
| `<x-table>` + `<x-table.th>` + `<x-table.td>` | Static table | manual `<table>` |
| `<x-tabs>` + `<x-tab>` | Tab UI | manual JS tabs |
| `<x-radio>` | Single-choice | `<input type="radio">` |
| `<x-textarea>` | Multi-line text | `<textarea>` |
| `<x-date>` | Date picker | `<input type="date">` (no localisation) |
| `<x-tooltip>` | Hover hint | manual title attr |
| `<x-error>` | Validation error display | manual `@error` block |

## Components that don't exist in TallStackUI (Flux)

If you find yourself reaching for these, they don't exist. Use the suggested alternative:

- `<x-stats>` — use `<x-card>` with a custom inner layout.
- `<x-progress>` — use Tailwind classes for the progress bar manually, or rely on the toast notification's progress bar.
- `<x-checkbox>` — use `<x-toggle>`.
- `<x-inputs.*>` — use `<x-input>`, `<x-number>`, etc.

## Buttons: `text` is a prop, not a slot

```blade
{{-- CORRECT --}}
<x-button text="{{ __('Save') }}" color="primary" icon="check" />

{{-- WRONG --}}
<x-button color="primary">{{ __('Save') }}</x-button>
```

The `text` prop is what the component renders. Slot content is ignored or treated as additional markup. Always use `text=...`.

For translated text:

```blade
<x-button :text="__('Save')" color="primary" />
```

The `:text=` (with colon) passes a PHP expression. This is the canonical form when the text is translated.

## Modals: `:title` prop, named footer slot

```blade
<x-modal :id="$modalId" size="xl" :title="__('Edit Voucher')">
    <form wire:submit.prevent="save" class="space-y-4">
        {{-- ... --}}
    </form>

    <x-slot:footer>
        <x-button :text="__('Cancel')" color="secondary" flat
                  x-on:click="$modalClose('{{ $modalId }}')" />
        <x-button :text="__('Save')" color="primary" wire:click="save" />
    </x-slot:footer>
</x-modal>
```

Notes:

- `:title=` is a prop. Both the prop form and the older `<x-slot:title>` form work; the prop form is canonical and shorter.
- `<x-slot:footer>` is a named slot for the footer (right-aligned by default, with the proper button spacing).
- `$modalClose($id)` is an Alpine helper provided by TallStackUI.

To open a modal:

```blade
<x-button :text="__('New')" wire:click="$modalOpen('{{ $modalId }}')" />
```

(Or trigger via Alpine `$modalOpen` from any element.)

## Async selects (`<x-select.styled>`)

For dropdowns backed by a search endpoint:

```blade
<x-select.styled
    wire:model="form.contact_id"
    :label="__('Contact')"
    select="value:id"
    unfiltered
    :request="['url' => route('search', \FluxErp\Models\Contact::class), 'method' => 'POST']"
/>
```

Required attributes:

- **`select="value:id"`** — the field used as the option value. For models that implement `InteractsWithDataTables`, the response auto-includes `id`, `description`, `label`, so `select="value:id"` is enough. For models that don't, specify the full mapping: `select="label:name|description:code|value:id"`.
- **`unfiltered`** — disables the client-side filter (the server does the searching).
- **`:request`** — the search request. `route('search', Model::class)` is the conventional URL.

For models without the `Searchable` trait, you must also specify which fields to search via SQL `LIKE`:

```blade
<x-select.styled
    wire:model="form.country_id"
    :label="__('Country')"
    select="label:name|value:id"
    unfiltered
    :request="[
        'url' => route('search', \FluxErp\Models\Country::class),
        'method' => 'POST',
        'params' => [
            'searchFields' => ['name', 'iso_alpha2'],
        ],
    ]"
/>
```

For more constraints (tenant filter, eager loads, additional where clauses):

```blade
<x-select.styled
    wire:model="form.address_id"
    :label="__('Address')"
    select="value:id"
    unfiltered
    :request="[
        'url' => route('search', \FluxErp\Models\Address::class),
        'method' => 'POST',
        'params' => [
            'fields' => ['contact_id', 'name'],
            'with' => 'contact.media',
            'where' => [['tenant_id', '=', $tenantId]],
        ],
    ]"
/>
```

The full search-endpoint parameter reference is in [Search endpoint](../8-routes-and-search/2-search-endpoint.md).

### `x-bind:disabled` on a styled select

`<x-select.styled>` doesn't propagate `x-bind:disabled` to the inner control. Wrap the select to disable visually:

```blade
<div x-bind:class="!edit && 'pointer-events-none'">
    <x-select.styled wire:model="form.country_id" ... />
</div>
```

Or use `x-bind:disabled` on a native select (`<x-select.native>`) where it _is_ propagated.

## Toggles, not checkboxes

```blade
<x-toggle wire:model="form.is_active" :label="__('Active')" />
```

Booleans are toggles. `<x-checkbox>` doesn't exist as a Flux component; use `<x-toggle>`.

## Confirm dialogs

```blade
<x-button
    text="{{ __('Delete') }}"
    color="danger"
    wire:click="delete"
    wire:flux-confirm.type.error="{{ __('wire:confirm.delete', ['model' => __('Voucher')]) }}"
/>
```

Modifier types: `success`, `error`, `warning`, `info` — affecting the dialog's color and icon.

## Tables (static)

```blade
<x-table>
    <x-slot:header>
        <x-table.th>{{ __('Code') }}</x-table.th>
        <x-table.th>{{ __('Amount') }}</x-table.th>
    </x-slot:header>

    @foreach ($vouchers as $voucher)
        <x-table.tr>
            <x-table.td>{{ $voucher->code }}</x-table.td>
            <x-table.td>{{ $voucher->amount }}</x-table.td>
        </x-table.tr>
    @endforeach
</x-table>
```

For dynamic, filterable, sortable tables, use `BaseDataTable` instead — see [Data tables](2-data-tables.md).

## Alpine inside Livewire

Three rules to remember (and they catch every dev once):

### No `$wire.entangle`

```blade
{{-- WRONG --}}
<div x-data="{ value: $wire.entangle('count') }">
    {{-- Maximum call stack size exceeded errors with deep state --}}
</div>

{{-- RIGHT --}}
<div x-data="{}">
    <span x-text="$wire.count"></span>
    <button x-on:click="$wire.count++">+</button>
</div>
```

`$wire` directly accesses Livewire properties. Entanglement causes performance issues (and crashes) with anything more complex than a primitive.

### `x-on:event`, not `@event`

```blade
{{-- WRONG --}}
<button @click="open = true">

{{-- RIGHT --}}
<button x-on:click="open = true">
```

The `@` shorthand is Alpine outside of Livewire. Inside Livewire, `@something` is Blade syntax — you'll get unexpected blade compilation. Use `x-on:event`.

### `x-bind:attr`, not `:attr`

```blade
{{-- WRONG --}}
<div :class="open && 'open'">

{{-- RIGHT --}}
<div x-bind:class="open && 'open'">
```

The `:attr` shorthand is Alpine outside of Livewire. Inside Livewire, `:attr` is Blade prop syntax — Blade tries to resolve it as a PHP expression at compile time. Use `x-bind:attr`.

### `x-cloak` with `x-show`

```blade
<div x-show="visible" x-cloak>...</div>
```

Always pair `x-show` with `x-cloak` to prevent flicker on initial page load (the element is hidden by Tailwind's `[x-cloak] { display: none }` rule until Alpine takes over).

## Form errors

```blade
<x-input wire:model="form.code" :label="__('Code')" />
<x-error name="form.code" />
```

`<x-error>` renders the validation error for the named field. Don't use `@error('field')` directly — `<x-error>` matches the TallStackUI styling.

## Stack naming for cross-package extensibility

Blade `@push`/`@stack` names must follow `{area}-{component}-{purpose}` to avoid collisions:

```blade
{{-- In your package's blade --}}
@push('order-state-card-actions')
    <x-button :text="__('My custom action')" wire:click="customAction" />
@endpush

{{-- In flux-core's order-state-card.blade.php --}}
@stack('order-state-card-actions')
```

Generic names like `'execute-actions'`, `'footer-buttons'`, `'scripts'` cause cross-package collisions — content from one package appears in unrelated places. Always namespace.

See [Conventions](../1-getting-started/1-conventions.md) for the full naming rule.

## Color and styling props

TallStackUI buttons accept `color`:

- `primary` (blue)
- `secondary` (gray)
- `success` (green)
- `danger` / `error` (red)
- `warning` (amber)
- `info` (blue)

Plus modifiers:

- `flat` — no background fill (outline only).
- `outline` — outlined button.
- `ghost` — text-only.
- `solid` (default) — filled.

For non-button components, the same color names apply where styling supports it (badges, alerts).

## Dark mode

Tailwind's `dark:` modifier is supported throughout. The framework respects the user's OS preference plus an explicit toggle. When writing custom views, include `dark:` variants for backgrounds, borders, and text:

```blade
<div class="bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
```

## Related

- [Form objects](1-form-objects.md) — form components in context.
- [Data tables](2-data-tables.md) — dynamic table component.
- [Conventions](../1-getting-started/1-conventions.md) — naming, alpine rules, blade stacks.

[Back to chapter](0-index.md) · [Back to index](../0-index.md)
