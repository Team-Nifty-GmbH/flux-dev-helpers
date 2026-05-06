# Permissions

Every action gets an auto-derived permission name. Whether or not it's enforced is opt-in (default: yes), and the call site decides whether to enforce hard (throw) or soft (return a boolean).

## The naming convention

`FluxAction::name()` builds the permission slug from the class basename:

```php
public static function name(): string
{
    $exploded = explode('_', Str::snake(class_basename(static::class)));
    $function = array_shift($exploded);
    return implode('_', $exploded) . '.' . $function;
}
```

Working through `CreateOrder`:

1. `class_basename` → `CreateOrder`.
2. `Str::snake` → `create_order`.
3. Split on `_`, the first piece is the verb (`create`).
4. Join the rest with `_`, append `.verb`.

Result: `order.create`.

The actual Spatie permission name has an `action.` prefix:

```
action.order.create        ← CreateOrder
action.order.update        ← UpdateOrder
action.order_position.create   ← CreateOrderPosition
action.address.delete      ← DeleteAddress
```

This is the permission you grant a role.

## The check: `canPerformAction()`

```php
public static function canPerformAction(bool $throwException = true): bool
{
    if (! static::hasPermission() || (app()->runningInConsole() && ! app()->runningUnitTests())) {
        return true;
    }

    try {
        resolve_static(Permission::class, 'findByName', ['name' => 'action.' . static::name()]);
    } catch (PermissionDoesNotExist) {
        return true;
    }

    if (! auth()->user()?->can('action.' . static::name())) {
        if ($throwException) {
            throw UnauthorizedException::forPermissions(['action.' . static::name()]);
        }
        return false;
    }

    return true;
}
```

Three early-exit conditions, then the actual check:

1. **`hasPermission()` is `false`** — opt-out at the action class level. See below.
2. **Running in console (but not in tests)** — Artisan commands, the scheduler, the queue worker all skip permission checks. They run as the system; nobody is signed in. Tests _do_ check, because tests need to verify permission behaviour.
3. **Permission row doesn't exist** — if `action.order.create` isn't in the `permissions` table (e.g. the application hasn't seeded it yet), the check passes. New actions are usable until permissions are explicitly seeded; this prevents a deploy from silently locking out users who didn't get the new permission yet.
4. The actual check: `auth()->user()?->can('action.{name}')`. If false, throw `UnauthorizedException` (default) or return `false` (with `throwException: false`).

## The two call shapes

### Hard check — throws

```php
SendMail::make($data)
    ->checkPermission()                          // ← throws UnauthorizedException
    ->validate()
    ->execute();
```

`checkPermission()` is sugar for `static::canPerformAction()`. Use it when "the user must have this permission, otherwise abort the request".

In a controller, the standard exception handler converts this into a 403. In a Livewire component, the action's exception bubbles up — wrap with `try/catch` if you want to soften the error.

### Soft check — returns boolean

```php
if (! SendMail::canPerformAction(throwException: false)) {
    // hide the button, show a tooltip, fall back to a different code path...
    return;
}
```

Use this when "I want to know whether the user can do this, but I'm going to do something other than 403 if they can't".

The form layer uses this pattern: `FluxForm::canAction(string $action)` does a soft check so the UI can disable buttons preemptively.

## Opting out: `hasPermission(): bool`

Some actions don't need a permission — they're internal plumbing that runs as part of a larger flow:

```php
class RecalculateOrderTotals extends FluxAction
{
    protected static bool $hasPermission = false;
    // ...
}
```

Setting `protected static bool $hasPermission = false;` makes `canPerformAction()` short-circuit to `true`. The action becomes callable from anywhere without a permission check.

When to use:

- Actions that fire as side effects of other actions (the outer action's permission already gates the chain).
- Pure-computation actions (no mutation, just calculation).
- Internal background processing where permission doesn't apply.

When _not_ to use:

- Actions that mutate user-visible state. Always require permission, even if it's tedious to seed.
- Actions that send notifications to other users. The permission is the boundary.

If you find yourself opting out frequently, that's a smell. Either the permission model is too granular (combine permissions) or the action chain is too deep (consolidate actions).

## Permissions and `actingAs()`

`actingAs($user)` switches the auth context inside `execute()`, _after_ `checkPermission()` has run. The check uses the dispatcher's permission, the work runs as the supplied user.

Sometimes you want both — the dispatcher's permission gates the action, but the work logs activity as a different user. That's the typical pattern. The current `actingAs()` does exactly this.

If you want the _supplied_ user's permission to be checked instead, do it explicitly:

```php
if (! $supplied->can('action.' . SendMail::name())) {
    throw UnauthorizedException::forPermissions([...]);
}

SendMail::make($data)->actingAs($supplied)->validate()->execute();
```

There's no built-in shortcut because mixing dispatcher and acting-as permissions is error-prone — making it explicit forces a decision.

## Permissions across the queue boundary

For `DispatchableFluxAction`, the permission must be checked at dispatch time:

```php
SendMail::make($data)->checkPermission()->validate()->executeAsync();
```

The worker has no auth context — `auth()->user()` is `null`, the second clause of `canPerformAction()` (`runningInConsole() && ! runningUnitTests()`) returns `true` on the worker, so the check is skipped on the worker side. This is the right behaviour: if the dispatcher had permission, the work is authorised. The worker doesn't redundantly recheck.

Caveat: a long-queued job carries the dispatcher's authorisation forward in time. If the user lost the permission between dispatch and execution, the job still runs. If that's a concern (e.g. for sensitive operations queued days in advance), check inside `performAction()`:

```php
public function performAction(): mixed
{
    $user = User::find($this->data['user_id']);
    if (! $user?->can('action.' . static::name())) {
        throw UnauthorizedException::forPermissions(['action.' . static::name()]);
    }
    // ...
}
```

## Route-derived permissions for controllers

For HTTP routes that aren't action-driven (a plain controller method), the framework has a parallel convention. The base `FluxErp\Http\Controllers\Controller` constructor:

```php
public function __construct(?string $permission = null)
{
    if (! $permission && $permission = route_to_permission()) {
        $this->middleware(['permission:' . $permission]);
    }
}
```

`route_to_permission()` derives the permission from the route name. If your route is named `order.show`, the controller demands `order.show` as a permission. See [Routes & Search](../8-routes-and-search/0-index.md) for the details.

The action permission (`action.{name}`) and the route permission (no prefix) coexist intentionally — actions describe _what someone can do_, routes describe _what someone can see_.

## Seeding permissions

Permission rows live in the `permissions` table (Spatie). They're seeded by Flux's installer for the standard set; downstream packages add their own via migrations or a seeder. The naming is just a string convention — Flux doesn't gate functionality on the row existing (remember: missing permission row means the check passes), so the most painful failure mode is "user wasn't granted a permission they should have had" rather than "permission row missing locks everyone out".

## Related

- [FluxAction](1-flux-action.md) — `checkPermission()` and `canPerformAction()` in the lifecycle.
- [Dispatchable actions](3-dispatchable-actions.md) — permission semantics across the queue boundary.
- [Routes & Search](../8-routes-and-search/0-index.md) — the parallel route-permission convention.
- [Helpers](../9-helpers.md) — `route_to_permission()`, `user_can()`, `user_can_access_route()`.

[Back to chapter](0-index.md) · [Back to index](../0-index.md)
