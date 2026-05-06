# FluxAction

`FluxErp\Actions\FluxAction` (`packages/flux-core/src/Actions/FluxAction.php`) is the abstract base class. Every action in the system inherits from it directly or through `DispatchableFluxAction`.

## The contract

```php
abstract class FluxAction
{
    abstract public static function models(): array;
    abstract public function performAction(): mixed;
    // ...
}
```

Two abstract methods. That's the surface area you _must_ implement.

- `models(): array` — the model classes this action touches. Used by Scramble to derive API tags ([Service provider](#scramble-tagging)) and by the permission system to hint at scope. Return `[]` for actions that don't operate on a single domain model.
- `performAction(): mixed` — the work. The return value lands on `$this->result`.

Everything else has a sensible default and only needs overriding when you have a specific reason.

## Lifecycle

Calling `validate()->execute()` runs the action through this sequence:

```
make($data)
   │
   ├─ booting event (halt: false)
   ├─ bootTraits()                      walks class_uses_recursive, calls boot{Trait}() once
   ├─ keepEmptyStrings stored
   ├─ boot($data) → setData(...)        empty strings → null unless keepEmptyStrings
   └─ booted event (halt: false)

validate()
   │
   ├─ if rules empty → setRulesFromRulesets() merges all FluxRuleset::getRules()
   ├─ preparingForValidation event
   ├─ prepareForValidation()            override hook for data normalisation
   ├─ validating event (halt: true → if false, skip validateData)
   ├─ validateData()                    Laravel Validator::validate, replaces $data with validated subset
   └─ validated event (halt: false)

execute()
   │
   ├─ executing event (halt: true → if false, return false)
   ├─ if actingAs set: switch auth user
   ├─ if DB::transactionLevel() === 0: wrap performAction() in DB::transaction(..., 5 retries)
   │  else: call performAction() inside the parent transaction
   ├─ restore previous auth user (or logout)
   ├─ executed event (halt: false)
   └─ return $this->result
```

## `make()` and `Makeable`

`make(...$arguments)` comes from the `Makeable` trait. It accepts any constructor signature and forwards through, so the canonical call is:

```php
CreateOrder::make($data, keepEmptyStrings: false);
```

`make()` is a static constructor — it doesn't run validation or execute anything. You always chain `validate()` and/or `execute()` after it.

## `setData()` and the empty-string handling

The constructor calls `setData($data)` with the value of `$keepEmptyStrings`:

```php
public function setData(array|Arrayable $data, bool $keepEmptyStrings = false): static
{
    if (! is_array($data)) {
        $data = $data->toArray();
    }
    $this->data = $keepEmptyStrings ? $data : $this->convertEmptyStringToNull($data);
    return $this;
}
```

Default behaviour: empty strings become `null` (recursively). This matches form submissions where unfilled inputs come through as `''` but should be persisted as `NULL`.

Pass `keepEmptyStrings: true` if you have a specific need to preserve empty strings (for example, when an empty string is semantically distinct from `null` in the underlying column).

`getData()` returns either the full data array or a single key:

```php
$this->getData();              // full array
$this->getData('user_id');     // single value
$this->getData('user_id', 0);  // with default
```

`data_get()` semantics — supports dot-notation for nested arrays.

## `prepareForValidation()` — pre-validation normalisation

Override this to mutate `$this->data` _before_ validation runs:

```php
protected function prepareForValidation(): void
{
    $this->data['order_date'] ??= now();
    $this->data['currency_id'] ??= resolve_static(Currency::class, 'default')?->getKey();
}
```

This is where you fill in defaults that depend on other fields, look up tenant context, derive a delivery address from a contact, etc. By the time `validateData()` runs, `$this->data` should be in its final, ready-to-validate shape.

`CreateOrder::prepareForValidation()` is a long, dense example — it derives address fields, payment terms, language, agent, and tenant from a contact that the caller may not have provided.

## `validateData()` — the validation step

The default implementation calls Laravel's validator:

```php
protected function validateData(): void
{
    $this->data = Validator::validate($this->getData(), $this->getRules());
}
```

After validation, `$this->data` is _replaced_ with the validated subset. Anything not covered by a rule is dropped. This is intentional: actions reach into `$this->data` later (in `performAction()`), and you want to be sure that what they reach for has been validated.

Override `validateData()` when you need cross-field invariants or DB-level checks that Laravel rules can't express cleanly. Always call `parent::validateData()` first and then collect additional errors. `CreateOrder::validateData()` is the canonical example — it parents up, then runs tenant-isolation checks for foreign keys.

If you collect errors, throw `ValidationException::withMessages($errors)` with an `errorBag` that downstream forms can recognise:

```php
throw ValidationException::withMessages($errors)->errorBag('createOrder');
```

## `performAction()` — the work

Where the actual mutation happens. Whatever you return ends up in `$this->result` (and is the return value of `execute()`).

```php
public function performAction(): Order
{
    $order = app(Order::class, ['attributes' => $this->data]);
    $order->save();

    return $order;
}
```

By the time `performAction()` runs:

- `$this->data` is fully validated and normalised.
- The action is inside a transaction (top-level) or inside the parent's transaction (nested).
- The auth context is whatever `actingAs()` configured (or unchanged).

You can call other actions from inside `performAction()`. They reuse the current transaction, so a deadlock retry rolls back the entire chain — not just the inner action.

## The transaction wrapper

```php
if (DB::transactionLevel() === 0) {
    DB::transaction(fn () => $this->result = $this->performAction(), 5);
} else {
    $this->result = $this->performAction();
}
```

The reason for the conditional: nested actions create deeply nested savepoints. Laravel's transaction counter and its retry behaviour don't compose cleanly with savepoint-based nesting — under a deadlock, a nested retry can desynchronize the outer counter and leave `RefreshDatabase` unable to roll back in tests, or leave production state inconsistent.

So: top-level actions get a transaction with five retries. Nested actions reuse the parent transaction. If the deadlock happens deep, the entire chain retries from the outer call.

The five retries are not configurable per action. If you have a workload where deadlocks are routine, that's a sign to redesign the action chain (lock order, query patterns) rather than tune the retry count.

## `actingAs()` — auth context switching

```php
DeliverDocuments::make($data)
    ->actingAs($systemUser)
    ->validate()
    ->execute();
```

Switches `auth()->user()` to the supplied user for the duration of `execute()`, then restores the previous user (or logs out, if there was none).

Use cases:

- A scheduled job that runs as the system but should produce activity-log entries attributed to it.
- An import that runs as a different user than the one who triggered it (e.g. the import operator vs. the data owner).
- A test that wants permissions as a specific role.

Pass `null` to explicitly _detach_ the auth context (useful for actions that must run as nobody).

The base `execute()` method handles the swap and the restore around `performAction()`. You don't need to manage `auth()` yourself.

## `checkPermission()`

```php
public function checkPermission(): static
{
    static::canPerformAction();
    return $this;
}
```

Calls the static `canPerformAction(throwException: true)` and either passes through or throws `UnauthorizedException`. Chain it before `validate()`:

```php
$action = SendMail::make($data)
    ->checkPermission()
    ->validate();
```

If you want a soft check (return `false` instead of throwing):

```php
if (! SendMail::canPerformAction(throwException: false)) {
    return; // user can't send mail
}
```

See [Permissions](4-permissions.md) for the full permission story.

## Lifecycle events

Each event fires through the application's event dispatcher with a key like `action.executing: FluxErp\Actions\Order\CreateOrder`. Two registration styles, both via the `HasActionEvents` trait:

```php
// Static class registration — typical place is a service provider's boot()
CreateOrder::executed(function (CreateOrder $action) {
    // log, broadcast, send a notification ...
});
```

```php
// Direct subscription via the event dispatcher
Event::listen('action.executed: '.CreateOrder::class, function (...) { ... });
```

Available events: `booting`, `booted`, `preparingForValidation`, `validating`, `validated`, `executing`, `executed`.

`executing` and `validating` are halting events — returning `false` from a listener cancels the next phase. `executed` and `validated` are notification-only.

To suppress all events for a single call:

```php
CreateOrder::make($data)->withoutEvents()->validate()->execute();
```

`withoutEvents()` swaps in `NullDispatcher`, so registered listeners don't fire. Pair with `withEvents()` to re-enable.

## `__serialize()` and `__unserialize()`

`FluxAction` overrides PHP's serialization hooks because the action is queueable when subclassed as `DispatchableFluxAction`. Two important properties:

```php
public function __serialize(): array
{
    $data = get_object_vars($this);
    unset($data['dispatcher']);
    try {
        serialize($data['rules'] ?? null);
    } catch (Throwable) {
        unset($data['rules']);
    }
    return $data;
}
```

- All object vars survive serialization, _except_ the event dispatcher (it's not safe to serialize a Laravel container instance).
- `rules` is dropped if it contains anything non-serialisable (e.g. a closure-based rule). On unserialise, `setRulesFromRulesets()` re-derives them.

The post-PR `get_object_vars` shape is what makes `Batchable` survive on `DispatchableFluxAction` — the `batchId` property the `Batchable` trait adds is now preserved across the queue boundary, where the previous explicit `'data' / 'result' / 'rules'` shape stripped it.

## Adding rules ad-hoc

Two methods to layer additional rules onto an action without writing a new ruleset:

```php
$action->addRules(['memo' => ['nullable', 'string', 'max:500']]);
$action->mergeRules(['memo' => 'string']);
```

- `addRules()` _appends_ to existing rules per key (so `['user_id' => 'integer']` plus `addRules(['user_id' => 'min:1'])` yields `['integer', 'min:1']`).
- `mergeRules()` does an array_merge — overwriting per-key.

Use `addRules` if you want to layer a constraint (e.g. an action that's normally optional becomes required in a specific call site). Use `mergeRules` if you want to replace.

## `withoutEvents()` / `withEvents()` / `setRules()` / `getRules()`

Round-trip helpers:

- `setRules(array)` — replace the rules entirely.
- `getRules()` — current rules.
- `getResult()` — the result of `execute()`.
- `setResult(mixed)` — overwrite the result (e.g. from a listener).

These are escape hatches. Prefer the normal flow.

## Description and naming

Two static metadata helpers:

- `static::name()` — `'order.create'` for `CreateOrder`. Used as the suffix of the permission name (`action.order.create`).
- `static::description()` — `'create order'` for `CreateOrder`. Used for human-readable lists.

You don't override these in domain code — the snake-case+headline conversion handles every consistently-named action.

## Scramble tagging

The `models(): array` return value is consumed by the Scramble integration (`packages/flux-dev-helpers/src/FluxDevHelpersServiceProvider.php` and friends) to group API endpoints by model. Returning `[Order::class]` means `CreateOrder` shows up under the `Order` tag in the generated OpenAPI spec.

Return `[]` if your action doesn't fit a single model (e.g. `SendMail`, an import job). Scramble falls back to URI-based tagging.

## Related

- [Rulesets](2-rulesets.md) — `getRulesets()` and the validation composition.
- [Dispatchable actions](3-dispatchable-actions.md) — `executeAsync()`, queue serialization, batching.
- [Permissions](4-permissions.md) — `canPerformAction()`, `hasPermission()`, the `action.{name}` convention.
- [Forms](../4-livewire/0-index.md) — `FluxForm` is the standard Livewire bridge to actions.
- [Models](../2-models/0-index.md) — the layer actions mutate.

[Back to chapter](0-index.md) · [Back to index](../0-index.md)
