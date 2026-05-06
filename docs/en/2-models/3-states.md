# States

Flux uses Spatie's ModelStates for state machines: an order moves through `Draft → Open → InProgress → Done`, a queue monitor through `Queued → Running → Succeeded`, etc. The state isn't a string column — it's a class, with declared transitions, colors, and behaviour.

For your own packages: when a model has a meaningful lifecycle with explicit transitions and per-state behaviour (different rules, different UI, different actions allowed), reach for ModelStates instead of an enum-as-string column.

## The base class

`FluxErp\States\State` extends Spatie's `State` and adds two things:

```php
abstract class State extends BaseState implements Arrayable
{
    protected static ?string $color = null;
    protected static ?array $config = null;

    public static function registerStateConfig(StateConfig $config, ?string $baseStateClass = null): void { /* ... */ }

    public function badge(): string
    {
        return Blade::render('<x-badge :$text :$color />', [
            'color' => $this->color(),
            'text' => __(Str::headline($this->__toString())),
        ]);
    }
}
```

- `badge()` — renders a TallStackUI badge with the state's color and humanised name. Use it directly in views: `{!! $order->state->badge() !!}`.
- `registerStateConfig()` — static method to override transition rules from outside the state class. Useful when a downstream package needs to alter the allowed transitions without subclassing.
- `Arrayable` implementation — the state stringifies to its short class name when serialised.

Flux ships base states under `FluxErp\States\` for the major lifecycles:

```
src/States/
├── State.php                     ← base abstract
├── EndableState.php              ← intermediate (states that can be marked final)
├── Order/
│   ├── OrderState.php            ← abstract base for order states
│   ├── Draft.php
│   ├── Open.php
│   ├── InProgress.php
│   ├── InReview.php
│   ├── Done.php
│   ├── Canceled.php
│   ├── ReadyForDelivery.php
│   ├── ReadyForPacking.php
│   ├── DeliveryState/            ← sub-state machine for delivery
│   └── PaymentState/             ← sub-state machine for payment
├── QueueMonitor/
│   ├── Queued.php
│   ├── Running.php
│   ├── Succeeded.php
│   ├── Failed.php
│   └── Stale.php
├── Task/
├── Ticket/
└── ...
```

## Defining a state machine

Three pieces:

1. **Abstract base state** for the machine (`OrderState`).
2. **Concrete state classes** (`Draft`, `Open`, `Done`).
3. **Cast on the model** (`'state' => OrderState::class`).

### The abstract base

```php
namespace FluxErp\States\Order;

use FluxErp\States\EndableState;
use Spatie\ModelStates\StateConfig;

abstract class OrderState extends EndableState
{
    abstract public function color(): string;

    public static function config(): StateConfig
    {
        return data_get(static::$config, static::class) ?? parent::config()
            ->default(Draft::class)
            ->allowTransitions([
                [Draft::class, Open::class],
                [Open::class, InProgress::class],
                [InProgress::class, Done::class],
                [InProgress::class, Canceled::class],
                // ... arbitrary list of [from, to] pairs
            ]);
    }
}
```

The `data_get(static::$config, ...)` lookup is what makes runtime overrides via `registerStateConfig()` work. If a downstream package has registered a custom config, it's used; otherwise the default declared here applies.

`config()` returns a `StateConfig` listing default state and allowed transitions. The state machine enforces these — calling `$order->state->transitionTo(InProgress::class)` from `Draft` throws `CouldNotPerformTransition` because `Draft → InProgress` isn't in the list.

### Concrete states

```php
namespace FluxErp\States\Order;

class Draft extends OrderState
{
    public function color(): string
    {
        return 'gray';
    }
}

class Open extends OrderState
{
    public function color(): string
    {
        return 'blue';
    }
}
```

Concrete states implement the abstract methods (`color()` here). They can override per-state behaviour — methods declared on the abstract base can be overridden per state for state-dependent logic.

### Cast on the model

```php
class Order extends FluxModel
{
    public function casts(): array
    {
        return [
            'state' => OrderState::class,
        ];
    }
}
```

The `state` column on the migration is a string. The cast handles serialisation to/from the class.

## Transitions

```php
$order->state->transitionTo(Open::class);
$order->save();
```

Transitions throw if not allowed. To check before transitioning:

```php
if ($order->state->canTransitionTo(Open::class)) {
    $order->state->transitionTo(Open::class);
}
```

Transitions can be enriched with classes that run during the change (for cleanup, audit, side effects):

```php
->allowTransitions([
    [Draft::class, Open::class, OrderTransition\DraftToOpen::class],
])
```

`DraftToOpen` is a class with a `handle()` method that runs as part of the transition. Useful for heavy logic that's specific to a transition.

## Validating state transitions in actions

Use `FluxErp\Rules\ValidStateRule` in rulesets to validate that a state column being set is a legal transition from the current state:

```php
use FluxErp\Rules\ValidStateRule;

public function rules(): array
{
    return [
        'state' => ['required', new ValidStateRule(OrderState::class)],
    ];
}
```

The rule checks the model's current state (resolved from the validation context) against the requested state's `canTransitionTo()`. If the transition is not allowed, validation fails — the action never executes.

## State-driven behaviour

States are classes, so they can carry behaviour. Two patterns:

### Per-state methods on the base

```php
abstract class OrderState extends EndableState
{
    public function isFinal(): bool
    {
        return false;     // overridden per state where applicable
    }
}

class Done extends OrderState
{
    public function isFinal(): bool
    {
        return true;
    }
}

class Canceled extends OrderState
{
    public function isFinal(): bool
    {
        return true;
    }
}
```

Then `if ($order->state->isFinal()) {...}` works without checking the class directly.

### Polymorphic dispatch through the state

When the state should determine which action runs:

```php
abstract class OrderState extends EndableState
{
    abstract public function nextAction(Order $order): ?FluxAction;
}

class Open extends OrderState
{
    public function nextAction(Order $order): ?FluxAction
    {
        return PrepareOrder::make([...]);
    }
}
```

`$order->state->nextAction($order)` returns the action appropriate to the current state. The state machine encodes the workflow.

## Querying by state

States can be queried as strings (the cast handles the conversion):

```php
Order::query()->whereState('state', Done::class);
Order::query()->whereState('state', [Open::class, InProgress::class]);
```

`whereState` is a Spatie helper that resolves classes to their short names for the SQL query.

## When to override a state config

Most often: a downstream package needs different transitions than core. Don't subclass — use `registerStateConfig`:

```php
// in your service provider's boot()
OrderState::registerStateConfig(
    OrderState::config()
        ->default(Open::class)              // your tenant's default is Open, not Draft
        ->allowTransitions([
            // your transitions
        ]),
    OrderState::class
);
```

The lookup in the base `config()` method picks this up. No core change needed.

## EndableState

`FluxErp\States\EndableState` adds an `isEndable()` concept — states that can be marked as the "final" terminal state. `OrderState` extends it; `Done` and `Canceled` are endable.

Inherit from `EndableState` instead of `State` for any machine that has a final state with cleanup behaviour.

## Frontend formatter integration

States that implement `HasFrontendFormatter` (a `tall-datatables` contract) render nicely in data tables — the column shows the badge instead of the raw class name:

```php
abstract class OrderState extends EndableState implements HasFrontendFormatter
{
    public static function getFrontendFormatter(...$args): string|array
    {
        return [
            'state',
            self::getStateMapping()->map(fn ($key) => (new $key(''))->color()),
        ];
    }
}
```

The formatter returns the state name and a color map; the data table's column renderer wires it into a TallStackUI badge.

## Related

- [FluxModel](1-flux-model.md) — the cast lives in `casts()`.
- [Actions](../3-actions/0-index.md) — actions are typically what trigger transitions.
- [Rulesets](../3-actions/2-rulesets.md) — `ValidStateRule` validates transitions before the action runs.

[Back to chapter](0-index.md) · [Back to index](../0-index.md)
