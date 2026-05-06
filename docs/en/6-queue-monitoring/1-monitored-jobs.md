# Monitored Jobs

A monitored job is any queued job that records its lifecycle in the `queue_monitors` table and pushes toast notifications to the users attached to it.

## Opting in

Two pieces, both required:

```php
use FluxErp\Contracts\ShouldBeMonitored;
use FluxErp\Traits\IsMonitored;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ExportDataTableJob implements ShouldBeMonitored, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, IsMonitored, Queueable;

    public function handle(): void
    {
        // ...
    }
}
```

- `ShouldBeMonitored` is the contract that `QueueMonitorManager` looks for when handling Laravel queue events. No methods — it is a marker interface.
- `IsMonitored` is the trait that adds the developer-facing API (`queueProgress`, `message`, `accept`, `reject`, `getName`, …).

`DispatchableFluxAction` already implements the contract. Subclassing it is the shortest path to a monitored job:

```php
abstract class DispatchableFluxAction extends FluxAction
    implements ShouldBeMonitored, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;
}
```

(Note the `Batchable` trait — that's what makes a `DispatchableFluxAction` usable inside `Bus::monitoredBatch()`. See [Monitored batches](2-monitored-batches.md).)

## What gets recorded

When the job is dispatched, `QueueMonitorManager` listens on Laravel's queue events (`JobQueued`, `JobProcessing`, `JobProcessed`, `JobFailed`, `JobExceptionOccurred`) and writes a row to `queue_monitors`:

| Field | Set by | Notes |
|---|---|---|
| `job_id` | Laravel queue id, or `md5(payload)` as fallback | |
| `job_uuid` | Laravel queue uuid | Used by `retry()` |
| `job_batch_id` | Laravel `batchId` on the job | Null for standalone jobs |
| `name` | `getName()` from the job, falling back to FQCN | What appears in the toast title |
| `state` | `Queued` → `Running` → `Succeeded` / `Failed` / `Stale` | Spatie ModelStates |
| `progress` | `queueProgress*()` — stored as 0–1 float | |
| `message` | `message()` | Free-text status |
| `data` | `queueData()` | Arbitrary JSON |
| `accept`, `reject` | `accept()` / `reject()` — `serialize(NotificationAction)` | Buttons on the finished toast |
| `started_at`, `finished_at` | event timestamps | Plus microsecond-precise `*_exact` siblings |
| `exception_class`, `exception_message` | filled on failure | |

Records older than 30 days are pruned automatically (`MassPrunable`).

If you do not need the row to survive the job, override `keepMonitorOnSuccess()`:

```php
public static function keepMonitorOnSuccess(): bool
{
    return false; // delete the QueueMonitor row on successful completion
}
```

## Friendly job name

Override `getName()` to control what appears in the toast title. The default returns the FQCN, which is rarely what you want.

```php
public function getName(): string
{
    return __(':model export', [
        'model' => __(Str::headline(morph_alias($this->modelClass))),
    ]);
}
```

The name is resolved in two places:

- When the job is queued (`JobQueued` event) — `QueueMonitorManager::getJobName()` deserializes the queued payload and calls `getName()` on the resulting instance, so the row's `name` column is correct from the start.
- When the toast is rendered — `QueueMonitor::getJobName()` returns the stored value (or, for jobs the manager could not resolve, falls back to the FQCN).

This means the title appears correctly in the very first toast (the "started" notification), not just after the job runs.

## Reporting progress

The trait exposes three progress methods. All three accept integers in 0–100; the trait stores them as 0–1 floats internally.

### `queueProgress(int $progress)`

Sets the absolute progress.

```php
foreach ($items as $i => $item) {
    $this->process($item);
    $this->queueProgress((int) (($i + 1) / count($items) * 100));
}
```

Bounded to `[0, 100]` automatically.

### `queueProgressAdvance(int $step = 1)`

Increments by `$step`. Useful when you don't have an index.

```php
$query->lazy()->each(function ($row) {
    $this->process($row);
    $this->queueProgressAdvance(/* default 1 */);
});
```

### `queueProgressChunk(int $total, int $perChunk)`

For chunked processing where each call represents one chunk of `$perChunk` items out of `$total`. Internally maintains a counter so you don't have to.

```php
foreach ($query->chunk(500) as $chunk) {
    $this->process($chunk);
    $this->queueProgressChunk(total: $totalRows, perChunk: 500);
}
```

### Cooldown

Progress writes are throttled. If you call `queueProgress(42)` ten thousand times in quick succession, the database does not get ten thousand updates — only the first one (and subsequent calls outside the cooldown window) hit the row.

The cooldown is configurable per job:

```php
public function progressCooldown(): int
{
    return 1; // throttle to one DB write per second
}
```

Default is `0` (no throttle). The values `0`, `25`, `50`, `75`, `100` always bypass the cooldown — milestones are guaranteed to land.

## Status message

`message(string|HtmlString $message)` sets free-text content rendered in the toast description. Useful for "X rows", "step 2 of 4", etc.

```php
$total = $query->count();
$this->message(__(':count :model rows', [
    'count' => $total,
    'model' => __(Str::plural(Str::headline(morph_alias($this->modelClass)))),
]));
```

You can pass `HtmlString` if you need rendered HTML (the toast description allows `<br>` for line breaks, but the rest of the input is escaped — see [Progress and toasts](3-progress-and-toasts.md)).

## Arbitrary data

`queueData(array $data, bool $merge = false)` stores arbitrary JSON in the `data` column. Useful for context the toast doesn't render but you want to consult later (admin views, debugging).

```php
$this->queueData(['source' => 'manual-trigger', 'requested_by' => auth()->id()]);
```

`$merge = true` does a shallow merge with the existing `data` array.

## Bulk update

`queueUpdate(array $attributes)` is a one-shot for setting `progress`, `message`, and `data` together (it respects the same cooldown as `queueProgress`):

```php
$this->queueUpdate([
    'progress' => 60,
    'message'  => __('Generating PDF'),
    'data'     => ['phase' => 'render'],
]);
```

## Action buttons on the finished toast

When the job completes, Flux sends a `JobFinishedNotification`. If the job called `accept()` or `reject()` during execution, the toast renders those as buttons.

`accept()` is for the positive action — the typical case is a download link for the file the job produced.

```php
use FluxErp\Support\Notification\ToastNotification\NotificationAction;

$this->accept(
    NotificationAction::make()
        ->label(__('Download'))
        ->url(route('private-storage', ['path' => $filePath]))
        ->download()
);
```

`reject()` is for the negative action — typically "Cancel" or "Discard". Use it when the user should be able to undo or dismiss the result of the job.

`NotificationAction` itself is documented in [Notification actions](../5-notifications/0-index.md). The shape it can take:

- `->label(string)` — button text.
- `->url(string)` or `->route(string $name, array $params = [])` — navigate on click.
- `->download(true)` — turn navigation into a download.
- `->method(string)->params(mixed)` — call a Livewire method on click.
- `->execute(string)` — run JavaScript.
- `->style(string)`, `->solid(bool)` — appearance.

The action is stored on the `QueueMonitor` row via `serialize()`, so anything you reference must be serializable. URLs and primitives are safest; closures and live model instances are not.

## Failure

If the job throws and Laravel decides it has failed permanently (`hasFailed() === true`), `QueueMonitorManager` writes the exception class and message to `exception_class` / `exception_message` and transitions the state to `Failed`. The user gets a `JobFinishedNotification` in error state.

If the job throws but Laravel will retry, the manager rolls the row back to `Queued` and a new attempt will create a fresh `QueueMonitor` row when it starts. Older unfinished rows for the same `job_id` are marked `Stale`.

Retries are first-class: if the row's state is `Failed` and the `job_uuid` is still present, `QueueMonitor::canBeRetried()` returns `true` and `$monitor->retry()` shells out to `php artisan queue:retry {uuid}`.

## API reference

`IsMonitored` (`packages/flux-core/src/Traits/IsMonitored.php`):

| Method | Purpose |
|---|---|
| `getName(): string` | Title shown in toasts. Override. |
| `queueProgress(int $progress)` | Set absolute progress, 0–100. |
| `queueProgressAdvance(int $step = 1)` | Increment progress. |
| `queueProgressChunk(int $total, int $perChunk)` | Chunked progress. |
| `message(string\|HtmlString $message)` | Set status message. |
| `queueData(array $data, bool $merge = false)` | Store arbitrary JSON. |
| `queueUpdate(array $attributes)` | Bulk set progress/message/data. |
| `accept(NotificationAction $action)` | Button on finished toast (positive). |
| `reject(NotificationAction $action)` | Button on finished toast (negative). |
| `progressCooldown(): int` | Override to throttle progress writes (seconds). |
| `keepMonitorOnSuccess(): bool` | Override to delete the row on success. |

## Related

- [Monitored batches](2-monitored-batches.md) — wrap many monitored jobs in one consolidated toast.
- [Progress and toasts](3-progress-and-toasts.md) — toast lifecycle, `progressMeta`, frontend behaviour.
- [Recipes](4-recipes.md) — concrete examples from flux-core.
- [Actions](../3-actions/0-index.md) — `DispatchableFluxAction` for the action-as-monitored-job pattern.
- [Notifications](../5-notifications/0-index.md) — `NotificationAction` and the underlying toast API.

[Back to chapter](0-index.md) · [Back to index](../0-index.md)
