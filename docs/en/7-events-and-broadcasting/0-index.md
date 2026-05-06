# Events & Broadcasting

Flux extends Laravel's broadcasting in three places:

- **Channel naming** — every model that uses `Illuminate\Database\Eloquent\BroadcastsEvents` gets a private channel auto-registered, named via `class_to_broadcast_channel()`. The user, the order, the ticket — all listenable on a stable, conventional channel name without you wiring anything up.
- **`BroadcastNowChannel`** — a notification channel that broadcasts via `ShouldBroadcastNow`, bypassing the broadcast queue. Used by queue-monitor toasts so progress updates don't sit in a queue waiting to be pushed.
- **`BroadcastableActionEventOccurred`** — actions that opt into `BroadcastsActionEvents` automatically broadcast their lifecycle events on a deterministic channel.

## What this chapter covers

1. [Broadcast channels](1-broadcast-channels.md) — naming convention (`class_to_broadcast_channel()`), the auto-registered model channels, presence channels, action event channels, batch authentication.
2. [Broadcast now](2-broadcast-now.md) — `BroadcastNowChannel`, `BroadcastNowNotificationCreated`, when to use it instead of the regular broadcast channel.

## Why this matters

Toasts that take seconds to appear feel broken. The queue-monitor pipeline reports progress from inside a queue worker; if the resulting notification rides the regular broadcast channel, it goes _back_ onto a queue and the user waits twice. `BroadcastNowChannel` fixes that — the broadcast is dispatched immediately on the same worker tick.

The model channels matter for live-update UIs: an `Order` detail page subscribes to `private-order.{order}` and re-renders whenever the order changes anywhere in the system, without polling. The convention is uniform — every broadcasting model gets the same shape — which keeps subscription code in Livewire components small and predictable.

## Related

- [Notifications](../5-notifications/0-index.md) — the `Notification` and `ToastNotification` API that rides on top of these channels.
- [Queue monitoring](../6-queue-monitoring/0-index.md) — the largest consumer of `BroadcastNowChannel`.

[Back to index](../0-index.md)
