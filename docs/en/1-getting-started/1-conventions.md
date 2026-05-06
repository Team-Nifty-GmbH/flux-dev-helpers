# Conventions

The set of rules that keep Flux's many layers from drifting. Most are tightenings of common Laravel conventions; a few are Flux-specific and the source of subtle bugs when ignored.

## Package layout

A Flux feature package has this shape:

```
packages/<package-name>/
├── composer.json
├── config/
│   └── <package-name>.php
├── database/
│   ├── migrations/
│   ├── seeders/
│   └── factories/
├── lang/
│   ├── de.json
│   └── en.json
├── resources/
│   ├── js/
│   └── views/
├── routes/
│   ├── web.php
│   ├── api.php
│   └── channels.php
├── src/
│   ├── Actions/
│   ├── Contracts/
│   ├── Enums/
│   ├── Events/
│   ├── Http/Controllers/
│   ├── Jobs/
│   ├── Listeners/
│   ├── Livewire/
│   ├── Models/
│   ├── Notifications/
│   ├── Providers/
│   ├── Rulesets/
│   ├── States/
│   ├── Support/
│   ├── Traits/
│   ├── Widgets/
│   └── <PackageName>ServiceProvider.php
└── tests/
    ├── Feature/
    ├── Unit/
    └── TestCase.php
```

Match the pattern; downstream packages discover by directory name (e.g. `all_models()` walks the morph map; the queue monitor's auto-registration walks the model traits).

For tenant customizations, prefix new models or tables with a short, distinctive token: `ll_test`, `ac_invoice` — never `test`, never the generic `customer_` (which collides with flux-core).

## Naming

| Layer | Convention | Examples |
|---|---|---|
| Models | Singular PascalCase | `Order`, `Address`, `MailAccount` |
| Actions | `<Verb><Domain>` | `CreateOrder`, `UpdateAddress`, `DeleteContact` |
| Rulesets | `<Verb><Domain>Ruleset` | `CreateOrderRuleset`, `BankConnectionRuleset` |
| Forms | `<Domain>Form` or `Edit<Domain>Form` | `OrderForm`, `EditAddressForm` |
| Data tables | `<Domain>List` | `OrderList`, `ContactList` |
| Livewire detail components | `Edit<Domain>` or `<Domain>Detail` | `EditOrder`, `EditMail` |
| Widgets | `<Domain>Widget` or `<Metric>Widget` | `OpenOrdersWidget`, `RevenueWidget` |
| Notifications | `<Domain><State>` | `ExportReady`, `JobFinishedNotification` |
| Channels (broadcast) | derived via `class_to_broadcast_channel()` | `private-order.{order}` |
| Permissions (action) | `action.{snake_domain}.{verb}` | `action.order.create` |
| Permissions (route) | `{snake_domain}.{verb}` | `order.show` |

The action and route permission schemas coexist. Actions describe _what_ a user can do; routes describe _what_ a user can see.

## Code style

- **Pint config** ships in `team-nifty-gmbh/flux-dev-helpers` (`php artisan flux-dev:publish-pint-config`). Run `vendor/bin/pint` before committing. CI runs `--test` mode.
- **Strict types**: not enforced project-wide; type hints on everything that can have one (parameters, return types, properties).
- **Arrays**: short syntax (`[]`).
- **Imports**: grouped (Laravel imports first, then framework, then app), alphabetised within groups — Pint enforces.

## Database conventions

- **Migration filenames**: Laravel default (`{timestamp}_create_orders_table.php`).
- **Column types**: prefer Laravel's typed columns (`->dateTime()`, `->decimal('amount', 18, 4)`, `->ulid('uuid')`). Avoid raw `->string('amount')` for numeric data.
- **Foreign keys**: `->foreignId('contact_id')->constrained()->cascadeOnDelete()` is the default. Use `nullOnDelete()` when delete should orphan rather than cascade.
- **Tenant columns**: every multi-tenant table includes `tenant_id`. Models add `HasTenants` trait.
- **Soft deletes**: every model that should support undelete includes the `SoftDeletes` trait + `deleted_at` column.
- **Translatable columns**: stored as JSON columns; the model's `attributeTranslationRules()` produces validation rules.

## Casts

Use the method form, not the property:

```php
// Good
public function casts(): array
{
    return [
        'finished_at' => 'datetime',
        'options'     => 'array',
        'state'       => OrderStateEnum::class,
    ];
}

// Bad — older style, doesn't compose with parent traits
protected $casts = [...];
```

The method form lets traits and parent classes contribute casts via `array_merge(parent::casts(), [...])`.

## Fillable

Models that extend `FluxModel` or `FluxPivotModel` _do not_ need a `$fillable` property. The base class is `$guarded = ['id', 'created_at', 'updated_at']`, which means everything else is mass-assignable.

If you find yourself adding `$fillable` to a `FluxModel` subclass, you're either redundant or fighting the convention. Remove it.

## Model identifiers

Use `getKey()` rather than `->id` directly:

```php
// Good
$order->getKey();
route('orders.show', ['order' => $order->getKey()]);

// Bad
$order->id;
```

The reasoning: some models use UUIDs, some use ULIDs, some use the default integer id. `getKey()` works for all of them. Direct `->id` access breaks if a model is later switched to a non-integer key.

## Routing convenience

- `wire:navigate` for in-app links between Livewire pages — preserves Alpine state and avoids full reloads.
- `wire:navigate.hover` for prefetching on hover.

```blade
<a href="{{ route('orders.show', ['order' => $order->getKey()]) }}" wire:navigate>
    {{ $order->order_number }}
</a>
```

## TallStackUI components — only

Flux uses TallStackUI exclusively. The components and their correct shapes are documented in [TallStackUI conventions](../4-livewire/0-index.md). The headlines:

- `x-input` for text inputs (NOT `x-inputs.text` — that's WireUI).
- `x-number` for numeric inputs.
- `x-select.native` for static dropdowns; `x-select.styled` for async.
- `x-toggle` for booleans (NOT `x-checkbox`).
- `x-button text="..."` (NOT `<x-button>...</x-button>`).
- `x-modal :title="..."` (NOT `<x-slot:title>`).

Mixing libraries breaks styling and event wiring.

## Alpine inside Livewire

Three rules that catch people who learnt Alpine in standalone contexts:

- **No `$wire.entangle`.** Use `$wire.fieldName` directly; entanglement causes maximum-call-stack errors with deep state.
- **No `@event` syntax.** Use `x-on:event` consistently.
- **No `:bind` shorthand.** Use `x-bind:attr` consistently.

For `x-show`, always pair with `x-cloak` on the element to avoid flicker on initial load:

```blade
<div x-show="open" x-cloak>...</div>
```

## Confirmation dialogs

For destructive actions, use `wire:flux-confirm`:

```blade
<x-button
    text="{{ __('Delete') }}"
    color="danger"
    wire:click="delete"
    wire:flux-confirm.type.error="{{ __('wire:confirm.delete', ['model' => __('Order')]) }}"
/>
```

Types: `success`, `error`, `warning`, `info`. The shape `wire:flux-confirm.type.error` puts the dialog in error styling.

## Blade `@stack` and `@push` naming

Stack names must follow `{area}-{component}-{purpose}` to avoid cross-package collisions:

```blade
{{-- Good --}}
@push('payment-run-execute-actions')
@push('order-state-card-actions')
@push('ticket-comment-actions')

{{-- Bad — generic, will collide --}}
@push('execute-actions')
@push('footer-buttons')
@push('scripts')
```

Stacks are global within a render cycle. A generic name in two packages causes content to appear in the wrong place.

## Customisation hooks

For tenant-specific behaviour, register overrides in your application's `AppServiceProvider`:

```php
public function register(): void
{
    $this->app->bind(FluxErp\Models\Order::class, App\Models\Order::class);
    $this->app->bind(FluxErp\Actions\Order\CreateOrder::class, App\Actions\Order\CreateOrder::class);
}
```

The `resolve_static()` helper used throughout flux-core respects these bindings, so a swapped class is picked up everywhere — query builders, ruleset resolution, action dispatch.

For UI extensions (custom tabs, editor buttons), use the dedicated service-provider hooks. See [Extending the frontend](../10-extending-the-frontend.md).

## Testing

- **Pest** is the framework — `pestphp/pest`.
- **Testbench** for Laravel package tests — `orchestra/testbench`.
- One smoke test per Livewire component, minimum.
- Integration tests use a real database (in-memory SQLite in CI) — no mocking the DB layer.
- The `team-nifty-gmbh/flux-dev-helpers` package provides `flux-dev:setup-tests` and `flux-dev:generate-livewire-smoke-tests` to scaffold the test infrastructure for a new package.

## Translations

- **Source language is English.** German strings live in `lang/de.json`.
- **Use `__('English text')` everywhere.** Never `trans('orders.created')` — Flux uses string-keyed JSON, not nested key files.
- **Interpolation**: `:name` placeholders, e.g. `__(':model created', ['model' => __('Order')])`.

## Composer warnings

If you see `--no-suggest` on Composer commands, drop it — it's deprecated and will break in Composer 3. Replace with no flag.

## Related

- [Anatomy of a feature](2-anatomy-of-a-feature.md) — the conventions in action.
- [Actions](../3-actions/0-index.md) — the central pattern.
- [TallStackUI conventions](../4-livewire/0-index.md) — full UI component reference.

[Back to chapter](0-index.md) · [Back to index](../0-index.md)
