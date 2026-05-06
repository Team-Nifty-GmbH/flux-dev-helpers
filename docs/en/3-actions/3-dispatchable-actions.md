# Dispatchable Actions

`FluxErp\Actions\DispatchableFluxAction` is the queueable variant of `FluxAction`. Subclass it instead of `FluxAction` when the action should be dispatchable to the queue, optionally inside a `Bus::monitoredBatch()`, with full queue-monitor integration.

## The base class

```php
abstract class DispatchableFluxAction extends FluxAction
    implements ShouldBeMonitored, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    final public function executeAsync(): void
    {
        static::dispatch($this->data, $this->keepEmptyStrings);
    }

    public function handle(): mixed
    {
        return $this->validate()->execute();
    }
}
```

What it adds on top of `FluxAction`:

- **`ShouldQueue`** — Laravel will route it through the queue.
- **`ShouldBeMonitored`** — `QueueMonitorManager` records its lifecycle, `IsMonitored` exposes progress/message/accept methods. See [Monitored jobs](../6-queue-monitoring/1-monitored-jobs.md).
- **`Batchable`, `Dispatchable`, `InteractsWithQueue`, `Queueable`** — the standard Laravel job traits.
- **`executeAsync()`** — fluent dispatch; equivalent to `static::dispatch($data, $keepEmptyStrings)`.
- **`handle()`** — Laravel's queue worker entry point. Calls `validate()->execute()`, so the validated, executed flow is the same on the queue as in-process.

The class is `final` on `executeAsync()` — overriding it would defeat the purpose, since the data and `keepEmptyStrings` flag must round-trip via the dispatch payload.

## Calling sites

### In-process (synchronous)

```php
$result = SendMail::make($data)
    ->checkPermission()
    ->validate()
    ->execute();
```

Same as a regular `FluxAction`. The `ShouldQueue` interface only matters when dispatched.

### Async (queued)

```php
SendMail::make($data)
    ->checkPermission()
    ->validate()
    ->executeAsync();
```

`executeAsync()` queues the job. The worker picks it up and calls `handle()`, which calls `validate()->execute()` again — yes, validation runs twice. The first call confirms validity at dispatch time so the user sees errors synchronously; the second is a defence-in-depth check on the worker.

If you've already validated upstream (e.g. on a Livewire component), it's still safe to call `executeAsync()` directly — the worker validates again, so you can't enqueue a job whose data is invalid by the time it runs.

### Inside a monitored batch

```php
$batchJobs = collect($recipients)->map(fn ($r) => SendMail::make([...])->validate())->all();

Bus::monitoredBatch($batchJobs)
    ->name(__('Email send'))
    ->allowFailures()
    ->dispatch();
```

The `Batchable` trait makes the action eligible for `Bus::batch()`. The `monitoredBatch()` wrapper adds the queue-monitor lifecycle hooks. See [Monitored batches](../6-queue-monitoring/2-monitored-batches.md) for the batch side.

## Why subclass `DispatchableFluxAction` instead of writing a Laravel job

You _could_ write a regular `Illuminate\Contracts\Queue\ShouldQueue` job. The reasons not to, when the work is mutating Flux state:

- Validation, permission check, and lifecycle events come for free.
- Rulesets compose with the rest of the system.
- A nested action chain (e.g. `SendMail` calling `CreateActivityLogEntry`) reuses the parent's transaction without you wiring it.
- Permission name (`action.{name}`) is consistent — no parallel permission system for "queue jobs".
- Container resolution, action discovery, and Scramble tagging all work the same way.

Use a plain Laravel job only when the work is genuinely outside the action model — pure I/O, third-party integration, periodic maintenance — and there's no domain mutation to validate.

## Serialization

The action is serialised to the queue payload via PHP's `serialize()`. `FluxAction::__serialize()` controls what survives:

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

What this means in practice:

- **All your typed properties survive.** A `protected string $component` declared on a job subclass is serialised and unserialised correctly.
- **The `Batchable` trait's `batchId` survives.** Pre-PR-#1695 the explicit `'data' / 'result' / 'rules'` shape stripped it; the current `get_object_vars()` shape preserves it. This is what makes a `DispatchableFluxAction` valid inside `Bus::monitoredBatch()`.
- **Closure-based validation rules don't survive.** If `$rules` includes a `Rule::when(closure)` or similar, serialisation fails the rules-only branch and the rules are dropped from the payload. On the worker, `setRulesFromRulesets()` re-derives them from the static ruleset declarations.
- **The event dispatcher is dropped.** It's not serialisable. `fireActionEvent()` lazily re-resolves it on the worker.

What you should _not_ pass through:

- Live Eloquent model instances. They serialise (Laravel's `SerializesModels` is part of the trait stack), but the queue payload will contain the model's class + key, then re-fetch on the worker. If the model is large or relations are eager-loaded, this bloats the payload. Pass IDs and re-fetch in `performAction()`.
- Closures. Use class references (`SerializableClosure` or `method()` calls).
- Resources, sockets, file handles — the obvious non-serialisables.

## Constructor signature for queued jobs

For actions that queue, the data array is the only thing that travels through the queue payload. Keep it data-only:

```php
class SendMail extends DispatchableFluxAction
{
    public function performAction(): array
    {
        $to = data_get($this->data, 'to');
        $subject = data_get($this->data, 'subject');
        // ...
    }
}
```

If your job needs additional construction parameters (file paths, configuration, …), the alternative is to subclass and add typed constructor properties — they survive serialisation thanks to `get_object_vars()`. `ExportDataTableJob` does this:

```php
public function __construct(
    protected string $component,
    protected string $modelClass,
    protected array $columns,
    protected string $userMorph,
    protected string $format = 'xlsx',
    protected bool $formatted = true,
) {
    $this->columns = array_filter($columns);
}
```

Note that `ExportDataTableJob` is _not_ a `DispatchableFluxAction` — it's a plain Laravel job that opts into `IsMonitored`. The pattern works the same way: typed properties survive, the queue worker calls `handle()`.

## Queue configuration

The standard Laravel job APIs apply:

- `->onQueue('exports')` — pin to a specific queue.
- `->onConnection('redis')` — pin to a specific connection.
- `->delay(now()->addMinutes(5))` — defer.
- `->afterCommit()` — defer dispatch until the surrounding DB transaction commits.

These are methods on the `PendingDispatch` returned by `static::dispatch(...)`. Since `executeAsync()` is `final` and doesn't return the pending dispatch, you call `static::dispatch(...)` directly when you need to configure the queue:

```php
SendMail::dispatch($data, false)
    ->onQueue('mail')
    ->delay(now()->addMinutes(1));
```

## When `executeAsync()` is the wrong choice

`executeAsync()` queues the action with the data you already have. There's no opportunity to inspect the result before it runs.

If you need:

- The action's result before deciding what's next → use `execute()` synchronously.
- Different validation than the worker will do → don't try to skip validation; either fix the rules or write a separate action with the looser rules.
- The user to wait for the result → keep it synchronous; queue dispatch is for fire-and-forget.

## Permission checking on dispatch

```php
SendMail::make($data)
    ->checkPermission()    // throws if user lacks 'action.send.mail'
    ->validate()
    ->executeAsync();
```

`checkPermission()` runs at dispatch time, in the user's auth context. By the time the worker picks the job up, the user may be gone — `auth()->user()` is null on the worker — so the permission must be enforced upstream. The worker's `handle()` does not re-check permission.

If you want the worker to re-check (e.g. the user might have lost the permission while the job was queued), call `checkPermission()` in `performAction()` explicitly. But the typical assumption is "if the dispatcher had permission at the time, the work is authorised".

## Combining with `actingAs()` across the queue boundary

`->actingAs($user)` is currently a synchronous concept — it switches `auth()->user()` for the duration of `execute()`. Across the queue, the property survives serialisation (it's a typed property, `get_object_vars()` keeps it), and `execute()` on the worker honours it. So this works:

```php
SendMail::make($data)
    ->actingAs($systemUser)
    ->validate()
    ->executeAsync();
```

…and the queue worker runs the action with `$systemUser` as `auth()->user()` for the duration of `performAction()`.

## Related

- [FluxAction](1-flux-action.md) — the synchronous lifecycle the worker re-runs.
- [Permissions](4-permissions.md) — `checkPermission()` semantics.
- [Monitored jobs](../6-queue-monitoring/1-monitored-jobs.md) — the `IsMonitored` API the dispatched job exposes.
- [Monitored batches](../6-queue-monitoring/2-monitored-batches.md) — `Bus::monitoredBatch()` for many actions in one toast.
- [Recipes](../6-queue-monitoring/4-recipes.md) — `EditMail` consolidates a bulk send into a monitored batch.

[Back to chapter](0-index.md) · [Back to index](../0-index.md)
