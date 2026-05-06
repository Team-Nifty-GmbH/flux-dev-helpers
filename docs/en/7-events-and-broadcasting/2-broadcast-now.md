# Broadcast Now

`FluxErp\Support\Notification\BroadcastNowChannel` is a notification channel that broadcasts immediately, bypassing the broadcast queue.

## Why it exists

Laravel's standard `BroadcastChannel` queues the broadcast event onto the broadcast connection. For most notifications that's correct: a non-time-sensitive update can wait its turn. But the queue-monitor pipeline reports progress _from inside a queue worker_:

```
queue worker tick
└─ job calls $this->queueProgress(42)
   └─ QueueMonitor row updated
      └─ JobProcessingNotification dispatched
         └─ standard BroadcastChannel: enqueues a broadcast event
            └─ broadcast worker picks it up later
               └─ user finally sees the toast update
```

The user sees nothing for as long as the broadcast queue is backlogged — exactly the problem the live progress bar is supposed to solve.

`BroadcastNowChannel` short-circuits the second hop:

```
queue worker tick
└─ job calls $this->queueProgress(42)
   └─ QueueMonitor row updated
      └─ JobProcessingNotification dispatched (via BroadcastNowChannel)
         └─ broadcast event implements ShouldBroadcastNow
            └─ broadcaster pushes to subscribers in this tick
               └─ user sees toast update immediately
```

## How it works

```php
// FluxErp\Support\Notification\BroadcastNowChannel
class BroadcastNowChannel extends BroadcastChannel
{
    public function send($notifiable, Notification $notification): ?array
    {
        $message = $this->getData($notifiable, $notification);

        $event = new BroadcastNowNotificationCreated(
            $notifiable, $notification, is_array($message) ? $message : $message->data
        );

        if ($message instanceof BroadcastMessage) {
            $event->onConnection($message->connection)
                ->onQueue($message->queue);
        }

        return $this->events->dispatch($event);
    }
}
```

It overrides Laravel's `BroadcastChannel::send()` to dispatch `BroadcastNowNotificationCreated` instead of the queued `BroadcastNotificationCreated`:

```php
// FluxErp\Events\BroadcastNowNotificationCreated
class BroadcastNowNotificationCreated extends BroadcastNotificationCreated
    implements ShouldBroadcastNow {}
```

`ShouldBroadcastNow` is the standard Laravel marker for "skip the queue". The event class doesn't override anything else — the channel name and payload are inherited from Laravel's standard notification broadcast.

## Using it from a notification

Return it in `via()`:

```php
class JobProcessingNotification extends Notification implements HasToastNotification
{
    public function via(object $notifiable): array
    {
        return [BroadcastNowChannel::class, DatabaseChannel::class];
    }
    // ...
}
```

That's it. The notification renders normally; only the broadcast channel is swapped.

## When to use it

Use `BroadcastNowChannel` when:

- The notification fires from inside a queue worker, _and_
- The user is actively waiting for the update (a progress bar, a "your job is done" toast, a real-time chat message).

Don't use it for:

- Notifications fired from a synchronous web request — there's no second queue hop to skip; a regular `BroadcastChannel` suffices and avoids tying up the dispatching worker on the broadcast call.
- Notifications that aren't time-sensitive — "your weekly report is ready" can wait its turn.
- High-volume background notifications — the broadcast queue exists to absorb load. Bypassing it removes that buffer; if a hundred toasts fire at once, they all hit the broadcaster synchronously.

The rule of thumb: only use it when the latency of the broadcast queue would visibly hurt the user experience.

## Channel naming, the same as regular broadcasts

`BroadcastNowChannel` extends `BroadcastChannel` and inherits its channel resolution. The notification still goes to `private-{morphAlias}.{key}` for the recipient (same as the user channel described in [Broadcast channels](1-broadcast-channels.md)). The frontend listens on the same channel; only the dispatching path is different.

## Combining channels

Most queue-monitor notifications use both:

```php
public function via(object $notifiable): array
{
    return [BroadcastNowChannel::class, DatabaseChannel::class];
}
```

The broadcast lands immediately as a toast. The database channel records it for the bell menu. If the user is looking at the screen, they see the toast. If they aren't, the notification is still in their bell menu when they come back — either through the same toast that survived, or through the database row.

## Caveats

- The dispatching worker tick is held during the broadcast call. If your broadcaster is slow (Pusher rate-limited, Reverb under load), the worker waits.
- Errors in the broadcaster propagate up as exceptions in the worker. A regular `BroadcastChannel` only fails the broadcast worker, isolating the original job.
- There is no automatic fallback. If the broadcaster is down, the toast doesn't appear and the user sees only the eventual database notification.

For most use cases these caveats are acceptable. If they aren't — for example, a job that fires hundreds of progress notifications per second on a flaky broadcaster — fall back to `BroadcastChannel` and accept the latency.

## Related

- [Broadcast channels](1-broadcast-channels.md) — naming, auto-registration, the regular `BroadcastChannel`.
- [Notifications](../5-notifications/0-index.md) — the notification base classes that produce these broadcasts.
- [Queue monitoring](../6-queue-monitoring/0-index.md) — the canonical consumer of `BroadcastNowChannel`.

[Back to chapter](0-index.md) · [Back to index](../0-index.md)
