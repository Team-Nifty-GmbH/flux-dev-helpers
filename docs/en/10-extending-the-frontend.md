# Extending the Frontend

Flux exposes several seams where downstream packages and tenant projects can plug into the existing UI without forking core. The seams are intentional — they're what makes feature packages composable.

## The seams, at a glance

| Seam | What you plug in | When to use |
|---|---|---|
| Custom tabs on detail pages | A blade `@push` to a named stack | Add a tab to an existing detail page (orders, contacts, …). |
| Editor buttons | `EditorButton`, `EditorDropdownButton`, `EditorTooltipButton` contracts | Add actions to the rich-text editor toolbar. |
| Widgets on dashboards | Livewire components with `Widgetable` | Surface a metric or list on the dashboard. |
| Container bindings | `$this->app->bind(...)` in a service provider | Replace a flux-core class with your own subclass. |
| State configs | `OrderState::registerStateConfig(...)` | Override the allowed transitions for an existing state machine. |
| Action listeners | `CreateOrder::executed(fn ($action) => ...)` | Run side effects after a flux-core action without modifying it. |
| Model event listeners | Standard Laravel observers / `Model::created()` | React to record changes. |
| Blade `@stack` extension | `@push('namespaced-stack')` | Add UI in slots that core leaves open for extension. |
| Search-endpoint constraints | `tall-datatables-searching` event listener | Tighten / loosen what shows up in `<x-select.styled>`. |

This chapter is the reference for the seams that aren't covered in detail elsewhere.

## Custom tabs on detail pages

Pattern: register a closure in your service provider's `boot()` that pushes a tab into the named stack on a detail page.

```php
// AppServiceProvider::boot()
public function boot(): void
{
    $this->registerCustomTabs();
}

protected function registerCustomTabs(): void
{
    \Illuminate\Support\Facades\Blade::componentNamespace(
        'TeamNifty\\YourPackage\\View\\Components',
        'your-package'
    );

    // Add a tab to the order detail page
    \Illuminate\Support\Facades\View::composer(
        'flux::livewire.order.edit-order',
        function ($view): void {
            $view->with('customTabs', array_merge(
                $view->getData()['customTabs'] ?? [],
                [
                    [
                        'name' => __('Audit Log'),
                        'component' => 'your-package::audit-log',
                        'parameters' => ['orderId' => $view->getData()['orderId'] ?? null],
                    ],
                ]
            ));
        }
    );
}
```

The actual contract depends on the host page — some use a `customTabs` view-shared variable, others use blade stacks. Check the host page's blade for the convention; the consistent rule is the namespacing.

For stack-based tabs:

```blade
{{-- Your package's blade fragment --}}
@push('order-detail-tabs')
    <x-tab name="audit" :label="__('Audit Log')">
        <livewire:your-package.audit-log :order-id="$orderId" />
    </x-tab>
@endpush
```

The host page's view contains:

```blade
<x-tabs>
    {{-- core tabs --}}
    @stack('order-detail-tabs')
</x-tabs>
```

## Editor buttons

The rich-text editor (used in mail composition, contract authoring, document templates) supports custom buttons via three contracts:

```php
namespace FluxErp\Contracts;

interface EditorButton
{
    public static function getName(): string;
    public static function getIcon(): string;
    public static function getCommand(): string;       // JS command name
}

interface EditorDropdownButton
{
    public static function getName(): string;
    public static function getIcon(): string;
    public static function getOptions(): array;        // dropdown items
}

interface EditorTooltipButton
{
    public static function getName(): string;
    public static function getTooltip(): string;
}
```

Implement the contract in a class under your package, then register in your service provider:

```php
namespace App\Editor;

use FluxErp\Contracts\EditorButton;

class InsertOrderNumberButton implements EditorButton
{
    public static function getName(): string { return 'insert-order-number'; }
    public static function getIcon(): string { return 'hashtag'; }
    public static function getCommand(): string { return 'insertOrderNumber'; }
}
```

```php
// AppServiceProvider::boot()
EditorRegistry::register(InsertOrderNumberButton::class);
```

The frontend picks up registered buttons and shows them in the toolbar. The JS command is dispatched on click — wire it up in your package's JS.

## Widgets

See [Widgets](4-livewire/3-widgets.md) for the Livewire side; the registration in your service provider:

```php
public function boot(): void
{
    WidgetRegistry::register([
        'open-orders' => OpenOrdersWidget::class,
        'revenue' => RevenueWidget::class,
    ]);
}
```

The framework then offers them in the dashboard's widget picker.

## Container bindings (model and action overrides)

The most powerful extension point — replace a flux-core class with your own subclass:

```php
// AppServiceProvider::register()
public function register(): void
{
    $this->app->bind(\FluxErp\Models\Order::class, \App\Models\Order::class);
    $this->app->bind(\FluxErp\Actions\Order\CreateOrder::class, \App\Actions\Order\CreateOrder::class);
    $this->app->bind(\FluxErp\Rulesets\Order\CreateOrderRuleset::class, \App\Rulesets\Order\CreateOrderRuleset::class);
}
```

`resolve_static()` and `app()->make()` calls (which is most of flux-core) honour these. The binding propagates through:

- Direct class references (`new App\Models\Order` is unaffected — but `app(Order::class)` resolves to your version).
- Relationships (because of `ResolvesRelationsThroughContainer` in `FluxModel`).
- Action chains (nested action calls go through `resolve_static`).

For models with morph aliases, also update the morph map:

```php
\Illuminate\Database\Eloquent\Relations\Relation::enforceMorphMap([
    'order' => \App\Models\Order::class,    // override the alias
]);
```

This ensures `morph_alias(\App\Models\Order::class)` returns `'order'` and broadcast channels resolve correctly.

## State configs

Override transitions without subclassing:

```php
// AppServiceProvider::boot()
public function boot(): void
{
    \FluxErp\States\Order\OrderState::registerStateConfig(
        \FluxErp\States\Order\OrderState::config()
            ->default(\FluxErp\States\Order\Open::class)
            ->allowTransitions([
                // your transition list
            ]),
        \FluxErp\States\Order\OrderState::class
    );
}
```

The base state's `config()` method consults the registered config first. See [States](2-models/3-states.md).

## Action lifecycle listeners

Hook into any action's lifecycle without modifying it:

```php
public function boot(): void
{
    \FluxErp\Actions\Order\CreateOrder::executed(function ($action) {
        // log, broadcast, send a notification, kick off a follow-up job ...
        $order = $action->getResult();
        SendOrderConfirmation::dispatch($order);
    });
}
```

The events are: `booting`, `booted`, `preparingForValidation`, `validating`, `validated`, `executing`, `executed`. See [FluxAction](3-actions/1-flux-action.md).

These listeners are typed to the specific action class. To listen to all actions (e.g. for global audit logging), subscribe to the underlying event:

```php
\Illuminate\Support\Facades\Event::listen('action.executed: *', function ($event, $action) {
    // ...
});
```

## Model observers

Standard Laravel pattern — register an observer that hooks into model lifecycle events:

```php
// AppServiceProvider::boot()
\FluxErp\Models\Order::observe(\App\Observers\OrderObserver::class);
```

The observer receives `created`, `updated`, `deleted`, `restored`, etc.

For lighter-weight hooks, the static event helpers work too:

```php
\FluxErp\Models\Order::created(function ($order) {
    // ...
});
```

## Search-endpoint customisation

For models whose search results need package-specific filtering, listen to the `tall-datatables-searching` event:

```php
\Illuminate\Support\Facades\Event::listen('tall-datatables-searching', function (Request $request) {
    if ($request->route('model') === 'voucher') {
        $request->merge([
            'where' => array_merge(
                $request->input('where') ?? [],
                [['tenant_id', '=', auth()->user()?->tenant_id]],
            ),
        ]);
    }
});
```

The event fires before the query runs; modifying the request modifies the query.

For a more declarative approach, override the model's query scopes — `HasTenants` and similar traits do most of this for you automatically.

## Blade `@stack` namespacing

The single most important rule in this section: **stack names must be `{area}-{component}-{purpose}`** to avoid collisions across packages.

```blade
{{-- WRONG — generic, will collide --}}
@push('execute-actions')
@push('footer-buttons')
@push('scripts')

{{-- RIGHT --}}
@push('payment-run-execute-actions')
@push('order-state-card-actions')
@push('ticket-comment-actions')
@push('contact-accounting-tabs')
```

When a host package emits `@stack('order-state-card-actions')` and your package pushes onto it, the content lands where you expect. If two packages push to a generic `@stack('actions')`, the content appears in any place that listens to it — usually wrong.

## Adding new packages

A typical Flux feature package's service provider:

```php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class YourPackageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/your-package.php', 'your-package');

        // Container bindings (only for overriding flux-core classes).
        // $this->app->bind(SomeFluxClass::class, YourReplacement::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'your-package');
        $this->loadJsonTranslationsFrom(__DIR__ . '/../../lang');

        // Custom tabs, widgets, editor buttons, action listeners, etc.
        $this->registerCustomTabs();
        $this->registerWidgets();
        $this->registerActionListeners();

        // Knowledge / docs registration if appropriate.
        // (See packages/flux-dev-helpers as the example.)
    }

    // ...
}
```

Match the conventions, register via the seams above, don't fork core.

## What you should _not_ do

Three anti-patterns to avoid:

1. **Editing flux-core source.** Use container bindings, action listeners, or state configs instead. A fork is unmaintainable.
2. **Subclassing without binding.** A subclass that's never registered in the container is dead code. The framework still resolves the original.
3. **Generic stack names.** Your push lands somewhere unintended; the bug surfaces months later, far from the cause.

## Related

- [Conventions](1-getting-started/1-conventions.md) — naming, blade stacks, customisation hooks.
- [States](2-models/3-states.md) — `registerStateConfig`.
- [Actions](3-actions/0-index.md) — lifecycle event listeners.
- [Widgets](4-livewire/3-widgets.md) — widget rules and registration.
- [Search endpoint](8-routes-and-search/2-search-endpoint.md) — `tall-datatables-searching` event.

[Back to index](0-index.md)
