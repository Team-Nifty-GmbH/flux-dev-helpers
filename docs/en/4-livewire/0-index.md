# Livewire

Flux's UI layer is Livewire components composed of TallStackUI Blade components, with three Flux-specific abstractions on top:

- **`FluxForm`** — Livewire form objects that wrap `FluxAction`. Validation, permission checks, action dispatch, and async support are inherited from the base.
- **`BaseDataTable`** — Livewire data tables (extending `tall-datatables`) with monitored export, share filters, and `HasEloquentListeners` for live updates.
- **Widgets** — Livewire components renderable on dashboards, with strict rules (no `mount` args, no outer card wrapper) so they compose with the dashboard layout.

This chapter is the daily reference for building Livewire UIs in your packages and projects.

## What this chapter covers

1. [Form objects](1-form-objects.md) — `FluxForm`, `getActions()`, `save()`/`create()`/`update()`/`delete()`/`restore()`, `toActionData()`, `async()`, `canAction()`, the standard form-modal pattern.
2. [Data tables](2-data-tables.md) — `BaseDataTable`, monitored export, search/filter/sort, share filters, `HasEloquentListeners`, the `DataTableHasFormEdit` trait.
3. [Widgets](3-widgets.md) — `Widgetable`, time-frame-aware widgets, the no-`mount`-args / no-outer-card rules, dashboard composition.
4. [TallStackUI conventions](4-tallstackui-conventions.md) — full reference of the components Flux uses, the correct prop shapes, and the gotchas around Alpine + Livewire integration.

## The mental model

A typical Flux page is structured like this:

```
EditOrder (Livewire component)
├── public OrderForm $form          ← state for edit operations
├── mount() / save() / archive()
└── view: edit-order.blade.php
    ├── <x-card>                    ← TallStackUI surface
    │   ├── <x-input wire:model="form.number" :label="__('Number')" />
    │   ├── <x-select.styled wire:model="form.contact_id" ... />
    │   ├── <x-tabs>                ← detail tabs (positions, comments, files)
    │   │   ├── @stack('order-detail-tabs')   ← extension hook
    │   │   └── ...
    │   └── <x-button text="..." wire:click="save" />
    └── @push('scripts') ... @endpush
```

The component is thin. The form holds state. Actions do work. Data tables list records. Widgets summarise. Each layer is isolated; the conventions below keep them that way.

## Three rules of thumb

1. **Form objects, not raw Livewire properties** for anything that drives an action. The form provides `validateSave()`, `canAction()`, `toActionData()` — duplicating that on a component is redundant.
2. **TallStackUI components only.** No WireUI, no Mary UI, no raw Bootstrap form controls. The styling, the modal pattern, the toast integration — all assume TallStackUI.
3. **Livewire navigation by default.** `wire:navigate` on `<a>` links (when both endpoints are Livewire). Preserves Alpine state, avoids full reloads, plays nicely with the persistent toast notifications layer.

## Where Livewire components live

```
src/Livewire/
├── Forms/
│   ├── FluxForm.php
│   ├── OrderForm.php
│   ├── ContactForm.php
│   └── ...
├── DataTables/
│   ├── BaseDataTable.php
│   ├── OrderList.php
│   ├── ContactList.php
│   └── ...
├── Order/
│   ├── EditOrder.php
│   ├── OrderShipments.php
│   └── ...
├── Contact/
├── Features/
│   ├── Notifications.php
│   └── ...
└── Widgets/
    ├── OpenOrders.php
    └── ...
```

Domain components in their domain subdirectory; forms in `Forms/`; data tables in `DataTables/`; cross-cutting "feature" components (notifications, search overlay) in `Features/`. Widgets either in `Livewire/Widgets/` or in a top-level `Widgets/` directory depending on the package.

## Related

- [Actions](../3-actions/0-index.md) — what forms wrap.
- [Conventions](../1-getting-started/1-conventions.md) — naming, blade stack naming, alpine rules.
- [Notifications](../5-notifications/0-index.md) — the toast layer that Livewire actions feed into.

[Back to index](../0-index.md)
