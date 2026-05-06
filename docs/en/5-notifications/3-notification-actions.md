# Notification Actions

`FluxErp\Support\Notification\ToastNotification\NotificationAction` defines a button on a toast. It's used in two places:

- `ToastNotification::accept(...)` and `ToastNotification::reject(...)` — buttons rendered next to the toast description.
- `IsMonitored::accept(...)` and `IsMonitored::reject(...)` — the same builder, stored on the queue-monitor row and unserialized when the finished toast renders.

It's a small `Arrayable` DTO with a fluent setter for each property. Nothing magic, but the conventions matter.

## Building an action

```php
use FluxErp\Support\Notification\ToastNotification\NotificationAction;

NotificationAction::make()
    ->label(__('Download'))
    ->url(route('private-storage', ['path' => $filePath]))
    ->download();
```

Or, with the array shorthand on `make()`:

```php
NotificationAction::make([
    'label' => __('Download'),
    'url' => route('private-storage', ['path' => $filePath]),
    'download' => true,
]);
```

(Each array key must match a method name on the class — `make()` walks the array and calls `$instance->{$key}($value)`.)

## What the button can do

A `NotificationAction` can be one of several things, set by which method you call:

| Behaviour | Method(s) | Result |
|---|---|---|
| Navigate to a URL | `url(string)` or `route(string $name, array $params = [])` | Click → `window.location` change. |
| Download a file | `url(...)` + `download(true)` | Click → file download via the URL. |
| Call a Livewire method | `method(string) (+ params(mixed))` | Click → `$wire.call(method, params)` on the host component. |
| Run JavaScript | `execute(string)` | Click → eval the JS expression. |
| Plain link with custom appearance | `url(...)` + `style(...)` + `solid(...)` | Click → navigation, but rendered with a chosen style class. |

You typically pick _one_ behaviour per button. If you set multiple, the rendering precedence is: `execute` > `method` > `url`.

## Field reference

| Setter | Type | Notes |
|---|---|---|
| `label(string)` | required | Button text. Wrap in `__()` for translation. |
| `url(?string)` | URL or route-derived URL | Use `route()` directly or `->route(name, params)`. |
| `route(string, array)` | shorthand | Same as `->url(route($name, $params))`. |
| `download(bool = true)` | only meaningful with `url` | Triggers a download instead of navigation. |
| `method(string)` | Livewire method name | Sent to `$wire.call()` on the toast host component. |
| `params(mixed)` | passed to `method` | Anything serializable. Often the model key. |
| `execute(string)` | JS expression | Evaluated on click. Use sparingly. |
| `style(string)` | CSS class hint | Passed to the button's `color`/style prop. |
| `solid(?bool)` | `true` = filled, `false` = outlined | Defaults to the framework's per-slot default if `null`. |

`label` is required (the constructor seeds it to `''`); everything else is nullable. `toArray()` filters out null/empty values, so the rendered payload only carries what you set.

## Three common shapes

### A download

```php
NotificationAction::make()
    ->label(__('Download'))
    ->url(route('private-storage', ['path' => $filePath]))
    ->download();
```

The canonical use on a finished export job. The route serves a private-storage file; `download(true)` makes the browser save it instead of opening it.

### A "view this thing" link

```php
NotificationAction::make()
    ->label(__('View order'))
    ->route('orders.show', ['order' => $order->getKey()]);
```

Use `->route()` rather than building the URL manually — it survives URL prefix changes (e.g. localized routes).

### A confirm/reject pair on a question toast

```php
ToastNotification::make()
    ->type(ToastType::QUESTION)
    ->title(__('Approve order?'))
    ->description(__('Order :number is awaiting approval.', ['number' => $order->number]))
    ->accept(
        NotificationAction::make()
            ->label(__('Approve'))
            ->method('approveOrder')
            ->params($order->getKey())
    )
    ->reject(
        NotificationAction::make()
            ->label(__('Reject'))
            ->method('rejectOrder')
            ->params($order->getKey())
    );
```

`method` calls a Livewire method on the host of the toast. The host depends on context — for top-level app toasts, it's the `Notifications` Livewire feature component (`packages/flux-core/src/Livewire/Features/Notifications.php`).

## Serialization gotcha (queue-monitor)

When you call `IsMonitored::accept($action)` inside a job, the action is stored on the `queue_monitors` row via `serialize()`:

```php
// FluxErp\Traits\IsMonitored::accept()
$monitor->update(['accept' => serialize($action)]);
```

This means everything inside the action must be serializable: strings, ints, bools, arrays of those. Concretely:

- ✅ `->url(route('foo', ['id' => 1]))` — the resolved string is what's stored.
- ✅ `->method('handleClick')->params($id)` — primitive params are fine.
- ❌ `->params($model)` where `$model` is a live Eloquent instance — works in tests but bloats the row and risks deserialization failures across deploys. Pass the key instead and re-fetch in the handler.
- ❌ Closures — can't be serialized through the standard PHP serializer. If you need a closure, use `Laravel\SerializableClosure\SerializableClosure`, but consider whether you actually need it (a `method()` call is usually clearer).

For toast-only actions (used in a `ToastNotification` that goes via the database channel), the action is also serialized into the notification row's `data` JSON via `toArray()`. The same constraints apply: keep it primitive.

## How buttons reach `FluxErp\Models\Notification::toast()`

The render side, for completeness:

```php
// FluxErp\Models\Notification::toast()
if (data_get($this->data, 'accept')) {
    $toast->confirm(
        data_get($this->data, 'accept.label', __('Accept')),
        data_get($this->data, 'accept.method', 'acceptNotify'),
        data_get($this->data, 'accept.params', $this->getKey())
    );
}

if (data_get($this->data, 'reject')) {
    $toast->cancel(
        data_get($this->data, 'reject.label', __('Reject')),
        data_get($this->data, 'reject.method', 'rejectNotify'),
        data_get($this->data, 'reject.params', $this->getKey())
    );
}
```

Two things to notice:

- The default `method` is `acceptNotify` / `rejectNotify`, and the default `params` is the notification's own key. If you don't set `method` explicitly, the toast wires up a generic accept/reject handler defined on the host Livewire component.
- The framework consumes `accept.label`, `accept.method`, `accept.params` directly from the data array — these are exactly the fields `NotificationAction::toArray()` produces. The DB channel and the broadcast channel both deliver the same shape.

You don't usually need to know this. It only matters if you're customising the toast template or implementing a custom host component.

## Related

- [Toast notifications](2-toast-notifications.md) — `accept()`/`reject()` on the toast builder.
- [Monitored jobs](../6-queue-monitoring/1-monitored-jobs.md) — `IsMonitored::accept()` / `reject()` from inside a job.
- [Recipes](../6-queue-monitoring/4-recipes.md) — `ExportDataTableJob` attaches a download button at the end of `handle()`.

[Back to chapter](0-index.md) · [Back to index](../0-index.md)
