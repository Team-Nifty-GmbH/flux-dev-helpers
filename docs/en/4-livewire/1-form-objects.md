# Form Objects

`FluxErp\Livewire\Forms\FluxForm` is the standard Livewire form object that wraps a `FluxAction`. Use it whenever a Livewire component needs to create, update, delete, or restore a record.

## The base class

```php
abstract class FluxForm extends BaseForm
{
    abstract protected function getActions(): array;

    public function save(): void { /* create or update */ }
    public function create(): void { /* dispatch CreateXAction */ }
    public function update(): void { /* dispatch UpdateXAction */ }
    public function delete(): void { /* dispatch DeleteXAction */ }
    public function restore(): void { /* dispatch RestoreXAction */ }

    public function async(bool $async = true): static { /* enable async */ }
    public function canAction(string $action): bool { /* permission check */ }
    public function toActionData(): array { /* public properties → action data */ }
    public function validateSave(...): void { /* form-side validation */ }
    public function validateDelete(...): void { /* form-side validation */ }
}
```

It extends `Livewire\Form` (Livewire's standard form-object base) and adds the action wiring.

## Minimal form

```php
namespace FluxErp\Livewire\Forms;

use FluxErp\Actions\Voucher\CreateVoucher;
use FluxErp\Actions\Voucher\DeleteVoucher;
use FluxErp\Actions\Voucher\UpdateVoucher;

class VoucherForm extends FluxForm
{
    public ?int $id = null;
    public ?int $tenant_id = null;
    public ?string $code = null;
    public ?float $amount = null;
    public ?string $expires_at = null;

    protected function getActions(): array
    {
        return [
            'create' => CreateVoucher::class,
            'update' => UpdateVoucher::class,
            'delete' => DeleteVoucher::class,
        ];
    }
}
```

That's enough for `save()`, `create()`, `update()`, `delete()` to work. The base class:

1. Calls `toActionData()` to get the public-property dictionary.
2. Resolves the action class from `getActions()[$verb]`.
3. `Action::make($data)->checkPermission()->validate()->execute()`.
4. Fills the form back from the action result (so the form's `id` etc. is up to date after create).

## Public properties as action data

`toActionData()` returns a snapshot of public properties via Livewire's `Utils::getPublicProperties`:

```php
public function toActionData(): array
{
    return Utils::getPublicProperties(
        $this,
        fn (ReflectionProperty $property) => collect($property->getAttributes())
            ->doesntContain(fn ($attribute) => $attribute->getName() === ExcludeFromActionData::class)
    );
}
```

Two things to know:

- **All public typed properties are included.** No need to maintain a separate "fields" list.
- **Properties with the `#[ExcludeFromActionData]` attribute are skipped.** Use this for UI-only state on the form (a visibility toggle, a "show advanced fields" flag) that shouldn't reach the action.

```php
use FluxErp\Support\Livewire\Attributes\ExcludeFromActionData;

class OrderForm extends FluxForm
{
    public ?int $id = null;
    public ?string $number = null;

    #[ExcludeFromActionData]
    public bool $showAdvanced = false;

    // ...
}
```

`showAdvanced` is bindable from the view (`wire:model="form.showAdvanced"`) but doesn't end up in the action's `$this->data`.

## `save()` — the canonical entrypoint

```php
public function save(): void
{
    if ($this->{$this->getKey()}) {
        $this->update();
    } else {
        $this->create();
    }
}
```

`save()` decides between create and update based on whether the form has a key. Use this in your view (`wire:click="save"`) for the standard "submit form" button — it covers both new and edit cases without branching in the component.

If your form doesn't use `id` as the key (e.g. composite keys on a pivot), override `getKey(): string` to return the right column name.

## Permission checks

The base methods call `checkPermission()` on the action by default, throwing `UnauthorizedException` if the user can't perform the action. To suppress the check (for system-level forms invoked by background jobs):

```php
$form->setCheckPermission(false);
```

To check permission preemptively from the view (so the button can be disabled):

```php
@if ($form->canAction('update'))
    <x-button text="{{ __('Save') }}" wire:click="save" />
@endif
```

`canAction(string $action)` does a soft check (`canPerformAction(throwException: false)`) — returns `false` if the user lacks the permission, without raising.

## Validation: `validateSave()` and `validateDelete()`

Sometimes you want to validate _before_ calling `save()` — for example, to show errors inline as the user types, or to gate a "Continue" button.

```php
$form->validateSave();
```

This runs `parent::validate()` (Livewire's form validation) using the rules from the appropriate action (`Create...` if `id` is null, otherwise `Update...`), filtered to keys that are actually present in the form data.

```php
$form->validateDelete();
```

Same pattern, but uses the `Delete...` action's rules.

These don't dispatch the action; they only validate. Call `save()` to actually run.

## Filling the form from a model

Standard Livewire pattern:

```php
public function mount(?int $orderId = null): void
{
    if ($orderId) {
        $order = Order::query()->whereKey($orderId)->firstOrFail();
        $this->form->fill($order->toArray());
    }
}
```

`fill()` is provided by Livewire's `Form` base; it sets matching public properties from the array. After a successful action, the form auto-fills from the action's result, so the displayed state reflects post-mutation values.

## Async actions

For actions that should be queued instead of run synchronously:

```php
$this->form->async()->save();
```

Or set up-front:

```php
public function mount(): void
{
    $this->form->async();
}
```

The action class must extend `DispatchableFluxAction`. Otherwise the form throws `InvalidArgumentException`. See [Dispatchable actions](../3-actions/3-dispatchable-actions.md).

After `async()->save()`, the action is dispatched and `save()` returns immediately. The user gets a queue-monitor toast as the job runs (see [Queue monitoring](../6-queue-monitoring/0-index.md)).

## The standard form-modal pattern

For modal-based edit forms, the canonical TallStackUI pattern:

```blade
<x-modal :id="$form->modalName()" size="xl" :title="__('Voucher')">
    <form wire:submit.prevent="save" class="space-y-4">
        <x-input wire:model="form.code" :label="__('Code')" required />
        <x-number wire:model="form.amount" :label="__('Amount')" required />
    </form>

    <x-slot:footer>
        <x-button
            :text="__('Cancel')"
            color="secondary"
            flat
            x-on:click="$modalClose('{{ $form->modalName() }}')"
        />
        <x-button
            :text="__('Save')"
            color="primary"
            wire:click="save"
        />
    </x-slot:footer>
</x-modal>
```

Notes:

- `:title="__('Voucher')"` — title as prop, not as `<x-slot:title>`. Both work, but the prop form is canonical.
- `<x-slot:footer>` — footer as named slot.
- `$modalClose($id)` — Alpine helper to close the modal by id.
- The modal id comes from `$form->modalName()` (defined in some base forms; you may also hard-code).

For the `DataTableHasFormEdit` trait that auto-wires data-table rows to a form modal, see [Data tables](2-data-tables.md).

## When _not_ to use a `FluxForm`

Three cases where a raw Livewire component is fine:

- **A read-only display.** Forms exist to dispatch actions; if there's no action, there's no form.
- **A search/filter input** that doesn't mutate anything. Use Livewire properties on the parent component.
- **A widget configuration form** that the widget itself manages (widgets have their own state-management contract — see [Widgets](3-widgets.md)).

Don't reach for `FluxForm` reflexively. The principle is: if there's an action behind it, use the form. If there isn't, plain Livewire is enough.

## Customising the action call

The default `create()`/`update()`/`delete()`/`restore()` build the action with `make($this->toActionData())` and run the standard chain. To customise — for example, to add ad-hoc rules or a different acting-as user — override:

```php
public function update(): void
{
    $action = $this->makeAction('update')
        ->actingAs($this->systemUser)
        ->addRules(['memo' => 'required'])
        ->checkPermission()
        ->validate();

    $this->actionResult = $action->execute();
    $this->fill($this->actionResult);
}
```

`makeAction(string $verb)` is `protected` and accessible — it's the standard hook point.

## Related

- [Actions](../3-actions/0-index.md) — what the form wraps.
- [Data tables](2-data-tables.md) — `DataTableHasFormEdit` for row-edit modals.
- [TallStackUI conventions](4-tallstackui-conventions.md) — the components used in form views.
- [Dispatchable actions](../3-actions/3-dispatchable-actions.md) — async support.

[Back to chapter](0-index.md) · [Back to index](../0-index.md)
