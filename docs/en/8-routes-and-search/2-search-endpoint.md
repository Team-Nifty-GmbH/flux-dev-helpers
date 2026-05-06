# Search Endpoint

`POST /search/{model}` is Flux's universal search/filter endpoint. Async selects, autocomplete fields, and many internal queries route through it.

For your own packages: any model registered in the morph map can be queried via `route('search', YourModel::class)` without writing a controller.

## The route

```php
Route::post('/search/{model}', SearchController::class)->name('search');
```

`route('search', Model::class)` resolves to `/search/{morph-alias}`. The controller resolves the morph alias back to the FQN.

## Request shape

```http
POST /search/voucher
Content-Type: application/json

{
    "search": "WELCOME",
    "searchFields": ["code", "description"],
    "limit": 20,
    "with": "tags",
    "where": [["tenant_id", "=", 1]]
}
```

Standard field list:

| Param | Type | Effect |
|---|---|---|
| `search` | string | Search query — runs Scout if model has `Searchable`, else SQL `LIKE` against `searchFields`. |
| `searchFields` | array | Fields to `LIKE` against when not Scout-driven. **Required** for non-`Searchable` models when `search` is set. |
| `selected` | scalar or array | Specific IDs to fetch (bypasses search; used to load already-selected values). |
| `option-value` | string | Field used as the option value (default: `id`). |
| `limit` | int | Result count cap (default: 10; default 20 for Searchable models with no global scopes). |
| `with` | array or string | Eager-load relations. |
| `scopes` | array | Local query scopes to apply (`->scopeName()`). |
| `select` | array | Columns to fetch. |
| `appends` | array | Accessors to append. |
| `orderBy` | string | Column to order by. |
| `orderDirection` | string | `asc` or `desc` (default: `asc`). |
| `where` | array | A single Laravel `where()` argument set. |
| `whereIn` | array of arrays | Each sub-array is `[column, values]`. |
| `whereNotIn` | array of arrays | Each sub-array is `[column, values]`. |
| `whereNull` | array of strings | Columns to require null. |
| `whereNotNull` | array of strings | Columns to require non-null. |
| `whereBetween` | array of arrays | Each `[column, [low, high]]`. |
| `whereNotBetween` | array of arrays | Negated. |
| `whereDate` | array of arrays | Each `[column, op, date]`. |
| `whereMonth` | array of arrays | Each `[column, op, month]`. |
| `whereDay` | array of arrays | Each `[column, op, day]`. |
| `whereYear` | array of arrays | Each `[column, op, year]`. |
| `whereTime` | array of arrays | Each `[column, op, time]`. |
| `whereHas` | array of arrays | Each `[relation, callback-args]` (advanced). |
| `whereDoesntHave` | array of arrays | Negated. |
| `whereRelation` | array of arrays | Each `[relation, column, op, value]`. |
| `doesntHave` | array of strings | Relations the record must not have. |

The full list lives in `SearchController::__invoke`.

## Response shape

For models that implement `InteractsWithDataTables`:

```json
[
    {
        "id": 1,
        "label": "WELCOME10",
        "description": "10€ voucher, expires 2026-01-01",
        "image": null
    },
    ...
]
```

For models that don't, the shape is whatever `select` returned. To guarantee `label`/`value`/`description` in the response, specify them explicitly via the front-end attribute (see below).

## How a model becomes searchable

Two paths, both fully optional but commonly chosen:

### `Searchable` (Scout)

```php
use Laravel\Scout\Searchable;

class Voucher extends FluxModel
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return ['code' => $this->code, 'description' => $this->description];
    }
}
```

The `search` parameter then runs through Scout (Algolia / Meilisearch / DB driver). No `searchFields` needed.

### Without Scout — `searchFields` per request

```php
// no Searchable trait on the model

// in the request:
'searchFields' => ['code', 'description']
```

The endpoint runs SQL `LIKE` against those fields. Less efficient than Scout but no infrastructure dependency.

If a model has neither `Searchable` _nor_ provides `searchFields` in the request when `search` is set, the endpoint returns 404. This is intentional: vague "search anything you can find" against an unknown model surface is a foot-gun.

## Wiring `<x-select.styled>` to a search

```blade
<x-select.styled
    wire:model="form.contact_id"
    :label="__('Contact')"
    select="value:id"
    unfiltered
    :request="['url' => route('search', \FluxErp\Models\Contact::class), 'method' => 'POST']"
/>
```

For a model that implements `InteractsWithDataTables`, this is enough. The response includes `id`, `label`, `description`; the select knows what to render.

For a model without `InteractsWithDataTables`, specify the field mapping:

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

`select="label:name|value:id"` says: use the model's `name` field as the visible label, and the `id` field as the option value.

For models with `Searchable` but not `InteractsWithDataTables`, omit `searchFields` (Scout drives the search) but still include the `select` mapping:

```blade
<x-select.styled
    wire:model="form.product_id"
    :label="__('Product')"
    select="label:name|description:sku|value:id"
    unfiltered
    :request="['url' => route('search', \FluxErp\Models\Product::class), 'method' => 'POST']"
/>
```

## Constraints (tenant scoping, eager loads)

When the select should only show records matching some criteria, layer them via `params`:

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

The endpoint applies the constraints before search/filter. The result set is whatever's both queryable by the request and matches the search.

## Tenant scoping reminder

For multi-tenant models, the `HasTenants` trait applies a global scope automatically. The `where('tenant_id', ...)` constraint above is _additional_ — for cases where the model isn't tenant-scoped by default but you want to limit the search.

If a model is tenant-scoped (`HasTenants`), the endpoint already filters to the current tenant; you don't need to add the `where` clause.

## Local scopes (`scopes`)

For named scopes on the model:

```php
class Voucher extends FluxModel
{
    public function scopeRedeemable(Builder $q): Builder
    {
        return $q->whereNull('redeemed_at')->where('expires_at', '>', now());
    }
}
```

```blade
'params' => [
    'scopes' => ['redeemable'],
],
```

The endpoint dispatches to `$model->hasNamedScope()` and applies the scope if it exists.

## Soft-delete handling

The endpoint applies `withoutGlobalScopes([SoftDeletingScope::class])` for `selected` lookups (so already-selected soft-deleted records still appear in the dropdown). For `search` queries, the standard scope applies (soft-deleted records hidden).

This is rarely something you need to override — but it explains the behaviour you'd otherwise find puzzling.

## Events fired

`SearchController` dispatches:

- `tall-datatables-searching` — before the query runs. Receives the request.
- `tall-datatables-searched` — after the query, before formatting.

Listen in if you need to inspect or modify search behaviour from a service provider.

## Performance considerations

- For high-cardinality models (large user / order tables), use Scout. SQL `LIKE` doesn't scale.
- Limit `with` to relations actually used in the dropdown — eager-loading `contact.media` for every search is heavy.
- Cap `limit` reasonably (10–50). The dropdown shows a paginated subset; users can refine with more typing.

## Related

- [Base controller](1-base-controller.md) — `SearchController` extends `Controller` and inherits the auto-permission middleware (with the `search` permission).
- [TallStackUI conventions](../4-livewire/4-tallstackui-conventions.md) — `<x-select.styled>` reference.
- [Models](../2-models/0-index.md) — `Searchable` and `InteractsWithDataTables` traits.

[Back to chapter](0-index.md) · [Back to index](../0-index.md)
