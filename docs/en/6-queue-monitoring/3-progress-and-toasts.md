# Progress and Toasts

This page describes the toast lifecycle, the `progressMeta` attribute, and the frontend rules that keep progress bars and download buttons working correctly.

## Lifecycle: which toast fires when

For a single monitored job:

| Event | Notification | Channels | Toast behaviour |
|---|---|---|---|
| Job enqueued | `JobStartedNotification` | `BroadcastNowChannel` | Persistent toast appears with progress 0. |
| Progress reported | `JobProcessingNotification` | `BroadcastNowChannel` | Same toast id; bar advances, `:time remaining` updates. |
| Job finished | `JobFinishedNotification` | `BroadcastNowChannel`, `DatabaseChannel`, optionally `MailChannel` | Same toast id; bar fills to 100%, severity reflects success/failure, accept/reject buttons render if set. |

For a monitored batch:

| Event | Notification |
|---|---|
| Batch starts | `BatchStartedNotification` |
| Each job finishes inside the batch | `BatchProcessingNotification` |
| All jobs done | `BatchFinishedNotification` |

The toast IDs are designed to deduplicate: the started/processing/finished notifications for the same job (or the same batch) all share an id, so the browser updates one toast instead of stacking three.

## `progressMeta` — the trailing metadata line

The toast description has two parts:

- The main message, set by the job (`message()`) or by the notification itself (e.g. `:count :model rows`).
- A trailing metadata line, set via the `progressMeta` attribute on the toast.

`progressMeta` is rendered as small, dimmed text under the progress bar. The queue-monitor notifications use it for time information:

```php
return ToastNotification::make()
    // ...
    ->progress($this->model->jobBatch?->progress ?? $this->model->progress)
    ->attributes([
        'progressMeta' => __(':time remaining', [
            'time' => $this->model->getRemainingInterval(),
        ]),
    ]);
```

Why a separate attribute and not just appended to the description?

- The description is set by the job and may be HTML; mixing it with framework-controlled metadata makes both harder to render and to escape.
- The metadata line should always look the same (small, dim, beneath the bar). Styling it consistently is easier when it has its own slot.
- It gives the toast template room to format differently — the actual rendering happens in `Notification::toast()` (`packages/flux-core/src/Models/Notification.php`), which inserts a `<div class="text-xs opacity-70 mt-1">` for the meta line.

You can use `progressMeta` for any short status string that complements the progress bar — a phase name, a counter, an ETA. Anything that should be _adjacent_ to the bar but not _part of_ the description.

## Why progress doesn't reset when the toast list re-renders

Toasts live inside an Alpine `x-for` loop. When a notification ID changes (or when an unrelated toast is appended), Alpine can re-mount the toast DOM nodes. Without precaution, that re-mount resets the progress bar's width to 0 and animates it back up to current — visually jarring.

Flux pins the progress width on `window` so it survives re-mounts:

```html
<div
    x-data="{
        w: (window._toastProgress?.[toast.id] ?? 0),
    }"
    x-init="requestAnimationFrame(() => requestAnimationFrame(() => {
        w = toast.progress * 100;
        (window._toastProgress = window._toastProgress || {})[toast.id] = w;
    }))"
    x-bind:style="`width: ${w}%; transition: width 700ms ease-out;`"
    ...>
```

When the toast remounts, the new instance reads its previous width from `window._toastProgress[toast.id]` and animates from that to the new value. There is no flicker.

You don't need to do anything to opt in — every toast that calls `->progress(...)` gets this treatment automatically.

## Why incoming toast IDs are ignored on update

When a Livewire broadcast lands, the frontend looks up the existing toast by ID and copies the new fields onto it. The `id` and `toastId` fields on the incoming event are explicitly skipped:

```js
// resources/js/components/tallstackui/toast.js
Object.keys(event.detail).forEach((key) => {
    if (key === 'id' || key === 'toastId') {
        return;
    }
    toast[key] = event.detail[key];
});
```

Without this, an updated toast would replace its own ID with the (identical) incoming one, briefly destabilising Alpine's reactivity tracking and causing a flicker. Skipping the assignment keeps the same JS reference alive across updates.

## Description rendering rules

Toast descriptions are rendered by `Notification::toast()` (`packages/flux-core/src/Models/Notification.php`):

- The raw description is run through `strip_tags($description, '<br>')` — line breaks survive, everything else is escaped. **Do not pass HTML strings expecting them to render**. If you need a multi-line description, separate the lines with `<br>` and accept that links/images will not be rendered.
- If `progress` is set (any value, including `0` or `0.0`), the bar is rendered. Use `null` to mean "no bar". The early code used `data_get($data, 'progress')` which treated `0` as falsy; the corrected check is `! is_null(...)`, which means a job in a "0% / queued" state still gets a bar.
- If `accept` or `reject` is set, the toast is forced persistent (`timeout = 0`).

## Accept/reject button rendering

When the finished toast renders, the accept and reject `NotificationAction` instances are unserialized from the `QueueMonitor` row and converted to button props by the toast template. They survive serialization because `NotificationAction` is `Arrayable` and contains only scalars.

The styling defaults are sensible: `accept` renders as the primary action, `reject` as a secondary one. Override with `->style(...)` and `->solid(true|false)` if you need different visual weight.

If both `accept` and `reject` are set, both buttons appear side by side. If only one is set, only that button appears. If neither is set, the toast renders without buttons.

## Title formatting

`getName()` is the single source of truth for the title. It's used:

- In `JobStartedNotification`: `:job_name started`.
- In `JobProcessingNotification`: `:job_name is processing`.
- In `JobFinishedNotification`: `:job_name is finished`.

For batches, the analogous source is `$batch->name(...)` set when dispatching. The notification then uses `:job_name is processing` / `:job_name is finished` with the batch name.

`__()` is applied to the resolved name, so `getName()` can return a translation key directly:

```php
public function getName(): string
{
    return 'order.import'; // resolved through the translator at toast time
}
```

…or it can return a fully composed translated string for cases like the export job, where the model name is interpolated:

```php
public function getName(): string
{
    return __(':model export', [
        'model' => __(Str::headline(morph_alias($this->modelClass))),
    ]);
}
```

Both styles work.

## Related

- [Monitored jobs](1-monitored-jobs.md) — the per-job API that drives these toasts.
- [Monitored batches](2-monitored-batches.md) — batch-level toast behaviour.
- [Recipes](4-recipes.md) — production examples.
- [Notifications](../5-notifications/0-index.md) — `ToastNotification`, attributes, channels.
- [Events & Broadcasting](../7-events-and-broadcasting/0-index.md) — `BroadcastNowChannel`, the immediate-broadcast path that delivers these toasts in real time.

[Back to chapter](0-index.md) · [Back to index](../0-index.md)
