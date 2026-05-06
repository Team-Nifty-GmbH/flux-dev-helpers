# Flux Developer Docs

Reference for developers building on the FluxErp core. Code citations point at `team-nifty-gmbh/flux-erp` (`packages/flux-core`).

## Reading order

1. [Getting Started](1-getting-started/0-index.md) — Conventions, package layout, anatomy of a feature.
2. [Models](2-models/0-index.md) — `FluxModel`, traits, states.
3. [Actions](3-actions/0-index.md) — `FluxAction`, rulesets, dispatchable actions, permissions.
4. [Livewire](4-livewire/0-index.md) — Forms, data tables, widgets, TallStackUI conventions.
5. [Notifications](5-notifications/0-index.md) — `Notification`, toast notifications, notification actions.
6. [Queue Monitoring](6-queue-monitoring/0-index.md) — Monitored jobs, monitored batches, progress toasts.
7. [Events & Broadcasting](7-events-and-broadcasting/0-index.md) — Channels, immediate broadcasts.
8. [Routes & Search](8-routes-and-search/0-index.md) — Base controller, search endpoint.
9. [Helpers](9-helpers.md) — Global helper functions.
10. [Extending the Frontend](10-extending-the-frontend.md) — Custom tabs, editor buttons, blade stack naming.

## Topic index

- **Actions** — [3-actions](3-actions/0-index.md)
- **Async actions** — [Dispatchable actions](3-actions/0-index.md), [Queue monitoring](6-queue-monitoring/0-index.md)
- **Batches** — [Monitored batches](6-queue-monitoring/2-monitored-batches.md)
- **Broadcasting** — [7-events-and-broadcasting](7-events-and-broadcasting/0-index.md)
- **Data tables** — [4-livewire](4-livewire/0-index.md)
- **Forms** — [4-livewire](4-livewire/0-index.md)
- **Helpers** — [9-helpers](9-helpers.md)
- **Jobs** — [Monitored jobs](6-queue-monitoring/1-monitored-jobs.md)
- **Models** — [2-models](2-models/0-index.md)
- **Notifications** — [5-notifications](5-notifications/0-index.md)
- **Permissions** — [Action permissions](3-actions/0-index.md)
- **Progress toasts** — [Progress and toasts](6-queue-monitoring/3-progress-and-toasts.md)
- **Rulesets** — [3-actions](3-actions/0-index.md)
- **Search endpoint** — [8-routes-and-search](8-routes-and-search/0-index.md)
- **TallStackUI** — [4-livewire](4-livewire/0-index.md)

## Audience

These docs are for developers building on top of flux-core — feature packages, customer-portal projects, tenant customisations. Not for flux-core maintainers and not for end users.

## Status

All ten chapters have content. The framework evolves; expect drift in places where examples deviate from current source.
