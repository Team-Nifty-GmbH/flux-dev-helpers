# Actions

Every state-changing operation in Flux runs through an action class. There is no controller that talks to a model directly, no Livewire component that calls `$model->save()` from inside an event handler, no service object dropped in for one specific call site.

The action is the thing.

This is the central convention of flux-core, and the easiest source of friction if you don't know it: a contributor reaching for "let me just update this field on the order from the listener" is reaching for the wrong tool. The right tool is `UpdateOrder::make([...])->validate()->execute()`. Once you internalise this, the rest of the framework reads naturally.

## What an action does

Each `FluxAction` subclass:

- declares which models it touches (`models(): array`),
- defines (or composes) validation rules (via a `FluxRuleset`),
- runs the actual mutation inside `performAction(): mixed`,
- enforces a permission check (`action.{name}`) automatically.

Calling sites use a fluent shape:

```php
$order = CreateOrder::make($data)
    ->checkPermission()
    ->validate()
    ->execute();
```

Each step is a separate decision: do you want to enforce the current user's permissions, or are you running on their behalf as the system? Do you want to validate, or have you already validated upstream? The chain is explicit on purpose.

## Why this pattern

- **Validation, permission, mutation in one place.** Everything that can go wrong with creating an order is encoded in `CreateOrder` — its rulesets, its `prepareForValidation()`, its `validateData()`, its `performAction()`. There is no second place where the order can be created differently.
- **Same code from web request, queue worker, console command, Livewire component, API controller, test.** The action is callable everywhere; nothing about it knows where it was invoked from.
- **Auditable.** Action lifecycle events (`booting`, `booted`, `validating`, `validated`, `executing`, `executed`) make it easy to bolt on logging, broadcasting, or hooks without modifying the action body.
- **Transactional with deadlock retry.** Top-level actions wrap `performAction()` in a transaction with five retry attempts on deadlocks. Nested actions reuse the parent transaction.
- **Dispatchable.** Subclassing `DispatchableFluxAction` instead of `FluxAction` makes the same class queueable and (post-PR #1695) batchable, with full queue-monitor integration.

## What this chapter covers

1. [FluxAction](1-flux-action.md) — the lifecycle in detail: `make()`, `validate()`, `execute()`, lifecycle events, transactions, `actingAs()`, `prepareForValidation()`, `__serialize()`, the abstract methods you must implement.
2. [Rulesets](2-rulesets.md) — `FluxRuleset` and the composition pattern (`getRulesets()` returning one or many rulesets, plus the auto-merged attribute-translation rules).
3. [Dispatchable actions](3-dispatchable-actions.md) — `DispatchableFluxAction`, `executeAsync()`, the `Batchable` trait, queue serialization caveats, queue monitoring integration.
4. [Permissions](4-permissions.md) — `action.{name}`, `hasPermission()`, `canPerformAction()`, `checkPermission()`, when to opt out.

## Where actions live

Under `packages/flux-core/src/Actions/`, organised by domain:

```
src/Actions/
├── Address/
│   ├── CreateAddress.php
│   ├── UpdateAddress.php
│   └── ...
├── Order/
│   ├── CreateOrder.php
│   ├── UpdateOrder.php
│   └── ...
├── Contact/
├── Mail/                  ← SendMail used by EditMail bulk send
├── ...
├── FluxAction.php         ← base class
└── DispatchableFluxAction.php
```

Naming convention: `<Verb><Domain>` — `CreateOrder`, `UpdateAddress`, `DeleteContact`, `RestoreLead`. The verb determines the permission name (`order.create`, `address.update`, …).

## Two minimum-viable patterns

### A synchronous action (the default)

```php
class CreateAddress extends FluxAction
{
    public static function models(): array
    {
        return [Address::class];
    }

    protected function getRulesets(): string|array
    {
        return CreateAddressRuleset::class;
    }

    public function performAction(): Address
    {
        $address = app(Address::class, ['attributes' => $this->data]);
        $address->save();

        return $address;
    }
}
```

Three things: declare the models, declare the rulesets, do the work. Validation and permission are inherited from the base.

### An async action (the queue-monitored variant)

```php
class SendMail extends DispatchableFluxAction
{
    public static function models(): array
    {
        return [];   // no domain model — this action emits side effects
    }

    protected function getRulesets(): string|array
    {
        return SendMailRuleset::class;
    }

    public function performAction(): array
    {
        // ... build and send the message ...
    }
}
```

Subclassing `DispatchableFluxAction` instead of `FluxAction` is the only difference. `executeAsync()` queues it; the queue monitor wraps it. See [Dispatchable actions](3-dispatchable-actions.md).

## Related

- [Rulesets](2-rulesets.md) — composing validation across actions.
- [Dispatchable actions](3-dispatchable-actions.md) — queueing and monitoring.
- [Permissions](4-permissions.md) — the auto-derived `action.{name}` permission.
- [Models](../2-models/0-index.md) — the data layer that actions mutate.
- [Forms](../4-livewire/0-index.md) — `FluxForm`, the standard Livewire bridge to actions.

[Back to index](../0-index.md)
