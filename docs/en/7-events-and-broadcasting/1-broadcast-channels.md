# Broadcast Channels

The conventions, the auto-registration, and the channels you can subscribe to.

## Channel naming: `class_to_broadcast_channel()`

```php
// packages/flux-core/helpers.php
function class_to_broadcast_channel(string $class, bool $withParam = true): string
{
    return Str::of($morphAlias = morph_alias($class))
        ->replace('\\', '.')
        ->lower()
        ->toString()
        . ($withParam ? '.{' . Str::camel(...) . '}' : '');
}
```

The rule:

- Take the morph alias of the class (`order`, `user`, `ticket`, `mail-account`, …).
- Lowercase it; replace `\` with `.`.
- Append `.{key}` (camel-cased model name in braces) if `$withParam = true`.

Examples:

| Class | Morph alias | Channel (with param) | Channel (no param) |
|---|---|---|---|
| `FluxErp\Models\User` | `user` | `user.{user}` | `user` |
| `FluxErp\Models\Order` | `order` | `order.{order}` | `order` |
| `FluxErp\Models\MailAccount` | `mail-account` | `mail-account.{mailAccount}` | `mail-account` |

Wrap with `private-` for private channels (`private-order.{order}`) or `presence-` for presence channels — Laravel's standard.

The same helper is used by `BroadcastsActionEvents`, prefixed with `action.`:

```php
// packages/flux-core/src/Traits/Action/BroadcastsActionEvents.php
public static function getBroadcastChannel(): string
{
    return 'action.' . class_to_broadcast_channel(static::class, false);
}
```

So the channel for `FluxErp\Actions\Order\CreateOrder` becomes `action.order.create-order` (no param).

Always derive channel names with this helper rather than hard-coding strings. Morph aliases can change, package authors can rename, and the helper guarantees you stay in sync.

## Auto-registered model channels

`packages/flux-core/routes/channels.php` walks `Relation::morphMap()` and registers a channel for every class that uses `Illuminate\Database\Eloquent\BroadcastsEvents`:

```php
foreach (Relation::morphMap() as $class) {
    $class = resolve_static($class, 'class');
    if (! in_array(BroadcastsEvents::class, class_uses_recursive($class))) {
        continue;
    }

    $channel = class_to_broadcast_channel($class);

    Broadcast::channel(
        $channel,
        function (Authenticatable $user, int|string $key) use ($class) {
            auth()->setUser($user);

            return $class::query()->where(app($class)->getRouteKeyName(), $key)->exists();
        },
        ['guards' => ['web', 'address']]
    );

    // bare channel for collection-level broadcasts
    Broadcast::channel(
        class_to_broadcast_channel($class, false) . '.',
        fn () => true,
        ['guards' => ['web', 'address']]
    );
}
```

Two channels per broadcasting model:

- `private-{morphAlias}.{key}` — per-record. Authorisation: the user must be authenticated, and the record must exist. Used for "watch this specific order".
- `private-{morphAlias}.` — collection-level. Anything authenticated can listen. Used for "watch all orders".

Both are authorised across the `web` and `address` guards (the latter is the customer portal guard — see [Customer portal](../10-extending-the-frontend.md) once written).

Subscribing from JavaScript:

```js
Echo.private(`order.${orderId}`).listen('.OrderUpdated', (e) => { /* ... */ });
```

The event names follow Laravel's convention for `BroadcastsEvents`: `OrderCreated`, `OrderUpdated`, `OrderDeleted`, `OrderTrashed`, `OrderRestored`. The leading dot tells Echo to treat the event name as fully qualified instead of prepending Laravel's namespace.

## The user channel (special case)

```php
Broadcast::channel(
    class_to_broadcast_channel(morphed_model('user')),
    fn ($user, $id) => (int) $user->id === (int) $id
);
```

The user channel uses an identity check rather than the existence check the morph-map loop produces. A user can only listen to _their own_ user channel.

This is the channel that delivers per-user notifications. When a notification fires with `BroadcastChannel`/`BroadcastNowChannel`, Laravel sends it to the user's private notification channel — under the hood, the same naming.

## The action channel

```php
Broadcast::channel('action.*', fn () => true);
```

A wildcard channel for action-event broadcasts. Authorisation is permissive — anyone authenticated can listen to any action channel.

Use case: a Livewire component that wants to react to "an order was created" doesn't need to know _which_ order; it can listen to `action.order.create-order` and refresh its list.

If you need authorisation tighter than "any authenticated user", override the channel registration in your own service provider with a more specific callback.

## The batch channel

```php
Broadcast::channel('job-batch.{id}', fn () => true);
```

Listen to a specific batch's progress events. The batch ID is the same UUID as `Bus::batch()` returns. Used by tooling that wants live progress on a specific batch (the queue-monitor itself uses notifications, not direct channel subscription, but the channel is available).

## Presence channels

```php
Broadcast::channel('presence', function (User $user) {
    return ['id' => $user->getKey()];
});
```

A general "who is online" presence channel returning the user's id. Useful for collaborative-editing surfaces and "X is also viewing this page" indicators.

## Batch authentication endpoint

```php
Route::post('/broadcasting/auth/batch', BroadcastingBatchAuthController::class)
    ->middleware(['web', 'auth:web'])
    ->name('broadcasting.auth.batch');
```

A batched-auth endpoint for clients that need to subscribe to many channels in one request. Reduces auth request volume on busy pages (e.g. a list view subscribing to one channel per row). The controller checks each requested channel against the standard `Broadcast::auth()` flow and returns a map of allowed/denied results.

Configure your client (Echo / Pusher) to use this endpoint when you need batch authentication; the standard `/broadcasting/auth` endpoint remains available for individual subscriptions.

## Action event broadcasting (`BroadcastsActionEvents`)

Adding the `BroadcastsActionEvents` trait (`packages/flux-core/src/Traits/Action/BroadcastsActionEvents.php`) makes a `FluxAction` broadcast its `executed` event automatically:

```php
class CreateOrder extends FluxAction
{
    use BroadcastsActionEvents;
    // ...
}
```

After `CreateOrder::execute()` finishes, a `BroadcastableActionEventOccurred` is dispatched on `private-action.order.create-order`. Subscribers see the event name `OrderExecuted` (the trait's default `broadcastAs()`).

The trait exposes hooks for customisation:

- `broadcastAs(string $event): string` — override the event name.
- `broadcastOn(string $event): array|Channel` — return additional channels.
- `broadcastWith(string $event): array` — control the payload (defaults to a serialisable form of the action's `result`).
- `broadcastConnection()`, `broadcastQueue()` — pin the broadcast to a specific queue/connection.

The payload is sanitised in the event constructor (`BroadcastableActionEventOccurred::__construct`) — non-serializable `result` / `data` / `rules` get nulled out so the broadcast itself doesn't fail.

Globally suppress action broadcasting (e.g. in tests) via `BroadcastsActionEvents::$isBroadcasting = false`.

## Related

- [Broadcast now](2-broadcast-now.md) — when the regular broadcast queue is too slow.
- [Models](../2-models/0-index.md) — `BroadcastsEvents` is what causes the per-model channels to be auto-registered.
- [Actions](../3-actions/0-index.md) — `BroadcastsActionEvents` for action-driven live updates.

[Back to chapter](0-index.md) · [Back to index](../0-index.md)
