# Widgets

Widgets are small Livewire components rendered on dashboards. They're "small" in two senses:

- **Visually small** — they fit within a card on a dashboard grid.
- **Constrained API** — they must follow specific rules so the dashboard layout can place them anywhere without surprises.

For your own packages: a widget is the right tool for "I want to surface this metric / list / status on the dashboard". It's the wrong tool for full-page UIs.

## Two hard rules

These are the rules that catch every widget author once. Internalise them up front:

### 1. Widgets must NOT expect parameters in `mount()`

The dashboard renders widgets generically. It doesn't know what parameters your widget might want; it just does `<livewire:your-widget />`. If `mount()` requires arguments, the dashboard render breaks.

```php
// WRONG
public function mount(int $tenantId): void { ... }

// RIGHT
public function mount(): void
{
    $tenantId = Context::get('tenant_id') ?? auth()->user()?->tenant_id;
    // ...
}
```

If the widget needs context, derive it from the auth user, the request context, or stored widget configuration — not from a constructor argument.

### 2. The widget view must NOT contain `<x-card>` (or any outer wrapper)

The dashboard wraps every widget in a `<x-card>` automatically. If your view has its own outer card, you get a card-in-a-card.

```blade
{{-- WRONG --}}
<x-card>
    <h3>{{ __('Open orders') }}</h3>
    {{ $count }}
</x-card>

{{-- RIGHT --}}
<div class="space-y-2">
    <h3 class="text-lg font-semibold">{{ __('Open orders') }}</h3>
    <p class="text-2xl">{{ $count }}</p>
</div>
```

Render the inner content directly. The dashboard provides the surface.

## Defining a widget

```php
namespace App\Widgets;

use FluxErp\Models\Order;
use FluxErp\Traits\Livewire\Widgetable;
use Livewire\Component;

class OpenOrdersWidget extends Component
{
    use Widgetable;

    public int $count = 0;

    public function mount(): void
    {
        $this->count = Order::query()
            ->where('state', 'open')
            ->count();
    }

    public function render()
    {
        return view('livewire.widgets.open-orders');
    }
}
```

The `Widgetable` trait registers the component as available on the dashboard.

## Time-frame-aware widgets

For widgets that respond to a global time-range selector ("show me this metric for the last 7/30/90 days"):

```php
use FluxErp\Traits\Livewire\IsTimeFrameAwareWidget;

class RevenueWidget extends Component
{
    use Widgetable, IsTimeFrameAwareWidget;

    public float $revenue = 0;

    public function calculate(): void
    {
        $this->revenue = Order::query()
            ->whereBetween('order_date', [$this->getTimeFrameStart(), $this->getTimeFrameEnd()])
            ->sum('total_gross_price');
    }
}
```

The `IsTimeFrameAwareWidget` trait:

- Adds time-frame state (start / end / preset).
- Listens to dashboard-level time-frame change events.
- Calls `calculate()` when the time frame changes.

`getTimeFrameStart()` and `getTimeFrameEnd()` return Carbon instances based on the current preset.

## Widget configuration

Some widgets need per-user configuration (e.g. "which orders to count: all, mine, my team's"). Use `SupportsWidgetConfig`:

```php
use FluxErp\Traits\Livewire\SupportsWidgetConfig;

class OpenOrdersWidget extends Component
{
    use Widgetable, SupportsWidgetConfig;

    public string $scope = 'all';      // 'all' | 'mine' | 'team'

    public function mount(): void
    {
        $this->loadConfig();          // populates $scope from saved config
        $this->refresh();
    }

    public function getConfigFields(): array
    {
        return [
            'scope' => [
                'type' => 'select',
                'options' => [
                    'all' => __('All orders'),
                    'mine' => __('My orders'),
                    'team' => __('Team orders'),
                ],
            ],
        ];
    }
}
```

The trait:

- Stores configuration per user, per widget instance.
- Renders a configuration UI (dropdown of fields) accessible from the widget.
- Persists changes and reloads the widget.

## Charts

Charts use Apex Charts (via the `apex-charts` JS component). The trait wires the data:

```php
class OrderTrendWidget extends Component
{
    use Widgetable, IsTimeFrameAwareWidget;

    public array $series = [];
    public array $categories = [];

    public function calculate(): void
    {
        $data = Order::query()
            ->whereBetween('order_date', [$this->getTimeFrameStart(), $this->getTimeFrameEnd()])
            ->selectRaw('DATE(order_date) as day, COUNT(*) as count')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $this->categories = $data->pluck('day')->all();
        $this->series = [['name' => __('Orders'), 'data' => $data->pluck('count')->all()]];
    }
}
```

```blade
<div>
    <h3 class="text-lg font-semibold">{{ __('Order trend') }}</h3>
    <div
        x-data="{ chart: null }"
        x-init="chart = new ApexCharts($el, {
            chart: { type: 'line', height: 250 },
            series: @json($series),
            xaxis: { categories: @json($categories) },
        }); chart.render();"
        x-on:livewire:updated.window="chart.updateOptions({
            series: @json($series),
            xaxis: { categories: @json($categories) },
        })"
    ></div>
</div>
```

Rebuild the chart on Livewire updates so the data stays current.

## Registering widgets

The widget is auto-discovered if it lives in a recognised path (a service-provider scan walks `Livewire\Widgets/` directories of every loaded package). For tenant or one-off widgets, register explicitly:

```php
// AppServiceProvider::boot()
WidgetManager::register([
    'open-orders' => \App\Widgets\OpenOrdersWidget::class,
    'revenue' => \App\Widgets\RevenueWidget::class,
]);
```

Once registered, users can add the widget to their dashboard via the dashboard configuration UI.

## Widget visibility (per-role)

Widgets can be gated by role. Set the `$roles` property:

```php
class TenantStatsWidget extends Component
{
    use Widgetable;

    public array $roles = ['Super Admin', 'Tenant Admin'];
}
```

Users not matching any of the roles don't see the widget in the picker.

## Refresh patterns

Widgets are static after `mount()` unless something explicitly refreshes them. Three options:

- **On dashboard event** — Listen for `dashboard:refresh` (dispatched by the dashboard's "refresh" button).
  ```php
  protected $listeners = ['dashboard:refresh' => 'refresh'];
  ```
- **On model broadcast** — Subscribe to the relevant model channel; re-fetch when the model changes.
  ```php
  protected $listeners = ['echo-private:order.,OrderUpdated' => 'refresh'];
  ```
- **Manual** — A button on the widget calls `refresh()` directly.

For high-frequency widgets, throttle or use a scheduled refresh rather than per-event re-renders.

## Don't reach for `BaseDataTable` inside a widget

Putting a full data table inside a widget is almost always wrong:

- A data table is wide; widgets are narrow.
- A data table has row actions; widgets typically don't.
- A data table paginates; widgets show a fixed snapshot.

If you want "the latest 5 orders" on the dashboard, write a small list widget. Keep `BaseDataTable` for full-page list views.

## Related

- [Livewire conventions](0-index.md) — overall component shape.
- [Widget customisation](../10-extending-the-frontend.md) — registering widgets from your own packages.
- [TallStackUI conventions](4-tallstackui-conventions.md) — components for widget views.

[Back to chapter](0-index.md) · [Back to index](../0-index.md)
