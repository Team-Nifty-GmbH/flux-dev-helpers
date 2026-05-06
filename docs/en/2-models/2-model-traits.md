# Model Traits

Reference for the standard model traits in `FluxErp\Traits\Model\`. Pick the ones your model needs; don't over-apply.

The pattern: every cross-cutting capability — broadcasting, audit log, soft delete, tagging, multi-tenancy, queue monitoring — is a trait. The trait does the wiring (boot hooks, relations, casts, scopes); your model declares intent by `use`-ing it.

For your own packages: prefer the framework traits over re-implementing. The auto-registered channels, the search endpoint, and downstream packages all key off these traits — implementing your own will leave gaps.

## Reference

### `BroadcastsEvents` (Laravel)

Already on every `FluxModel` via the base class. Broadcasts `created`, `updated`, `deleted`, `trashed`, `restored` on the model's auto-registered channel. See [Broadcast channels](../7-events-and-broadcasting/1-broadcast-channels.md).

You don't `use` this on subclasses — it's inherited.

### `HasModelPermission`

Already on every `FluxModel`. Adds permission-aware queries and per-record permission checks. Inherited.

### `ResolvesRelationsThroughContainer`

Already on every `FluxModel`. Makes `belongsTo`/`hasMany`/etc. resolve relations through the container so downstream packages can swap models. Inherited.

### `HasUserModification`

Stamps `created_by` and `updated_by` user IDs.

```php
use FluxErp\Traits\Model\HasUserModification;

class Voucher extends FluxModel
{
    use HasUserModification;
}
```

Migration columns: `created_by` and `updated_by` (nullable, FK to `users`). The trait fills both on `creating` / `updating` events using `auth()->id()`.

When to use: any model whose audit trail should record the human that touched it. Most domain models.

### `HasTenants`

Multi-tenancy via a `tenant_id` foreign key (or, for many-to-many tenancy, a morph-to-many pivot).

```php
use FluxErp\Traits\Model\HasTenants;

class Voucher extends FluxModel
{
    use HasTenants;
}
```

Adds:

- A global scope that filters queries by the current tenant (`whereHasTenant($tenantId)` or the active tenant from `Context::get('tenant_id')`).
- `getTenantId()` accessor.
- `tenants()` morph-to-many or direct relation depending on table shape.

Migration columns: `tenant_id` (foreign id, nullable for cross-tenant records, otherwise required).

When to use: every domain model that should be isolated per tenant.

### `LogsActivity`

Spatie audit log integration. Records create/update/delete events with the model attributes that changed.

```php
use FluxErp\Traits\Model\LogsActivity;

class Voucher extends FluxModel
{
    use LogsActivity;
}
```

The Flux variant pre-configures the Spatie defaults — log name, attributes to track, batch UUID propagation. The activity entries appear in the activity log section of model detail pages and in `activity_log` table queries.

When to use: any model where "who changed what when" is auditable. In practice, almost everything.

### `SoftDeletes` (Laravel)

Standard Laravel soft delete. Adds `deleted_at`, makes `delete()` set the timestamp, adds `withTrashed()`/`onlyTrashed()`/`restore()`.

```php
use Illuminate\Database\Eloquent\SoftDeletes;

class Voucher extends FluxModel
{
    use SoftDeletes;
}
```

Migration: `$table->softDeletes();`.

When to use: any record that should be undeletable. The action layer's `DeleteVoucher` plus `RestoreVoucher` pair lines up with this.

### `CascadeSoftDeletes`

When this model is soft-deleted, automatically soft-delete declared related models too.

```php
use FluxErp\Traits\Model\CascadeSoftDeletes;

class Order extends FluxModel
{
    use CascadeSoftDeletes;

    protected $cascadeSoftDeletes = ['orderPositions', 'comments'];
}
```

When to use: a model that owns child records, where soft-deleting the parent should hide the children. The relations listed in `$cascadeSoftDeletes` need to use a model with `SoftDeletes`.

### `HasTags`

Spatie tags integration: arbitrary taggable many-to-many.

```php
use FluxErp\Traits\Model\HasTags;
```

Adds `tags()` relation, `attachTag()`, `syncTags()`, scopes to filter by tags. Tag groups (categories of tags) are supported.

When to use: any user-tagged content. Tickets, contacts, orders.

### `Categorizable`

Hierarchical categories (a model belongs to a category that has a parent category that has a parent...).

```php
use FluxErp\Traits\Model\Categorizable;
```

Different from tags: categories are a tree, tags are a flat label set. A model can be tagged with multiple tags but typically belongs to one category.

### `HasAttributeTranslations`

Translatable attributes stored as JSON columns.

```php
use FluxErp\Traits\Model\HasAttributeTranslations;

class Product extends FluxModel
{
    use HasAttributeTranslations;

    public array $translatable = ['name', 'description'];
}
```

The columns `name` and `description` are stored as JSON keyed by language code (`{"en": "Foo", "de": "Föö"}`). Reads are auto-translated based on the current locale. Writes accept either a string (current locale) or an array (full translation set).

`FluxRuleset::getRules()` automatically merges `attributeTranslationRules()` from the model when `static::$model` and `static::$addTranslationRules` are set on the ruleset.

### `HasFrontendAttributes`

Custom attributes (key-value JSON pairs) that the frontend can read and modify on a model. Used for tenant-specific extensions where you don't want to add columns.

```php
use FluxErp\Traits\Model\HasFrontendAttributes;
```

Adds a JSON `frontend_attributes` column accessor and a `setFrontendAttribute()` method.

### `HasDefault`

One record in a set is marked as the default; setting another as default unsets the previous one.

```php
use FluxErp\Traits\Model\HasDefault;

class PaymentType extends FluxModel
{
    use HasDefault;
}
```

Adds:

- `is_default` column handling (the migration needs `boolean('is_default')`).
- `setDefault()` method to mark a record default.
- `default()` scope/static method to retrieve the default.

When to use: any "one of these is the canonical pick" relationship. Default currency, default payment type, default address per contact.

### `HasParentChildRelations`

Recursive parent-child hierarchies (single-parent trees).

```php
use FluxErp\Traits\Model\HasParentChildRelations;

class Category extends FluxModel
{
    use HasParentChildRelations;
}
```

Adds:

- `parent_id` column handling.
- `parent()` / `children()` relations.
- `descendants()` recursive query.
- `ancestors()` walking up the tree.

Migration: `$table->foreignId('parent_id')->nullable()->constrained()->nullOnDelete();`.

### `HasParentMorphClass`

Polymorphic parent (a record points at a parent of any type).

```php
use FluxErp\Traits\Model\HasParentMorphClass;
```

Adds:

- `parent_type` and `parent_id` columns.
- `parent()` morph-to relation.

Different from `HasParentChildRelations` (single type, single tree) — this is "parent could be anything" (a comment's parent could be a ticket, an order, etc.).

### `HasUuid` / `HasUlids`

Use ULIDs or UUIDs as primary key. The Laravel built-ins (`HasUlids`, `HasUuids`) work normally; the framework variants exist where additional behaviour is needed.

```php
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class ImportRow extends FluxModel
{
    use HasUlids;
}
```

When to use: high-write tables where integer key contention is a concern, or tables that synchronise across systems where the key needs to be globally unique.

### `Notifiable` (Laravel)

Standard Laravel notifiable. Adds `notify()` and `routeNotificationFor*()`.

```php
use Illuminate\Notifications\Notifiable;

class Contact extends FluxModel
{
    use Notifiable;
}
```

When to use: any model that should receive notifications (mostly users; sometimes contacts for email-only notifications).

### `MonitorsQueue`

Allows the model to be attached to `QueueMonitor` records (so the user can see "this order has 3 active jobs").

```php
use FluxErp\Traits\Model\MonitorsQueue;

class User extends FluxModel
{
    use MonitorsQueue;
}
```

Adds:

- `queueMonitors()` morph-to-many relation.
- `jobBatches()` morph-to-many relation.

Used by `MonitorablePendingBatch::before()` — when a batch starts, the dispatching user is attached to the `JobBatch` if they have this trait. Most often applied to `User` only; you don't need it on every model.

### `InteractsWithMedia`

Spatie media library integration.

```php
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Order extends FluxModel implements HasMedia
{
    use InteractsWithMedia;
}
```

Adds:

- Media library relations and methods.
- File upload handling via the upload pipeline.
- Media collection definitions via `registerMediaCollections()`.

When to use: any model that has documents, images, or attachments.

### `Trackable`

Versioning/auditing — keeps a history of attribute changes.

```php
use FluxErp\Traits\Model\Trackable;
```

Different from `LogsActivity` (single-line audit entry per change) — this stores _full versions_ of the record, retrievable by version number.

Use sparingly. Storage cost is proportional to write frequency.

### `Filterable`

Adds a fluent filter builder.

```php
use FluxErp\Traits\Model\Filterable;
```

The `BaseDataTable` uses this (combined with the `tall-datatables` library) to apply UI-driven filters.

### `HasSerialNumberRange`

Sequential numbering schemes (invoice numbers, order numbers, voucher codes per range).

```php
use FluxErp\Traits\Model\HasSerialNumberRange;
```

Allocates the next number from a configured range when a record is created.

### `HasCart`

For models that participate in cart-style flows (a contact's cart, etc.).

### `HasPushSubscriptions` / `HasPasskeys` / `InteractsWithPasskeys`

Web-push subscription storage and passkey/WebAuthn integration. Mostly relevant on the `User` model.

### `HasWidgets`

Stores per-user widget configuration (dashboard layouts).

### `HasRecordOrigin`

Tracks the source of a record (manual entry, import, API, sync) — useful for audit and debugging.

### `HasPackageFactory`

Lets a downstream package supply a model factory for a flux-core model. The factory is registered via the package's service provider; the trait wires it up.

### `Commentable` / `Communicatable`

Comment threads and communication-history (email, phone calls) on a record.

### `HasTenantAssignment`

Some models can be assigned to tenants more flexibly than via `tenant_id`. This trait provides the relation. Used in conjunction with `HasTenants` for cross-tenant scenarios.

### `Calendar` (sub-namespace)

Calendar-related traits live in `FluxErp\Traits\Model\Calendar\` — `Calendarable`, etc. Used for models that should appear on the calendar UI (events, appointments, tasks with deadlines).

## Picking traits

Three rules of thumb:

1. **Add traits when the migration shape supports them**, not after. `HasTags` without the polymorphic tag pivot table won't help.
2. **Don't add traits "just in case"**. Each trait has boot hooks and adds query overhead. `LogsActivity` doubles write cost for high-volume tables.
3. **Read the trait source if you're unsure**. They're short. The boot hooks and the relations are visible.

## Testing model traits

When testing models in your package:

- For `HasTenants` models, set the tenant context (`Context::set('tenant_id', $tenantId)`) before queries.
- For `HasUserModification` models, `actingAs(User::factory()->create())` so `auth()->id()` returns something.
- For `LogsActivity` models, the `activity_log` table needs to exist (loaded via testbench's migration discovery).
- For `BroadcastsEvents`, the broadcaster is `null` in tests by default — events fire but don't reach a real channel. Assert via `Bus::fake()` if you need to verify dispatch.

## Related

- [FluxModel](1-flux-model.md) — the base class these traits compose with.
- [States](3-states.md) — Spatie ModelStates for state-machine models.
- [Helpers](../9-helpers.md) — `get_models_with_trait()` for trait-driven discovery.
- [Events & Broadcasting](../7-events-and-broadcasting/0-index.md) — `BroadcastsEvents` and channel auto-registration.

[Back to chapter](0-index.md) · [Back to index](../0-index.md)
