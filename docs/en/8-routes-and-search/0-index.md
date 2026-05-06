# Routes & Search

Two HTTP-layer pieces with strong conventions:

- **The base controller** auto-derives a permission middleware from the route name. Naming the route `vouchers.show` is enough to gate it on `vouchers.show` permission — no manual middleware wiring.
- **The search endpoint** (`POST /search/{model}`) is a universal search/filter API. Async selects, autocomplete, and many internal queries route through it. Any model can be queried with a rich set of filters via this single endpoint.

## What this chapter covers

1. [Base controller](1-base-controller.md) — `FluxErp\Http\Controllers\Controller`, route-derived permissions, `route_to_permission()`, naming routes for the convention to apply.
2. [Search endpoint](2-search-endpoint.md) — full parameter reference, `Searchable` vs. `searchFields`, `InteractsWithDataTables` integration, wiring `<x-select.styled>` against your own models.

## When to write a new controller

For a Livewire-driven page, you typically don't. The route points directly at the Livewire component:

```php
Route::get('vouchers/{voucher}', EditVoucher::class)
    ->name('vouchers.show')
    ->permissionName('vouchers.show');
```

A controller is needed for:

- File downloads or streamed responses.
- Webhooks from external systems.
- API endpoints (often paired with Scramble for auto-generated docs).
- Anything that doesn't fit the Livewire model — e.g. a print-PDF endpoint where the response is a binary blob.

For these, extend `FluxErp\Http\Controllers\Controller` and let the auto-permission middleware do its job.

## Related

- [Permissions](../3-actions/4-permissions.md) — the route-permission convention coexists with the action-permission convention.
- [Helpers](../9-helpers.md) — `route_to_permission()`, `user_can()`, `user_can_access_route()`.

[Back to index](../0-index.md)
