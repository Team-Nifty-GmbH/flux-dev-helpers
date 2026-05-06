# FluxModel

`FluxErp\Models\FluxModel` is the abstract base class for every domain model.

## The class

```php
abstract class FluxModel extends Model
{
    use BroadcastsEvents, HasModelPermission, ResolvesRelationsThroughContainer;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    public static function removeGlobalScopes(array $scopes): void { /* ... */ }
    public static function withTemporaryGlobalScopes(array $scopes): Builder { /* ... */ }

    public function resolveCollectionFromAttribute(): ?string { /* ... */ }
}
```

Three baked-in traits, three guarded fields, two scope helpers, one collection-resolution tweak. That's the whole base class.

## What the base traits give you

### `BroadcastsEvents` (Laravel)

This is Laravel's own `Illuminate\Database\Eloquent\BroadcastsEvents`, applied to every model. It broadcasts `created`, `updated`, `deleted`, `trashed`, `restored` events on the model's private channel.

Combined with the channel auto-registration in `routes/channels.php` (which walks `Relation::morphMap()` and registers `private-{morphAlias}.{key}` for every model with this trait), every flux model is observable in real time without manual channel wiring. A Livewire component subscribed to `private-order.{order}` re-renders the moment the order changes anywhere — same browser tab, different user, cron job, whatever.

The cost is one broadcast per write. For high-write models (queue monitor rows update many times per second), this is a lot of broadcasts; flux-core mitigates by using `BroadcastNowChannel` for queue notifications and accepting the volume.

### `HasModelPermission`

Adds permission-aware query helpers and enforces model-level permission checks. The Spatie permission system gates _what_ a user can do; this trait gates _which records_ they can do it to.

The methods you'll actually call:

- `$model->can($ability)` — check if the auth user can perform an ability on this specific record.
- Query scopes for filtering results to records the auth user can see.

### `ResolvesRelationsThroughContainer`

Defines relations using `app()` to resolve the related class:

```php
public function contact(): BelongsTo
{
    return $this->belongsTo(app(Contact::class)::class);
}
```

…rather than `$this->belongsTo(Contact::class)` directly. This is what makes downstream packages able to swap models via container bindings: if your application binds `App\Models\Contact` over `FluxErp\Models\Contact`, every relation defined this way resolves to your override.

When defining new relations on a `FluxModel` subclass, follow the same pattern. `$this->belongsTo(SomeModel::class)` works but skips the override mechanism.

## Mass assignment: `$guarded`, not `$fillable`

```php
protected $guarded = ['id', 'created_at', 'updated_at'];
```

`FluxModel` is universally guarded — every column is mass-assignable except `id` and the timestamps. This works because the action layer already validates inputs through Laravel's validator, which is strict by default. By the time `$this->data` reaches `app(Voucher::class, ['attributes' => $this->data])` in `performAction()`, only validated keys are in the array.

Subclasses that add `$fillable` are usually wrong. Either they're redundant (everything's already mass-assignable except `id`/timestamps) or they're trying to defend against unvalidated input — which is the action's job.

## Identifiers: `getKey()`

```php
$order->getKey();     // returns the primary key value, regardless of column name or type
```

Use this everywhere instead of `$order->id`. Models with `HasUuid` or `HasUlid` traits have non-integer primary keys; some pivot models use composite keys. `getKey()` is the only method guaranteed to return the right thing.

Same for the column name:

```php
$order->getKeyName();      // 'id', 'uuid', whatever
$order->getRouteKeyName(); // for route binding — defaults to getKeyName()
```

Use these for `route()` calls and query scoping if you can't hard-code the column name.

## Casts: `casts()`, not `$casts`

```php
public function casts(): array
{
    return [
        'expires_at'   => 'datetime',
        'amount'       => 'decimal:4',
        'state'        => OrderStateEnum::class,
        'options'      => 'array',
    ];
}
```

The method form composes with parent traits via `array_merge(parent::casts(), [...])`. The property form (`protected $casts = [...]`) doesn't — it replaces the parent's casts and breaks traits that expect their own casts to apply (e.g. `HasAttributeTranslations`).

## Scope helpers: `removeGlobalScopes()` and `withTemporaryGlobalScopes()`

```php
// removeGlobalScopes — drop scopes for the rest of the request
Order::removeGlobalScopes([TenantScope::class]);
$allTenantOrders = Order::query()->get();

// withTemporaryGlobalScopes — add, query, then auto-remove
$results = Order::withTemporaryGlobalScopes([
    'highValue' => fn ($q) => $q->where('total', '>', 10_000),
])->get();
```

`withTemporaryGlobalScopes()` adds the scopes, runs the query, and removes them after the query completes (via `afterQuery`). Useful for one-off restrictions where you don't want to mutate the model's scope state for the rest of the request.

`removeGlobalScopes()` is permanent for the request — primarily for tests or specific admin contexts.

## Collection resolution

```php
public function resolveCollectionFromAttribute(): ?string
{
    $parent = get_parent_class(static::class);
    if ($parent && (new ReflectionClass($parent))->isAbstract()) {
        return null;
    }
    return parent::resolveCollectionFromAttribute();
}
```

Tweaks Laravel's collection-attribute resolution to skip abstract parent classes. Mostly invisible — relevant only if you're writing a model that participates in the polymorphic-collection-cast feature and inherits from a non-abstract intermediary.

## Container resolution: `resolve_static()`

The helper used throughout flux-core for static method calls on resolvable classes:

```php
$default = resolve_static(Currency::class, 'default');
$rules   = resolve_static(CreateOrderRuleset::class, 'getRules');
$query   = resolve_static(Order::class, 'query');
```

Equivalent to `app(Class::class)::method(...)` — the container resolves the class (so bindings apply), then the method is called statically on the resolved class name.

When a downstream package overrides `Currency::class` in the container with `App\Models\Currency`, every `resolve_static(Currency::class, 'default')` call picks up the override transparently. Direct `Currency::default()` calls do not.

In your own model code: use `resolve_static()` whenever you'd otherwise call a static method on a class that downstream code might want to override.

## FluxPivotModel

`FluxPivotModel` exists for pivot tables that need the trait stack but extend Laravel's `Pivot` instead of `Model`. It's used much less often than `FluxModel`. If you're writing a pivot-table model, check whether it needs `BroadcastsEvents` etc.; if it does, extend `FluxPivotModel`. If not, a plain `Pivot` subclass is fine.

## What you don't get for free

`FluxModel` is small. The capabilities you probably want are in traits:

- Soft deletes → `Illuminate\Database\Eloquent\SoftDeletes` (Laravel).
- Activity log → `LogsActivity` (Spatie).
- Tags → `HasTags`.
- Multi-tenant scope → `HasTenants`.
- User modification stamps → `HasUserModification`.
- Media library → `InteractsWithMedia` (Spatie media library).
- Translations → `HasAttributeTranslations`.
- Parent-child trees → `HasParentChildRelations`.
- Default record → `HasDefault`.
- Queue monitoring → `MonitorsQueue`.
- ULIDs/UUIDs → `HasUlids`/`HasUuids` (Laravel).
- Categorisation → `Categorizable`.
- Soft-cascade deletes → `CascadeSoftDeletes`.

Pick what the model needs. See [Model traits](2-model-traits.md) for the full reference.

## Related

- [Model traits](2-model-traits.md) — the trait reference.
- [States](3-states.md) — Spatie ModelStates integration.
- [Helpers](../9-helpers.md) — `resolve_static()`, `morph_alias()`, `morph_to()`.
- [Events & Broadcasting](../7-events-and-broadcasting/0-index.md) — `BroadcastsEvents` and the auto-registered model channels.

[Back to chapter](0-index.md) · [Back to index](../0-index.md)
