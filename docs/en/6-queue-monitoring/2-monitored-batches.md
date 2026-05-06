# Monitored Batches

A monitored batch is a Laravel `Bus::batch()` that:

- creates a `JobBatch` model row alongside the native `job_batches` row,
- attaches the dispatching user to the batch,
- sends a single consolidated toast for the whole batch (started, processing, finished) instead of one per job.

The user's experience is one toast that fills up as jobs complete, with `4 / 12` style counters and a single "finished" notification when the last job lands.

## Dispatching a batch

```php
use FluxErp\Actions\Mail\SendMail;
use Illuminate\Support\Facades\Bus;

$batchJobs = collect($recipients)
    ->map(fn ($recipient) => SendMail::make($this->buildPayload($recipient))
        ->checkPermission()
        ->validate()
    )
    ->all();

Bus::monitoredBatch($batchJobs)
    ->name(__('Email send'))
    ->allowFailures()
    ->dispatch();
```

Three things to note:

1. The jobs in the batch must be `Batchable` (the trait or a parent class with it). All `DispatchableFluxAction` subclasses are batchable.
2. `name(...)` becomes the toast title. Without it the toast falls back to the batch ID, which is meaningless to the user.
3. `allowFailures()` is the right default in almost every case — without it, the first failed job aborts the entire batch. For bulk sends, exports, imports, you almost always want each job to be tried independently.

`Bus::monitoredBatch()` returns a `MonitorablePendingBatch`, which extends Laravel's `PendingBatch`. Every method on `PendingBatch` (`then`, `catch`, `finally`, `name`, `onQueue`, `onConnection`, `allowFailures`, `withOption`, `dispatch`) is available as normal — the only addition is the lifecycle hooks the `MonitorablePendingBatch` constructor pre-installs (see _What the wrapper does_ below).

## What the wrapper does

`MonitorablePendingBatch` (`packages/flux-core/src/Support/Bus/MonitorablePendingBatch.php`) installs three batch hooks at construction time:

- `before(...)` — when Laravel's batch enters the queue, the hook resolves the dispatching user (from `auth()` or the queued `Context::get('user')`) and attaches them to the `JobBatch`. It then sends `BatchStartedNotification`. The user sees a persistent toast immediately.
- `progress(...)` — Laravel fires this after every job finishes. The hook fans out a `BatchProcessingNotification` to every user attached to the batch (or `BatchFinishedNotification` if `getProcessedJobs() == total_jobs`). The toast updates in place.
- `finally(...)` — when the batch is fully resolved (all jobs done or failures handled), every attached user gets `BatchFinishedNotification`.

These three hooks are pre-installed. Hooks you add yourself with `->before(...)`, `->then(...)`, `->finally(...)` run in addition.

## How progress is computed

`JobBatch` (`packages/flux-core/src/Models/JobBatch.php`) wraps the underlying Laravel batch row and exposes UI-ready helpers:

```php
public function getProcessedJobs(): int
{
    return $this->total_jobs - $this->pending_jobs + $this->failed_jobs;
}

public function getProgress(): float
{
    return $this->isFinished()
        ? 1.0
        : ($this->total_jobs > 0 ? $this->getProcessedJobs() / $this->total_jobs : 0.0);
}
```

The `+ $failed_jobs` is intentional. A job that fails permanently is removed from `pending_jobs` but added to `failed_jobs`. If you didn't add `failed_jobs` back, the progress bar would underreport — a batch where every job failed would still look 0% done because nothing was counted as processed.

`getElapsedInterval()` returns a `CarbonInterval` from `created_at` to now (or `finished_at`). `getRemainingInterval()` extrapolates from the current progress against the elapsed time. Both return `0 seconds` for batches that are 0%, 100%, or have no rows yet — they fail safe rather than throw on division by zero.

## Finished toast severity

`BatchFinishedNotification` picks its severity from the failure ratio:

- `failed_jobs == 0` → success toast, `:count jobs succeeded`.
- `failed_jobs == total_jobs` → error toast, `All jobs have failed`.
- otherwise → warning toast, `:success of :total jobs succeeded, :failed failed`.

This is the right behaviour for `allowFailures()` batches: a partial success looks visually distinct from a clean run, but a full failure is still red.

## Working with the underlying Laravel batch

If you need access to the Laravel `Batch` (e.g. to call `cancel()`), `JobBatch::getBatch()` resolves it:

```php
$jobBatch = JobBatch::find($id);
$jobBatch->getBatch()?->cancel();
```

`getBatch()` returns `null` if Laravel has already pruned the underlying batch.

## What does and doesn't go into a monitored batch

The batch wraps Laravel's batching. Each job inside the batch:

- _Does_ run through Laravel's batching machinery (per-job retry, batch cancellation, the standard `then`/`catch`/`finally` callbacks).
- _Does not_ get its own `JobStarted` / `JobProcessing` / `JobFinished` toast. Per-job notifications are suppressed when `job_batch_id` is set; only the batch-level notifications are sent. (`JobFinishedNotification`'s id is derived from `job_batch_id ?? job_id`, so all jobs in a batch deduplicate to the same toast.)
- _Does_ still get a `QueueMonitor` row each. You can drill in for diagnostics — `JobBatch::queueMonitors()` returns all of them — but the user only sees the batch-level toast.

This is the desired UX: bulk sending 200 emails to a list should not produce 600 toasts. It should produce one toast that fills up.

## When to batch vs. dispatch individually

| Situation | Use |
|---|---|
| Many of the same job over a list | `Bus::monitoredBatch([...])` |
| One job triggered by user action | `$action->executeAsync()` |
| Several distinct jobs, but the user cares about each one finishing | individual `executeAsync()` calls |
| Bulk operation where some failures are tolerable | `monitoredBatch(...)->allowFailures()` |
| Bulk operation that must be atomic (all-or-nothing) | `monitoredBatch(...)` without `allowFailures()` |

## Related

- [Monitored jobs](1-monitored-jobs.md) — the per-job API; jobs in a batch must use it (or extend `DispatchableFluxAction`).
- [Recipes](4-recipes.md) — `EditMail` shows a monitored batch in production.
- [Actions](../3-actions/0-index.md) — `DispatchableFluxAction` includes the `Batchable` trait.

[Back to chapter](0-index.md) · [Back to index](../0-index.md)
