# Data Tables

Flux's data tables build on `team-nifty-gmbh/tall-datatables`. Every list view in the app is a `BaseDataTable` subclass.

## The base class

```php
abstract class BaseDataTable extends DataTable
{
    use Actions, HasEloquentListeners;

    #[Renderless]
    public function export(array $columns = [], string $format = 'xlsx', bool $formatted = true): Response|BinaryFileResponse|StreamedResponse
    {
        $columns = array_filter($columns) ?: $this->enabledCols;

        ExportDataTableJob::dispatch(
            serialize($this),
            $this->getModel(),
            $columns,
            auth()->user()->getMorphClass() . ':' . auth()->id(),
            $format,
            $formatted,
        );

        return response()->noContent();
    }

    protected function getModel(): string { /* ... */ }
    protected function getDisplayTimezone(): string { /* ... */ }
    protected function canSaveDefaultColumns(): bool { /* Super Admin only */ }
    protected function canShareFilters(): bool { /* checks ShareFilter action permission */ }
}
```

What you get:

- **Search, filter, sort, pagination** from `tall-datatables`.
- **Monitored export** — `export()` dispatches `ExportDataTableJob`, which streams progress and provides a download link via the queue monitor (see [Recipes](../6-queue-monitoring/4-recipes.md)).
- **Live updates** — `HasEloquentListeners` re-renders the table when relevant model events fire.
- **Row actions** — the `Actions` trait dispatches actions from row-level buttons.

## Minimal data table

```php
namespace FluxErp\Livewire\DataTables;

use FluxErp\Models\Voucher;

class VoucherList extends BaseDataTable
{
    protected string $model = Voucher::class;

    public array $enabledCols = ['code', 'amount', 'expires_at'];
    public array $sortable = ['code', 'amount', 'expires_at'];
    public array $availableCols = [
        'code', 'amount', 'expires_at', 'redeemed_at', 'created_at',
    ];
}
```

That's a working, exportable, filterable, sortable list of vouchers.

## Conventions

- **`$model`** — FQN of the model. The base resolves it through the container (`resolve_static($model, 'class')`), so binding overrides apply.
- **`$enabledCols`** — columns shown by default. Users can re-arrange via the column config UI.
- **`$availableCols`** — columns selectable. Subset of the model's queryable fields.
- **`$sortable`** — which columns are sortable.
- **`$search`** — fields searched by the global search box. Defaults to model-level if `Searchable` trait is present.

The full surface is on the `tall-datatables` `DataTable` base — refer to its README for column formatters, filter widgets, and badge rendering. The Flux additions sit on top.

## Filters

Two types of filter on a data table:

- **Static filters** declared on the data-table class (visible UI controls).
- **Saved filters** (per-user or shared) — saved server-side, applied when selected.

Saved filters can be shared across users when the user has the `ShareFilter` action permission. `canShareFilters()` reflects this; the UI hides the "share" button when it returns false.

To declare a static filter:

```php
public function getFilters(): array
{
    return [
        Filter::make('expires_within')
            ->label(__('Expires within'))
            ->options([
                '7'  => __('7 days'),
                '30' => __('30 days'),
                '90' => __('90 days'),
            ])
            ->callback(fn (Builder $q, $value) => $q->whereBetween('expires_at', [now(), now()->addDays($value)])),
    ];
}
```

The `tall-datatables` `Filter` class is what you instantiate. Apply via callback or via column-driven equality matchers — see the library's docs.

## Live updates with `HasEloquentListeners`

```php
use TeamNiftyGmbH\DataTable\Traits\HasEloquentListeners;

class VoucherList extends BaseDataTable
{
    use HasEloquentListeners;
}
```

The trait subscribes to the model's broadcast channel (`private-voucher.{voucher}` and `private-voucher.`) and re-fetches the affected row when the model fires `created`/`updated`/`deleted`/`restored` broadcasts.

You don't have to do anything beyond `use HasEloquentListeners` — the channel is auto-registered (see [Broadcast channels](../7-events-and-broadcasting/1-broadcast-channels.md)) and the trait listens to all of `created`, `updated`, `deleted`, `restored`, `trashed`.

For high-frequency updates (e.g. rapidly-changing queue monitor rows), consider whether the live-updates UX is helpful. You can scope which events the table listens to.

## Monitored export

`export()` is `#[Renderless]` (returns the action without re-rendering the component) and dispatches `ExportDataTableJob`. The job:

1. Records the export start in `queue_monitors` with friendly title `:model export`.
2. Streams chunked progress as it writes (`queueProgress(int)` — see [Monitored jobs](../6-queue-monitoring/1-monitored-jobs.md)).
3. Stores the resulting file in private storage.
4. Attaches a `NotificationAction::download(...)` on completion — the user's finished toast has a "Download" button.

You don't write any of this. Subclassing `BaseDataTable` is enough.

The user export experience: click "Export", get a "started" toast, watch it fill up, click "Download" when finished. No second browser tab, no manual refresh.

## Row actions

```php
public function getRowActions(): array
{
    return [
        DataTableButton::make()
            ->label(__('Redeem'))
            ->color('primary')
            ->icon('check')
            ->wireClick('redeem(record.id)'),

        DataTableButton::make()
            ->label(__('Delete'))
            ->color('danger')
            ->icon('trash')
            ->wireClick('delete(record.id)')
            ->wireFluxConfirm('error', __('wire:confirm.delete', ['model' => __('Voucher')])),
    ];
}

public function redeem(int $id): void
{
    RedeemVoucher::make(['id' => $id])
        ->checkPermission()
        ->validate()
        ->execute();
}

public function delete(int $id): void
{
    DeleteVoucher::make(['id' => $id])
        ->checkPermission()
        ->validate()
        ->execute();
}
```

Three things to note:

- `DataTableButton::make()->wireClick('redeem(record.id)')` — the `record.id` is an Alpine reference to the row's record in the table's `x-for` loop.
- `wireFluxConfirm('error', ...)` adds the standard confirmation dialog before dispatching.
- The methods on the data table are plain Livewire methods. Action dispatch happens here.

## `DataTableHasFormEdit`

For tables where rows open an edit modal:

```php
use FluxErp\Traits\Livewire\DataTableHasFormEdit;
use FluxErp\Livewire\Forms\VoucherForm;

class VoucherList extends BaseDataTable
{
    use DataTableHasFormEdit;

    public VoucherForm $voucherForm;
    // ...
}
```

The trait wires up:

- A row click handler that loads the record into the form and opens the modal.
- The modal infrastructure (slot for the modal blade template).
- Save/cancel/delete handlers.

The pattern is "list + inline edit": no separate detail page, just a modal opened from the row.

## Sharing data tables across views

A data table is a Livewire component. Use it on a page:

```blade
<livewire:voucher-list />
```

Or render it programmatically with parameters:

```blade
<livewire:voucher-list :tenant-id="$tenantId" />
```

`mount()` accepts the parameters as usual; `$enabledCols` etc. can be conditional on input.

## When the user has no permission

`BaseDataTable` doesn't enforce permission checks itself — that's the route's job (the `Controller`'s auto-attached middleware blocks access at the HTTP layer). For row-action permissions, use the action's `canPerformAction()` to gate the row-action button.

## Customising the column type

The `tall-datatables` library has the standard column types (text, number, currency, date, badge). For custom rendering — e.g. a state column showing a colored badge — the column needs a frontend formatter. State classes that implement `HasFrontendFormatter` ([States](../2-models/3-states.md)) wire this up automatically.

For one-off custom formatters, pass a closure to the column definition. See `tall-datatables` docs.

## Performance: paging, scopes, indexes

For large tables (more than ~100K rows):

- Make sure the columns used by `$enabledCols` are indexed.
- Use Scout / Meilisearch / Algolia for the global search if SQL `LIKE` performance is a concern. Models with the `Searchable` trait wire this up.
- Be mindful of `with()` eager loading — a per-row N+1 in a 1000-row paginated view is 1000 queries the user waits for.
- Live updates via `HasEloquentListeners` re-fetch the changed row; for very high-frequency updates, throttle or scope the listener.

## Related

- [Form objects](1-form-objects.md) — for `DataTableHasFormEdit` modals.
- [Recipes](../6-queue-monitoring/4-recipes.md) — `ExportDataTableJob` walked through.
- [Search endpoint](../8-routes-and-search/2-search-endpoint.md) — the `route('search', Model::class)` API used by async selects.

[Back to chapter](0-index.md) · [Back to index](../0-index.md)
