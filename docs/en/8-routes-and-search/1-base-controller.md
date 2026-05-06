# Base Controller

Flux's controller base is small but does one important thing: it auto-attaches a permission middleware derived from the route name.

## The class

```php
namespace FluxErp\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    public function __construct(?string $permission = null)
    {
        if (! $permission && $permission = route_to_permission()) {
            $this->middleware(['permission:' . $permission]);
        }
    }
}
```

What it does:

1. Inherits the standard Laravel controller traits (`AuthorizesRequests`, `DispatchesJobs`, `ValidatesRequests`).
2. On construction, looks up the permission for the current route via `route_to_permission()`.
3. If a permission is found, attaches the `permission:` middleware (Spatie permission middleware) for that name.

The result: extending `FluxErp\Http\Controllers\Controller` gives you automatic permission enforcement based on the route name. You don't write `middleware('permission:vouchers.show')` on every route.

## Naming routes for the convention to work

`route_to_permission()` (in `helpers.php`) reads the current route's `permissionName()`:

```php
Route::get('vouchers', [VoucherController::class, 'index'])
    ->name('vouchers.index')
    ->permissionName('vouchers.index');
```

The `permissionName()` is the Spatie permission slug — typically the same as the route name in `model.action` form.

If `permissionName()` isn't set on the route, no middleware is attached. The controller passes through. **Don't rely on this** — explicitly set the permission name for any route that should be gated.

## Custom permissions per controller method

If a controller has multiple methods that need different permissions, you can pass the permission to `parent::__construct(...)` per method, but the cleaner approach is to declare it on the route:

```php
Route::get('vouchers/{voucher}/redeem', [VoucherController::class, 'redeem'])
    ->name('vouchers.redeem')
    ->permissionName('vouchers.redeem');
```

The constructor reads from the active route, so each method gets its own permission.

For a single controller class with many methods, declare each route with its own permission name. Don't try to encode the conditional logic in the controller body.

## Bypass for unauthenticated routes

`route_to_permission()` returns `null` if the route isn't behind any auth middleware (i.e. it's a fully public route). The controller then attaches no middleware, which is the right behaviour: a public route shouldn't need a permission.

If a public route needs to call a controller that extends the Flux base, no harm done — the constructor just doesn't attach middleware.

## Multiple guards

```php
function route_to_permission(...): ?string
{
    // ...
    $authGuard = Arr::first(Arr::where(
        $route->middleware(),
        fn ($value) => str_starts_with($value, 'auth:')
    ));

    $guard = explode(',', explode(':', $authGuard)[1] ?? '');
    // ...
}
```

If a route has `auth:web` middleware, the guard is `web`. If it has `auth:address` (the customer-portal guard), the guard is `address`. The Spatie permission lookup respects the guard — `vouchers.show` for `web` is a different row than `vouchers.show` for `address`.

This means: customer-portal routes use the same permission name convention but live on a separate guard's permission table. A single permission slug is bound to the guard it was registered for; cross-guard permissions are independent.

## When to add a custom controller

For Livewire pages: don't. Route directly at the component:

```php
Route::get('vouchers/{voucher}', EditVoucher::class)
    ->name('vouchers.show')
    ->permissionName('vouchers.show');
```

The Livewire component handles its own state and rendering. No controller is involved.

For non-Livewire endpoints — file downloads, webhooks, API responses, exports that bypass the queue, integrations:

```php
namespace FluxErp\Http\Controllers;

class WebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        // process webhook
        return response()->json(['received' => true]);
    }
}
```

Single-action controllers with `__invoke` are the convention for a small endpoint.

## Calling actions from controllers

Standard pattern:

```php
public function generate(Request $request)
{
    $result = GenerateReport::make($request->validated())
        ->checkPermission()
        ->validate()
        ->execute();

    return response()->download($result['path']);
}
```

The action does the work. The controller is glue between HTTP and the action layer. Don't put business logic in the controller — push it into an action.

## Sanctum for API authentication

API routes typically use `auth:sanctum` middleware. The base controller's permission lookup respects this — the lookup uses Sanctum's resolved user.

```php
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('orders', [OrderController::class, 'store'])
        ->name('orders.store')
        ->permissionName('orders.store');
});
```

Sanctum's bearer-token authentication issues tokens via `/login` (or the standard Sanctum routes); permission checking against the token's user goes through the same `permission:` middleware as web routes.

## Scramble integration

`flux-dev-helpers` registers Scramble extensions that pick up `FluxAction` controllers and document them automatically. If your route controller is an action (e.g. you're using one of the auto-generated REST endpoints), the OpenAPI doc captures the rules and the response shape.

For plain controller methods, write the docblocks Scramble expects. The extensions in `flux-dev-helpers/src/Scramble/` show what's wired up.

## Related

- [Permissions](../3-actions/4-permissions.md) — `action.{name}` (the action permission convention) coexists with route permissions.
- [Helpers](../9-helpers.md) — `route_to_permission()`, `user_can()`, `user_can_access_route()`.
- [Search endpoint](2-search-endpoint.md) — `SearchController` is one example of a `Controller` subclass.

[Back to chapter](0-index.md) · [Back to index](../0-index.md)
