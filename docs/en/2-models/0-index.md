# Models

Every model in Flux either extends `FluxErp\Models\FluxModel` (or `FluxPivotModel` for pivot tables) and composes traits from `FluxErp\Traits\Model\` for the cross-cutting capabilities — broadcasting, permissions, tags, tenants, audit logs, soft deletes, media, translations, parent-child trees, queue monitoring, default-record marking, and more.

The base class is small. The trait ecosystem is where most of the framework's domain power lives.

## What this chapter covers

1. [FluxModel](1-flux-model.md) — the base class, what it gives you, scope helpers, how casts and relations are resolved.
2. [Model traits](2-model-traits.md) — reference for the standard trait set: which trait, what it does, when to use it.
3. [States](3-states.md) — Spatie ModelStates integration for state machines (orders, queue monitor, leads).

## Three rules to internalize first

- **Extend `FluxModel`, never raw `Model`.** You inherit broadcasting, permission integration, container-resolved relations, and the standard guarded fields. Applications that subclass `Model` directly disable half the framework.
- **Casts via `casts()`, not `$casts`.** The method form composes with parent traits via `array_merge(parent::casts(), [...])`. The property form replaces.
- **Identifiers via `getKey()`, not `->id`.** Some models use UUIDs/ULIDs. `getKey()` is the only key-access form that's correct in all cases.

## Where models live

```
src/Models/
├── FluxModel.php
├── FluxPivotModel.php
├── Address.php
├── Order.php
├── Contact.php
├── User.php
├── ...
└── Pivots/
    ├── JobBatchable.php
    └── ...
```

Domain models at the top level; pivot models under `Pivots/`. Naming is singular PascalCase.

## Related

- [Conventions](../1-getting-started/1-conventions.md) — model naming, fillable/guarded conventions, ID handling.
- [Actions](../3-actions/0-index.md) — the layer that mutates models. Don't `Order::create($data)`; do `CreateOrder::make($data)->validate()->execute()`.
- [Events & Broadcasting](../7-events-and-broadcasting/0-index.md) — every `FluxModel` broadcasts via the `BroadcastsEvents` trait.

[Back to index](../0-index.md)
