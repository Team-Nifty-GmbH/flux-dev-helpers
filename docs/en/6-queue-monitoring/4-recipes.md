# Recipes

Two real flux-core examples that exercise the queue-monitor API end-to-end.

## Recipe 1: chunked export with a download button

`ExportDataTableJob` is dispatched whenever a user clicks "Export" on a data table. It needs to:

- Stream progress to the user as rows are written, so the toast bar advances rather than sitting at 0% for minutes.
- Surface the resulting file as a download button on the finished toast.
- Use a friendly, model-aware title (`Order export`, `Contact export`, …) instead of `ExportDataTableJob`.

```php
namespace FluxErp\Jobs;

use FluxErp\Contracts\ShouldBeMonitored;
use FluxErp\Support\Notification\ToastNotification\NotificationAction;
use FluxErp\Traits\IsMonitored;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

class ExportDataTableJob implements ShouldBeMonitored, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, IsMonitored, Queueable;

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

    public function getName(): string
    {
        return __(':model export', [
            'model' => __(Str::headline(morph_alias($this->modelClass))),
        ]);
    }

    public function handle(): void
    {
        $user = morph_to($this->userMorph);

        // ... build query, resolve component, choose export class ...

        $total = (clone $query)->toBase()->getCountForPagination();
        $this->message(__(':count :model rows', [
            'count' => $total,
            'model' => __(Str::plural(Str::headline(morph_alias($this->modelClass)))),
        ]));

        app($exportClass, [
            'builder'       => $query,
            'exportColumns' => $this->columns,
            'formatters'    => $formatters,
            'onChunk'       => $total > 0
                ? fn (int $processed) => $this->queueProgress(
                    min(99, (int) ($processed / $total * 100))
                )
                : null,
        ])->store($filePath);

        $this->accept(
            NotificationAction::make()
                ->label(__('Download'))
                ->url(route('private-storage', ['path' => $filePath]))
                ->download()
        );
    }
}
```

Three things worth pointing out:

- **`min(99, ...)` on the chunk callback.** The bar is held at 99% during the final write. The transition to 100% is left to `JobFinishedNotification`, which fires after `accept()` has stored the download action. If you let the chunk callback hit 100%, the user briefly sees a finished bar with no download button.
- **`message()` is called once, before the loop.** It sets the static "rows" line; the moving part is the bar and the `progressMeta` (`:time remaining`), both managed by the framework. Don't call `message()` inside the chunk callback — it would flap on every update for no benefit.
- **`accept()` is called after `store()`.** The `QueueMonitor` row has to exist to receive the action, and the URL to a file that doesn't exist yet would fail the download. Order matters: write the file, then attach the action, then the framework sends the finished toast.

The companion change on the data-table side is gone:

```php
// Removed from BaseDataTable::export()
$this->toast()->success(
    __('Export started'),
    __('Your export is being processed. ...')
)->send();
```

The `JobStartedNotification` now does this job — the user gets a "started" toast from the queue-monitor pipeline, which then upgrades into the progressing/finished toast as the job runs. Two separate toasts are no longer needed.

## Recipe 2: bulk send as a single batch toast

`EditMail::sendGroupMessages()` (`packages/flux-core/src/Livewire/EditMail.php`) bulk-sends an outgoing campaign. Before the batch wrapper, every recipient produced its own `SendMail` async dispatch — which meant N toasts.

The fix:

```php
use FluxErp\Actions\Mail\SendMail;
use Illuminate\Support\Facades\Bus;

protected function sendGroupMessages(/* ... */): array
{
    $successCount = 0;
    $failedCount  = 0;
    $batchJobs    = [];

    foreach ($mailMessages as $mailMessage) {
        try {
            // ... build $data ...

            $batchJobs[] = SendMail::make($data)
                ->checkPermission()
                ->validate();

            $successCount++;
        } catch (Throwable $e) {
            $failedCount++;
            // ...
        }
    }

    if ($batchJobs) {
        Bus::monitoredBatch($batchJobs)
            ->name(__('Email send'))
            ->allowFailures()
            ->dispatch();
    }

    return ['success' => $successCount, 'failed' => $failedCount];
}
```

What changed compared to dispatching individually:

- **Validation runs synchronously, before the batch is dispatched.** `validate()` returns the action ready to run. If validation throws, the user gets immediate feedback and the bad message never enters the batch — `$failedCount` increments and the loop continues. The batch only contains messages that are known to be valid at dispatch time.
- **`->name(__('Email send'))` is essential.** Without it the toast title would be the batch UUID. The name is what the user sees on the started/processing/finished toasts.
- **`->allowFailures()` is the right policy here.** A single bad recipient (e.g. a malformed address that slipped through validation, or an SMTP rejection) shouldn't abort the rest of the campaign. Each job retries independently within Laravel's batch machinery, and the finished toast reports the success/failure ratio (success / warning / error).
- **The old `showSendResultToast()` is gone.** The batch's finished notification replaces it. The `:success of :total jobs succeeded, :failed failed` description shows the same information the manual toast did, but live and per-batch.

## What to take away

Both recipes share the same shape:

1. Mark the job as monitored (`ShouldBeMonitored` + `IsMonitored`) or use a class that already is (`DispatchableFluxAction`, like `SendMail`).
2. Give it a name (`getName()` or `Bus::monitoredBatch(...)->name(...)`).
3. Report progress (`queueProgress*()` for a single job; the batch wrapper does it for you across jobs).
4. Attach a button if there's a result the user should act on (`accept()` / `reject()`).
5. Let the framework handle the toast lifecycle. Don't manually emit "started" / "in progress" / "done" toasts.

## Related

- [Monitored jobs](1-monitored-jobs.md) — the job-side API used in recipe 1.
- [Monitored batches](2-monitored-batches.md) — the batch-side API used in recipe 2.
- [Progress and toasts](3-progress-and-toasts.md) — why the toast keeps its progress on re-render and what `progressMeta` is for.

[Back to chapter](0-index.md) · [Back to index](../0-index.md)
