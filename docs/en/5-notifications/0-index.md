# Notifications

Flux replaces Laravel's notification class with one that picks sensible default channels per recipient type, and ships a fluent `ToastNotification` builder for the in-app toast UI. The toast layer is what queue-monitor jobs, batch dispatches, and most user-facing async feedback ride on top of.

## What this chapter covers

1. [Notification base](1-notification-base.md) — `FluxErp\Notifications\Notification`, default channels, `via()`, the `HasToastNotification` contract, and how a notification class is structured.
2. [Toast notifications](2-toast-notifications.md) — the `ToastNotification` builder: title, description, type, progress, accept/reject, attributes, persistence, timeouts, `markAsRead()`, `id()`-based deduplication.
3. [Notification actions](3-notification-actions.md) — `NotificationAction` for buttons on toasts: URL/route, download, Livewire method, JavaScript, styling.

## Three things to know up front

- **One Notification class, many channels.** A notification typically implements `toToastNotification()` and lets the framework derive `toArray()`, `toMail()`, and `toWebPush()` from it. You do not have to write four different rendering methods.
- **Toast IDs deduplicate.** Calling `->id('some-stable-key')` lets a later notification _update_ an existing toast instead of stacking a new one. This is how the queue-monitor pipeline keeps the started/processing/finished trio on one row.
- **Two transport paths to the browser.** Database + broadcast for normal notifications; `BroadcastNowChannel` for state that should appear without queue latency. Most queue-monitor toasts use the latter — see [Broadcast now](../7-events-and-broadcasting/0-index.md).

## Where this is used in flux-core

- `JobStartedNotification`, `JobProcessingNotification`, `JobFinishedNotification` — per-job toasts; see [Queue monitoring](../6-queue-monitoring/0-index.md).
- `BatchStartedNotification`, `BatchProcessingNotification`, `BatchFinishedNotification` — batch toasts.
- `ExportReady`, `DocumentsReady`, `WebPushTestNotification`, `FcmTestNotification` — domain notifications using the same base.

## Related

- [Queue monitoring](../6-queue-monitoring/0-index.md) — the largest consumer of `ToastNotification`.
- [Events & Broadcasting](../7-events-and-broadcasting/0-index.md) — the channel that delivers toast updates in real time.

[Back to index](../0-index.md)
