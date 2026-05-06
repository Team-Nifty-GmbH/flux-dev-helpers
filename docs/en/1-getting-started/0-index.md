# Getting Started

This chapter is the orientation pass. Read it first if you're new to Flux. It covers the conventions you'll trip over otherwise, and walks the layers of a single feature end-to-end so you can see how the pieces interact before diving into individual chapters.

## What this chapter covers

1. [Conventions](1-conventions.md) — naming, package layout, code style, the rules that keep multiple packages from colliding.
2. [Anatomy of a feature](2-anatomy-of-a-feature.md) — a full traversal: model + migration → action + ruleset → form → Livewire component → data table → permissions. Annotated with what each layer is responsible for and what it isn't.

## How Flux is organised

Flux ships as a Composer package (`team-nifty-gmbh/flux-erp`) plus a constellation of feature packages that depend on it (`flux-finapi`, `flux-zugferd`, `nuxbe-mailing`, `nuxbe-shopware`, …). Your application — the actual Laravel app the customer uses — composes these packages and adds tenant-specific code on top.

The key boundaries:

- **flux-core** owns the domain primitives — `FluxModel`, `FluxAction`, `FluxForm`, `BaseDataTable`, the auto-registered model channels, the queue monitor, the toast system, the permission convention, the search endpoint, the helpers.
- **Feature packages** add domain modules (banking, accounting, shipping, …). They follow the same conventions and consume the core primitives.
- **Application code** — for tenant-specific customisation — overrides via container bindings and service-provider hooks rather than forking core or feature classes.

## What you actually need to know first

Three rules that catch out new contributors more than anything else:

1. **Don't bypass the action layer.** Reach for `CreateOrder::make($data)->validate()->execute()`, not `Order::create($data)`. See [Actions](../3-actions/0-index.md).
2. **Use the right UI component library.** Flux uses TallStackUI exclusively — `x-input`, `x-select.styled`, `x-button text="..."`, etc. Not WireUI, not Mary UI, not raw form elements. See [TallStackUI conventions](../4-livewire/0-index.md).
3. **Honour the permission convention.** Routes use `route_to_permission()` (no prefix); actions use `action.{name}` automatically. Don't invent new schemas. See [Permissions](../3-actions/4-permissions.md).

Everything else — broadcasting, queue monitoring, Livewire patterns, the helper landscape — is content of later chapters. The point of this chapter is the orientation that makes those chapters legible.

## Related

- [Actions](../3-actions/0-index.md) — the central pattern.
- [Models](../2-models/0-index.md) — the data layer.
- [Livewire](../4-livewire/0-index.md) — UI conventions.

[Back to index](../0-index.md)
