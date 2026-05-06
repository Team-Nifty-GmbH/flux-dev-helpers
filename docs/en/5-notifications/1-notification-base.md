# Notification Base

`FluxErp\Notifications\Notification` (`packages/flux-core/src/Notifications/Notification.php`) is a thin layer on top of `Illuminate\Notifications\Notification`. It exists to:

- pick sensible default channels based on the recipient,
- delegate channel selection to the recipient when the recipient knows better, and
- act as the parent class for every notification in flux-core, so `instanceof FluxErp\Notifications\Notification` is reliable.

## Default channels

```php
public static function defaultChannels(?object $notifiable = null): array
{
    return is_object($notifiable)
        && method_exists($notifiable, 'getMorphClass')
        && $notifiable->getMorphClass() !== morph_alias(User::class)
            ? [BroadcastChannel::class, DatabaseChannel::class, MailChannel::class, FcmChannel::class]
            : [BroadcastChannel::class, DatabaseChannel::class, FcmChannel::class];
}
```

Two cases:

- **Recipient is a `User`** — broadcast (so the in-app toast pops), database (so it lands in the bell menu), FCM (mobile push). _No mail_, because users see notifications in the app and getting an email for every one would be noise.
- **Recipient is anything else** (a Contact, an Address, anything notifiable that isn't a user) — same set, plus `MailChannel`. The assumption is that non-user recipients have no in-app surface, so email is the only reliable way to reach them.

You don't usually call `defaultChannels()` directly. The base `via()` method does it.

## `via()` resolution

```php
public function via(object $notifiable): array
{
    if ($notifiable instanceof AnonymousNotifiable) {
        return array_keys($notifiable->routes);
    }

    return method_exists($notifiable, 'notificationChannels')
        ? $notifiable->notificationChannels($this)
        : static::defaultChannels($notifiable);
}
```

Three branches, in priority order:

1. `AnonymousNotifiable` — Laravel's stand-in for "send a notification to this email/phone/whatever without a User model". The channels are the routes the caller registered.
2. The notifiable defines `notificationChannels($notification)` — the recipient picks. This is how user-level notification preferences kick in: the `User` model's `notificationChannels()` consults the per-user `NotificationSetting` rows and filters out channels the user has disabled.
3. Fallback to `defaultChannels()`.

This means: as a notification author, _do not override `via()`_ unless you have a specific reason. Let the recipient's preferences win.

The exception is when a notification needs to _add_ a channel based on data only it knows. `JobFinishedNotification` does this — it adds `MailChannel` when the queue-monitor row has `notify_on_finish = true` for the recipient:

```php
public function via(object $notifiable): array
{
    $via = [BroadcastNowChannel::class, DatabaseChannel::class];
    if ($this->model->queueMonitorables()
        ->where('queue_monitorable_type', morph_alias($notifiable::class))
        ->where('queue_monitorable_id', $notifiable->id)
        ->where('notify_on_finish', true)
        ->exists()
    ) {
        $via[] = MailChannel::class;
    }
    return $via;
}
```

If you find yourself overriding `via()`, the test is: _does this depend on the notification's payload, or only on the recipient's preferences?_ The former is fine. The latter belongs on the recipient.

## Anatomy of a flux notification class

A typical class looks like this:

```php
namespace FluxErp\Notifications;

use FluxErp\Contracts\HasToastNotification;
use FluxErp\Support\Notification\ToastNotification\NotificationAction;
use FluxErp\Support\Notification\ToastNotification\ToastNotification;
use Illuminate\Notifications\Messages\MailMessage;
use NotificationChannels\WebPush\WebPushMessage;

class ExportReady extends Notification implements HasToastNotification
{
    public function __construct(
        public string $filePath,
        public string $modelAlias,
    ) {}

    public function toArray(object $notifiable): array
    {
        return $this->toToastNotification($notifiable)->toArray();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->toToastNotification($notifiable)->toMail();
    }

    public function toWebPush(object $notifiable): ?WebPushMessage
    {
        return $this->toToastNotification($notifiable)->toWebPush($notifiable);
    }

    public function toToastNotification(object $notifiable): ToastNotification
    {
        return ToastNotification::make()
            ->notifiable($notifiable)
            ->title(__(':model Export Ready', ['model' => __($this->modelAlias)]))
            ->description(__('Your export is ready for download.'))
            ->accept(
                NotificationAction::make()
                    ->label(__('Download'))
                    ->url(route('private-storage', ['path' => $this->filePath]))
                    ->download()
            );
    }
}
```

The pattern: implement `toToastNotification()` once, then forward the channel-specific renderers (`toArray`, `toMail`, `toWebPush`) to it. `ToastNotification` already knows how to convert itself to each format. You typically only override one of those forwarders if you want a channel-specific rendering that diverges from the toast.

## The `HasToastNotification` contract

```php
namespace FluxErp\Contracts;

interface HasToastNotification
{
    public function toToastNotification(object $notifiable): ToastNotification;
}
```

The contract is a marker that says "this notification produces a toast". Channels and consumers can use `instanceof HasToastNotification` to decide whether to call `toToastNotification()` (e.g. the database channel uses the resulting array as the stored payload).

Every queue-monitor notification implements this contract. Every domain notification that should appear in the app should as well.

## Channels you'll meet

| Channel | Purpose | When to choose |
|---|---|---|
| `Illuminate\Notifications\Channels\BroadcastChannel` | Standard Laravel broadcast (queued via the broadcast queue). | Most user-facing in-app notifications. |
| `FluxErp\Support\Notification\BroadcastNowChannel` | Broadcast _immediately_ via `ShouldBroadcastNow`, no queue hop. | Queue-monitor toasts where the user already waited for a job to run; another queue hop would defeat the purpose. |
| `Illuminate\Notifications\Channels\DatabaseChannel` | Stores in `notifications` table. Backs the bell menu. | Anything that should be inspectable later. |
| `Illuminate\Notifications\Channels\MailChannel` | Email. | When the recipient has no in-app surface, or when they explicitly opted in. |
| `FluxErp\Notifications\Channels\FcmChannel` | Firebase Cloud Messaging — mobile push. | User notifications that should reach the phone. |
| `NotificationChannels\WebPush\WebPushChannel` | Browser web-push. | Same role as FCM for desktop browsers; opt-in via `pushSubscriptions`. |

For deeper detail on the broadcast paths see [Events & Broadcasting](../7-events-and-broadcasting/0-index.md).

## Database channel: the `Notification` model

The database channel writes rows into the `notifications` table. Flux's `FluxErp\Models\Notification` extends Laravel's `DatabaseNotification` and:

- Adds 30-day pruning of read notifications (`MassPrunable` + `prunable()`).
- Provides `toast(?Component $component): Toast` — converts a stored DB notification back into a renderable `Toast`, used by the bell-menu list to re-render past notifications. This rendering recreates the progress bar (with the `window._toastProgress` pinning trick described in [Progress and toasts](../6-queue-monitoring/3-progress-and-toasts.md)), the accept/reject buttons, the meta line, and the timestamp.

You generally do not interact with `FluxErp\Models\Notification` directly — `ToastNotification::toArray()` produces the array that ends up in the `data` column, and `Notification::toast()` is what reads it back.

## Related

- [Toast notifications](2-toast-notifications.md) — the builder that `toToastNotification()` returns.
- [Notification actions](3-notification-actions.md) — `NotificationAction` for accept/reject buttons.
- [Events & Broadcasting](../7-events-and-broadcasting/0-index.md) — channel transport details.
- [Queue monitoring](../6-queue-monitoring/0-index.md) — the largest set of concrete `Notification` implementations.

[Back to chapter](0-index.md) · [Back to index](../0-index.md)
