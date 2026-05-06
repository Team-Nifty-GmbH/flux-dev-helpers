# Queue Monitoring

Flux ships a queue-monitoring layer on top of Laravel's queue: every job that opts in gets a database row recording its lifecycle, and every user attached to that row gets live toast notifications with a progress bar, an elapsed/remaining time line, and optional accept/reject buttons.

The same primitive scales from a single job to a Laravel batch: wrap a list of jobs in `Bus::monitoredBatch(...)` and the recipient sees one consolidated toast for the whole batch, not one per job.

## What this chapter covers

1. [Monitored jobs](1-monitored-jobs.md) — the `ShouldBeMonitored` contract, the `IsMonitored` trait, and the API a job uses to report progress, set a friendly name, and attach buttons.
2. [Monitored batches](2-monitored-batches.md) — `Bus::monitoredBatch()`, `JobBatch`, `allowFailures()`, and how batch progress aggregates from individual jobs.
3. [Progress and toasts](3-progress-and-toasts.md) — the toast lifecycle (started → processing → finished), the `progressMeta` attribute, and the frontend rules that keep progress bars from flickering across re-renders.
4. [Recipes](4-recipes.md) — real flux-core examples: `ExportDataTableJob` streaming chunked progress with a download button, and `EditMail` consolidating a bulk send into a single batch toast.

## How the pieces fit together

```
┌──────────────────────────────────────────────────────────────────────┐
│  Application code                                                    │
│                                                                      │
│  $action->executeAsync()      Bus::monitoredBatch([...])->dispatch() │
│        │                                  │                          │
│        ▼                                  ▼                          │
│  ShouldBeMonitored                MonitorablePendingBatch            │
│  + IsMonitored                    (before/progress/finally hooks)    │
└────────┬─────────────────────────────────┬───────────────────────────┘
         │                                 │
         ▼                                 ▼
┌──────────────────────┐           ┌──────────────────────┐
│ QueueMonitorManager  │           │ JobBatch (model)     │
│ (event listener)     │           │ over Laravel Batch   │
│                      │           │                      │
│ jobQueued/Processing │           │ progress, elapsed,   │
│ /Processed/Failed    │           │ remaining, processed │
└──────────┬───────────┘           └──────────┬───────────┘
           │                                  │
           ▼                                  ▼
┌──────────────────────────────────────────────────────────┐
│ QueueMonitor row(s) + JobBatch row                       │
│ Lifecycle hooks send notifications to attached users.    │
└──────────────────────┬───────────────────────────────────┘
                       │
                       ▼
┌──────────────────────────────────────────────────────────┐
│ JobStarted / JobProcessing / JobFinished Notifications   │
│ Batch{Started,Processing,Finished}Notification           │
│   via BroadcastNowChannel (immediate) + DatabaseChannel  │
└──────────────────────┬───────────────────────────────────┘
                       │
                       ▼
                  Toast in browser
```

The whole pipeline is event-driven. No polling, no periodic refresh: the toast updates the moment the job calls `queueProgress()`.

## When to use what

- **Single job, monitored** → `implements ShouldBeMonitored` + `use IsMonitored`. See [Monitored jobs](1-monitored-jobs.md).
- **`DispatchableFluxAction`** → already monitored. Just dispatch with `executeAsync()`.
- **Many jobs, one toast** → `Bus::monitoredBatch([...])->dispatch()`. See [Monitored batches](2-monitored-batches.md).
- **Want a download/cancel button on the finished toast** → `$this->accept(NotificationAction::make()->...)`. See [Progress and toasts](3-progress-and-toasts.md).

## Related

- [Notifications](../5-notifications/0-index.md) — the underlying `Notification` and `ToastNotification` API that the queue-monitor toasts build on.
- [Events & Broadcasting](../7-events-and-broadcasting/0-index.md) — `BroadcastNowChannel`, the channel that delivers toast updates immediately rather than being queued.
- [Actions](../3-actions/0-index.md) — `DispatchableFluxAction` is the most common monitored job.

[Back to index](../0-index.md)
