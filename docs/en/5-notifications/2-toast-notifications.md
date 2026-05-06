# Toast Notifications

`FluxErp\Support\Notification\ToastNotification\ToastNotification` is the builder you return from `toToastNotification(object $notifiable): ToastNotification`. It extends Flux's TallStackUI `Toast` wrapper and adds notification-specific concerns: an ID for deduplication, a `notifiable`, accept/reject action buttons, custom attributes, and `toArray()` / `toMail()` / `toWebPush()` / `toFcm()` renderers that derive each format from the same source of truth.

## Minimal example

```php
return ToastNotification::make()
    ->notifiable($notifiable)
    ->title(__('Order created'))
    ->description(__('Order :number is ready for invoicing.', ['number' => $this->order->number]));
```

That's enough to show a green-rimmed info toast with a title and a body. Everything else is optional.

## The full builder API

| Method | Purpose |
|---|---|
| `make(...$args)` | Constructor. Pass an array of method-name → value pairs and they get applied. |
| `notifiable(object)` | The recipient. Required for `toMail()`/`toWebPush()`/`toFcm()` to render correctly. |
| `id(string\|int\|null)` | Stable ID for deduplication / update-in-place. |
| `title(string)` | Toast title. |
| `description(?string)` | Toast body. Run through `strip_tags($desc, '<br>')` at render time. |
| `type(ToastType)` | `INFO` (default), `SUCCESS`, `WARNING`, `ERROR`, `QUESTION`. |
| `progress(float\|int\|string\|null)` | Renders a progress bar. Stored as a float, `null` becomes `0.0`. |
| `progressbar(bool)` | Toggle the indeterminate spinner-style "is working" bar (separate from a value-driven `progress`). |
| `accept(?NotificationAction)` | Primary action button. Forces the toast persistent. |
| `reject(?NotificationAction)` | Secondary action button. Forces the toast persistent. |
| `href(string $url, string $label = 'Open…')` | Shorthand for `accept(NotificationAction::make()->label($label)->url($url))`. |
| `image(?string)` | Image URL stored under `data.image`; used as web-push icon and FCM image. |
| `attributes(array)` | Extra payload data merged into `toArray()`. Used for `progressMeta`, `state`, etc. |
| `markAsRead(bool)` | Mark the underlying DB notification as read when the toast is shown. |
| `persistent()` | Inherited — toast does not auto-dismiss. |
| `timeout(int)` | Inherited — auto-dismiss after N seconds. |
| `emit(string)`, `to(string)`, `method(string)`, `params(mixed)` | Wire the toast click into a Livewire event/method. |
| `onClose(NotificationEvent)`, `onDismiss(NotificationEvent)`, `onTimeout(NotificationEvent)` | Hook into lifecycle events. |
| `toArray()`, `toMail()`, `toWebPush()`, `toFcm()`, `toFcmData()` | Renderers. |

## Choosing a toast type

```php
->type(ToastType::SUCCESS)
```

| Type | Visual cue | Use for |
|---|---|---|
| `INFO` (default) | Neutral / blue | Status updates, FYI |
| `SUCCESS` | Green | Job/batch completed cleanly |
| `WARNING` | Amber | Partial success (some failures, but not all) |
| `ERROR` | Red | Failed jobs, validation errors, unrecoverable conditions |
| `QUESTION` | Neutral with prompt styling | Action required from the user (used with accept/reject) |

The queue-monitor's `BatchFinishedNotification` picks `SUCCESS`, `WARNING`, or `ERROR` based on the failed-jobs ratio — that's the canonical pattern when a notification can land in any of the three states.

## ID-based deduplication

Calling `->id(string|int)` lets later notifications with the same ID _update_ the existing toast in place. Without it, every notification spawns a new toast.

```php
$id = Uuid::uuid5(Uuid::NAMESPACE_URL, $this->model->job_batch_id ?? $this->model->job_id);

return ToastNotification::make()
    ->id($id)
    ->title(...)
    // ...
```

Two practical patterns:

- **Stable ID across the lifecycle of one logical event.** Queue-monitor uses `uuid5(NAMESPACE_URL, batch_id ?? job_id)` so all three notifications (started → processing → finished) of the same job share an ID. The browser sees three updates, not three toasts.
- **Distinct ID per attempt.** If retries should each get their own toast, mix the attempt or `created_at` into the ID seed.

The frontend's TallStackUI integration explicitly ignores `id` and `toastId` fields when an _update_ event lands (see `resources/js/components/tallstackui/toast.js`), which keeps the same JS reference alive across re-renders. See [Progress and toasts](../6-queue-monitoring/3-progress-and-toasts.md) for the full picture.

## Persistent vs. timed toasts

By default a toast auto-dismisses after the configured timeout. You can pin it open:

```php
->persistent()             // never auto-dismiss
// or
->timeout(0)               // equivalent
// or
->timeout(15_000)          // 15 seconds
```

Setting `accept(...)` or `reject(...)` forces the toast persistent — it would be useless otherwise. The user has to interact (or explicitly dismiss) before it goes away. The base toast template applies this implicitly:

```php
// FluxErp\Models\Notification::toast()
if (data_get($this->data, 'accept') || data_get($this->data, 'persistent')) {
    $toast->persistent();
}
```

For status updates that the user doesn't need to ack (e.g. "Order saved"), let it auto-dismiss.

## Progress bars

`->progress(0.42)` renders a bar at 42%. Accepts:

- `null` → no bar (treated as `0.0` internally; a value of `0.0` _does_ render an empty bar — pass `null` if you want no bar at all).
- A float in `[0.0, 1.0]`, an int in `[0, 100]` (will be cast to float), or a string parseable as a number.

The actual rendering happens in `FluxErp\Models\Notification::toast()` (`packages/flux-core/src/Models/Notification.php`). The bar pins its width to `window._toastProgress[id]` so re-mounts inside Alpine `x-for` don't reset the animation. See [Progress and toasts](../6-queue-monitoring/3-progress-and-toasts.md) for the full mechanism.

If you want the indeterminate "is working on it" spinner-style bar instead of a value-driven one:

```php
->progressbar(true)
```

The two are independent: `progress` is the filled percentage; `progressbar` is the boolean toggle for the indeterminate variant.

## Custom attributes (the `progressMeta` slot)

`->attributes([...])` merges arbitrary keys into the toast payload. Two well-known keys:

- `progressMeta` — short trailing text under the progress bar. Used by queue-monitor for `:time remaining` / `:time elapsed`.
- `state` — used by queue-monitor finished toasts to indicate succeeded/failed for downstream handlers.

```php
->progress($this->model->progress)
->attributes([
    'progressMeta' => __(':time remaining', ['time' => $this->model->getRemainingInterval()]),
    'state' => $this->model->state,
])
```

You can store anything in `attributes` — only `progressMeta` has special framework-level rendering. Other keys land in `toArray()` and are accessible from the toast template via `data.your_key`, which is useful if you have a custom toast template or want to drive frontend logic.

## Accept and reject buttons

```php
->accept(NotificationAction::make()
    ->label(__('Download'))
    ->url(route('private-storage', ['path' => $filePath]))
    ->download()
)
->reject(NotificationAction::make()
    ->label(__('Cancel'))
    ->method('cancelExport')
    ->params($this->exportId)
)
```

Both buttons accept any `NotificationAction`. Render rules:

- Accept renders as the primary (filled) button.
- Reject renders as the secondary (outlined) button.
- Setting either forces the toast persistent (the user must click).
- If only one is set, only that button appears.

For the full `NotificationAction` API see [Notification actions](3-notification-actions.md).

## Lifecycle events

```php
->onClose(NotificationEvent::make()->method('handleClose'))
->onDismiss(NotificationEvent::make()->method('handleDismiss'))
->onTimeout(NotificationEvent::make()->method('handleTimeout'))
```

Each takes a `NotificationEvent` (a small DTO that defines a Livewire method to call when the toast closes / is dismissed / times out). Useful for cleaning up server-side state (e.g. unlock a record) or marking the underlying DB notification read in a custom way.

If you don't set any of these, the framework defaults apply (the toast simply disappears).

## Per-channel renderings (`toArray`, `toMail`, `toWebPush`, `toFcm`)

A `ToastNotification` knows how to render itself for every channel:

- `toArray()` — the full payload, including all `attributes` and the `accept`/`reject` toArray. This is what the database channel stores and the broadcast channel sends.
- `toMail()` — a Laravel `MailMessage` with the title as subject, the description as a `line()`, and the `accept` action as the `action()` button if set.
- `toWebPush()` — a `WebPushMessage` (returns `null` if the recipient has no `pushSubscriptions()` method or no subscriptions).
- `toFcm()` / `toFcmData()` — Firebase notification + accompanying data payload (extracts URL/path from the accept action).

The forwarding pattern in your notification class:

```php
public function toArray(object $notifiable): array     { return $this->toToastNotification($notifiable)->toArray(); }
public function toMail(object $notifiable): MailMessage { return $this->toToastNotification($notifiable)->toMail(); }
public function toWebPush(object $notifiable): ?WebPushMessage { return $this->toToastNotification($notifiable)->toWebPush($notifiable); }
```

You only override one of these if a specific channel needs to deviate. For example, if the email needs richer formatting than what `toMail()` produces by default.

## Macroable

`ToastNotification` uses `Macroable`. If your project introduces a recurring pattern (a custom button shape, a shared progress format), add a macro in a service provider:

```php
ToastNotification::macro('downloadButton', function (string $url, string $label = 'Download') {
    return $this->accept(
        NotificationAction::make()->label(__($label))->url($url)->download()
    );
});

// Usage
->downloadButton(route('private-storage', ['path' => $filePath]))
```

Don't reach for macros for one-off cases — but for company-wide patterns they reduce repetition without forcing inheritance.

## Related

- [Notification base](1-notification-base.md) — how to wire a `ToastNotification` into a `Notification` class with the right channels.
- [Notification actions](3-notification-actions.md) — the `NotificationAction` builder used for `accept`/`reject`.
- [Progress and toasts](../6-queue-monitoring/3-progress-and-toasts.md) — render-time behaviour: progress pinning, `progressMeta`, ID dedup.
- [Queue monitoring](../6-queue-monitoring/1-monitored-jobs.md) — the consumer-side example: `accept(NotificationAction)` from inside a job.

[Back to chapter](0-index.md) · [Back to index](../0-index.md)
