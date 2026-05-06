# Helpers

The global helpers in `packages/flux-core/helpers.php`. They're loaded for every request — no `use` needed. Reach for them rather than re-implementing the same patterns inline.

## Discovery

### `all_models(): Collection`

Returns a collection of all classes registered in the morph map.

```php
all_models()->each(fn ($class) => /* ... */);
```

Use case: iterating over every model that might be of interest — populating dropdowns, applying a global migration, building an index.

### `get_models_with_trait(string $trait, ?callable $mapCallback = null): array`

Returns models whose `class_uses_recursive` includes the given trait. Memoised forever.

```php
$broadcasting = get_models_with_trait(BroadcastsEvents::class);
$tenantScoped = get_models_with_trait(HasTenants::class);
```

Default mapping returns `['label' => Headline, 'value' => morph_alias]` per model — ready for a select. Pass a custom `$mapCallback` to override.

The cache is `Cache::memo()` — process-local, never invalidated. If you change a model's traits, restart the worker.

### `get_subclasses_of(string $class): array`

Returns all loaded subclasses of `$class` (PSR-4 autoloaded).

```php
$concreteStates = get_subclasses_of(OrderState::class);
```

Useful for walking state hierarchies, finding all rulesets, etc. The set is computed from the autoloader's class map, so unloaded classes are excluded.

## Permissions

### `route_to_permission(Route|string|null $route = null, bool $checkPermission = true): ?string`

Resolves a route to its associated permission name (the `permissionName()` declared on the route).

```php
route_to_permission();                               // current route's permission
route_to_permission('orders.show');                  // by route name
route_to_permission(Route::current());               // explicit
route_to_permission('orders.show', false);           // skip the existence check
```

Returns the permission name if (a) the route is guarded, (b) it has a `permissionName()`, and (c) the permission row exists in the `permissions` table. Otherwise `null`.

The `$checkPermission = false` form skips the database lookup and returns the route's declared permission name regardless of whether it's been seeded. Useful for "what _would_ this route's permission be" introspection.

### `user_can(Route|string|array $permission): bool`

Checks if the auth user has the permission.

```php
if (user_can('orders.show')) { /* ... */ }
if (user_can(['orders.show', 'orders.edit'])) { /* any of these */ }
if (user_can(Route::current())) { /* the current route's permission */ }
```

Single permission: returns true if the user has it.
Array: returns true if the user has _any_ of them.
Route: equivalent to `user_can(route_to_permission($route))`.

### `user_can_access_route(?string $routeName): bool`

Higher-level: can the auth user access the named route at all (auth + permission combined)?

```php
if (user_can_access_route('orders.index')) {
    // show the menu link
}
```

This wraps the auth-guard check, the permission check, and the public-route handling. Use it for menu visibility, route-driven UI.

### `user_can_view_model_detail($model): bool`

Convenience: `user_can()` for a model's detail route. Resolves the route convention `{morph}.show` and checks.

### `channel_to_permission(string $channel): ?string`

Reverse of the channel naming: given a broadcast channel string, return the permission name that covers it.

### `print_view_to_permission(string $view): ?string`

For models implementing `OffersPrinting`, derives the permission name for printing a specific view of that model.

## Morph aliases

### `morph_alias(string $class): string`

Resolves a class to its morph alias.

```php
morph_alias(\FluxErp\Models\Order::class);   // 'order'
morph_alias(\FluxErp\Models\MailAccount::class); // 'mail-account'
```

Reverse via `morphed_model()`.

### `morphed_model(string $alias): ?string`

Reverse — alias to FQN.

```php
morphed_model('order'); // 'FluxErp\\Models\\Order'
morphed_model('voucher'); // 'App\\Models\\Voucher' (after binding override)
```

Returns `null` if not in the morph map.

### `morph_to(string $morph): Model`

Resolves a `morph_class:key` string to the model instance.

```php
morph_to('user:1');                    // User::find(1)
morph_to('order:abc-uuid');            // Order::find('abc-uuid')
morph_to('voucher:WELCOME10');         // matches whatever route key the model uses
```

Used in queue jobs and broadcast handlers where the recipient is serialised as a morph reference. The split is on `:`, so morph aliases with colons need escaping (none currently do).

### `qualify_model(string $model): string`

Adds the `App\Models\\` namespace if the input is unqualified, leaves it alone otherwise.

```php
qualify_model('Order');          // 'App\\Models\\Order' (or whatever model_namespace config says)
qualify_model('FluxErp\\Models\\Order'); // unchanged
```

Used by `SearchController` to accept short names from the URL.

## Container resolution

### `resolve_static(string $class, string $method, array $args = []): mixed`

Calls a static method on a container-resolved class — picking up bindings.

```php
$default = resolve_static(Currency::class, 'default');
$rules   = resolve_static(CreateOrderRuleset::class, 'getRules');
$query   = resolve_static(Order::class, 'query');
```

Implementation: `app($class)::staticMethod($args)`. The `app()` call applies any container bindings; the static method then runs on the resolved class name.

When you'd write `Currency::default()` directly, you bypass the override mechanism. Use `resolve_static(Currency::class, 'default')` instead, and downstream packages that swap `Currency` for their own subclass will work transparently.

### `class_to_broadcast_channel(string $class, bool $withParam = true): string`

Builds the broadcast channel name from a class. See [Broadcast channels](7-events-and-broadcasting/1-broadcast-channels.md).

```php
class_to_broadcast_channel(Order::class);          // 'order.{order}'
class_to_broadcast_channel(Order::class, false);   // 'order'
class_to_broadcast_channel(MailAccount::class);    // 'mail-account.{mailAccount}'
```

### `model(string $alias): Model`

Resolves a morph alias to a fresh model instance via the container.

```php
$order = model('order');         // app(Order::class), respecting bindings
```

## Notification adapters

### `exception_to_notifications(Throwable $exception, object $notifiable, ?string $description = null): void`

Converts a thrown exception into toast notifications for the recipient — typically used inside a controller or job that catches an error and wants the user to see what went wrong without the standard error page.

```php
try {
    SendMail::make($data)->checkPermission()->validate()->execute();
} catch (Throwable $e) {
    exception_to_notifications(
        exception: $e,
        component: $this,
        description: data_get($data, 'subject')
    );
}
```

For `ValidationException`, it produces one toast per validation error, each with the message bag's key as title. For other exceptions, a single error toast with the message.

## Numeric helpers

### `gross_to_net(float|string $gross, float|string $vatRate): string`

Converts a gross amount to net using a VAT rate (decimal, e.g. `0.19` for 19%). Returns a string (decimal-precise).

### `net_to_gross(float|string $net, float|string $vatRate): string`

Inverse of above.

### `discount(float|string $base, float|string $rate): string`

Applies a discount rate to a base amount.

### `diff_percentage(float|string $a, float|string $b): float`

Percentage difference between two values.

### `percentage_of(float|string $value, float|string $total): float`

Value as a percentage of total.

### `bcabs($value): string`

`abs()` for bcmath strings.

### `bcceil(string $value, int $precision = 0): string`

`ceil()` for bcmath.

### `bcfloor(string $value, int $precision = 0): string`

`floor()` for bcmath.

These exist because Flux uses bcmath / decimal strings for money to avoid float rounding. Use them instead of casting to float and back.

## Trees

### `to_flat_tree(array $items, string $childrenKey = 'children'): array`

Flattens a nested tree into a list with depth tracking — useful for rendering hierarchies as flat option lists.

### `to_tree(array $items, $idKey = 'id', $parentKey = 'parent_id'): array`

The inverse — builds a nested tree from a flat list with parent-id references.

## Misc utilities

### `event_subscribers(string $eventClass): array`

Returns the registered listeners for an event class — useful for debugging "why isn't my listener firing".

### `eloquent_model_event(string $event, $model): array`

Helper to construct the standard model-event payload. Used internally by some traits; rarely needed in user code.

### `meilisearch_import_sync($model): void`

Triggers a synchronous Meilisearch import for a Searchable model. Mostly for migrations and seeding.

### `faker(): Faker\Generator`

Returns a Faker instance with the framework's seeders. Used by model factories and tests.

### `livewire_component_exists(string $name): bool`

Checks if a Livewire component is registered. Useful when conditionally rendering an extension component that may not be installed.

### `flux_path(string $path = ''): string`

Resolves a path relative to the flux-core package root. Used internally; avoid in your own packages — use Laravel's `package_path()` or your own equivalent.

### `map_values_to_options(iterable $values): array`

Quick helper to turn a list of values into `[['label' => $value, 'value' => $value], ...]` for dropdowns.

### `render_editor_blade(string $template, array $data = []): string`

Renders a Blade template with a context restricted to editor-safe helpers — used by the print/document editor.

## When to add a helper of your own

Three rules:

1. **Truly global, used in many call sites.** A function used in two places isn't global — make it a class method.
2. **No dependencies on request state.** Helpers should be pure or at most read from the container. State-coupling makes them flaky.
3. **Cohesive with what's already there.** A helper named like `morph_alias` belongs in the morph helpers group; one named `compute_x` looks out of place. If you can't find a place that feels right, the helper might not be a helper.

For your own packages, register helpers via your service provider's `register()` method:

```php
public function register(): void
{
    // ...
    require_once __DIR__ . '/../helpers.php';
}
```

Wrap each definition in `if (! function_exists('your_helper')) { ... }` so reloads don't double-define.

## Related

- [Permissions](3-actions/4-permissions.md) — `route_to_permission`, `user_can`.
- [Models](2-models/0-index.md) — the morph map and traits the discovery helpers walk.
- [Notifications](5-notifications/0-index.md) — `exception_to_notifications` complements the toast system.

[Back to index](0-index.md)
