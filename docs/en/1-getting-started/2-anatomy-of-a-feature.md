# Anatomy of a Feature

End-to-end traversal of a hypothetical feature: a `Voucher` domain, with a model, an action, a form, a Livewire detail component, and a data table. Every step shows what each layer is responsible for and what it isn't.

This is the working pattern for any new domain. Once you've done one, the next ones are mechanical.

## Goal

Add vouchers (gift cards) with the following capabilities:

- A `Voucher` model with `code`, `amount`, `expires_at`, `redeemed_at`, `tenant_id`, `created_by`.
- A `CreateVoucher` action with validation rules (unique code per tenant, positive amount, future expiry).
- An `EditVoucherForm` form object that wraps the action.
- A `EditVoucher` Livewire detail page.
- A `VoucherList` data table with export and filter.
- A permission `action.voucher.create` (auto-derived from the action class name).

## 1. The migration

```php
// database/migrations/2026_05_06_000000_create_vouchers_table.php
return new class extends Migration {
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code')->unique();
            $table->decimal('amount', 18, 4);
            $table->dateTime('expires_at');
            $table->dateTime('redeemed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
```

Notes:

- `tenant_id` for multi-tenancy.
- `created_by` for the `HasUserModification` trait on the model.
- `softDeletes()` for the `SoftDeletes` trait.

## 2. The model

```php
namespace FluxErp\Models;

use FluxErp\Traits\Model\HasTenants;
use FluxErp\Traits\Model\HasUserModification;
use FluxErp\Traits\Model\LogsActivity;
use Illuminate\Database\Eloquent\SoftDeletes;

class Voucher extends FluxModel
{
    use HasTenants, HasUserModification, LogsActivity, SoftDeletes;

    public function casts(): array
    {
        return [
            'amount'       => 'decimal:4',
            'expires_at'   => 'datetime',
            'redeemed_at'  => 'datetime',
        ];
    }
}
```

Notes:

- Extends `FluxModel`, not `Model`. That gets you `BroadcastsEvents`, `HasModelPermission`, `ResolvesRelationsThroughContainer`, and the standard guarded fields.
- No `$fillable` — `FluxModel` is `$guarded = ['id', 'created_at', 'updated_at']`, so everything else is mass-assignable.
- `casts()` method, not `$casts` property — composes with parent traits.
- Traits enabled by feature: `HasTenants` (multi-tenant scope), `HasUserModification` (auto-stamp `created_by`/`updated_by`), `LogsActivity` (Spatie audit log), `SoftDeletes`.

Register the morph alias in your service provider's `boot()`:

```php
Relation::enforceMorphMap([
    'voucher' => Voucher::class,
]);
```

The morph alias is what `morph_alias(Voucher::class)` returns and what the broadcast channel name (`private-voucher.{voucher}`) uses.

## 3. The ruleset

```php
namespace FluxErp\Rulesets\Voucher;

use FluxErp\Models\Voucher;
use FluxErp\Rulesets\FluxRuleset;

class CreateVoucherRuleset extends FluxRuleset
{
    protected static ?string $model = Voucher::class;

    public function rules(): array
    {
        return [
            'tenant_id'  => 'required|integer|exists:tenants,id',
            'code'       => 'required|string|max:255|unique:vouchers,code',
            'amount'     => 'required|numeric|gt:0',
            'expires_at' => 'required|date|after:now',
        ];
    }
}
```

Notes:

- `static $model = Voucher::class` lets the framework auto-merge translation rules if the model has translatable attributes (this one doesn't, but the property is harmless).
- Rules describe the _input_ shape, not the database constraints. `unique` is a Laravel rule, not a DB constraint check — both are needed (unique constraint on the migration, unique rule here for friendly errors).

## 4. The action

```php
namespace FluxErp\Actions\Voucher;

use FluxErp\Actions\FluxAction;
use FluxErp\Models\Voucher;
use FluxErp\Rulesets\Voucher\CreateVoucherRuleset;

class CreateVoucher extends FluxAction
{
    public static function models(): array
    {
        return [Voucher::class];
    }

    protected function getRulesets(): string|array
    {
        return CreateVoucherRuleset::class;
    }

    public function performAction(): Voucher
    {
        $voucher = app(Voucher::class, ['attributes' => $this->data]);
        $voucher->save();

        return $voucher;
    }
}
```

Notes:

- `models()` returns the affected model. This drives Scramble's API tagging.
- `getRulesets()` references the ruleset. Validation auto-merges from this.
- `performAction()` does the work — straightforward `attribute → save`.
- Permission name is auto-derived: `CreateVoucher` → `voucher.create` → `action.voucher.create`. No additional configuration.

A symmetric `UpdateVoucher`, `DeleteVoucher`, and `RestoreVoucher` follow the same shape.

## 5. The form object

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

Notes:

- Extends `FluxForm`. Gets `create()`, `update()`, `delete()`, `save()`, `restore()`, `validateSave()`, `validateDelete()`, `canAction()`, `toActionData()` for free.
- One public typed property per field. `toActionData()` walks public properties and returns them as an array; the form is the contract between Livewire and the action.
- The `getActions()` map binds verbs to action classes. The form's `create()`, `update()`, `delete()` use these.
- `save()` (provided by the base) calls `create()` if `$id` is null, otherwise `update()`.

## 6. The Livewire detail component

```php
namespace FluxErp\Livewire\Voucher;

use FluxErp\Livewire\Forms\VoucherForm;
use FluxErp\Models\Voucher;
use FluxErp\Traits\Livewire\SupportsAutoRender;
use Livewire\Attributes\Locked;
use Livewire\Component;

class EditVoucher extends Component
{
    use SupportsAutoRender;

    #[Locked]
    public ?int $voucherId = null;

    public VoucherForm $form;

    public function mount(?int $voucherId = null): void
    {
        if ($voucherId) {
            $voucher = Voucher::query()->whereKey($voucherId)->firstOrFail();
            $this->form->fill($voucher->toArray());
        }
    }

    public function save(): void
    {
        $this->form->save();
        $this->skipRender();
    }
}
```

Notes:

- `SupportsAutoRender` resolves the view automatically from the component's class path; no `render()` method needed.
- `#[Locked]` prevents the property from being modified by the client. Use it for primary keys.
- `$form` is a Livewire form object — Livewire's standard form-object support, with Flux conventions.
- The component itself is thin. The action does the work; the form holds the state.

## 7. The Blade view

```blade
{{-- resources/views/livewire/voucher/edit-voucher.blade.php --}}
<x-card>
    <form wire:submit.prevent="save" class="space-y-4">
        <x-input wire:model="form.code" :label="__('Code')" required />
        <x-number wire:model="form.amount" :label="__('Amount')" required />
        <x-input wire:model="form.expires_at" type="datetime-local" :label="__('Expires at')" required />

        <div class="flex justify-end gap-2">
            <x-button text="{{ __('Save') }}" color="primary" type="submit" />
        </div>
    </form>
</x-card>
```

Notes:

- TallStackUI components throughout: `x-input`, `x-number`, `x-button`. Not `x-inputs.text` (that's WireUI).
- `x-button text="..."` — text as prop, not as slot content.
- `:label="__('Code')"` — translated. The `:` colon prefix passes a PHP expression to the prop.

## 8. The data table

```php
namespace FluxErp\Livewire\DataTables;

use FluxErp\Models\Voucher;

class VoucherList extends BaseDataTable
{
    protected string $model = Voucher::class;

    public array $enabledCols = ['code', 'amount', 'expires_at', 'redeemed_at'];

    public array $sortable = ['code', 'amount', 'expires_at'];

    public array $availableCols = [
        'code', 'amount', 'expires_at', 'redeemed_at', 'created_at',
    ];
}
```

Notes:

- Extends `BaseDataTable`. Gets export, filter, sort, search for free.
- Model auto-derived; `protected string $model = Voucher::class;` is the binding.
- `$enabledCols` is the default column set. Users can re-arrange via UI.
- The export button (built into the data table UI) dispatches `ExportDataTableJob`, which emits a monitored toast with a download link when the file is ready.

## 9. Routes

```php
// routes/web.php
Route::middleware('auth')->group(function (): void {
    Route::get('vouchers', VoucherList::class)
        ->name('vouchers.index')
        ->permissionName('voucher.list');

    Route::get('vouchers/{voucher}', EditVoucher::class)
        ->name('vouchers.show')
        ->permissionName('voucher.show');
});
```

Notes:

- Routes point at Livewire components directly. No controller methods.
- `->permissionName(...)` declares the route-level permission. `route_to_permission()` reads this.

The action permission (`action.voucher.create`) and the route permission (`voucher.list`, `voucher.show`) are independent and live alongside each other.

## 10. The smoke test

```php
// tests/Livewire/Voucher/EditVoucherTest.php
it('renders the edit voucher component', function (): void {
    Livewire::test(EditVoucher::class)
        ->assertOk();
});

it('creates a voucher through the form', function (): void {
    actingAs(User::factory()->create());

    Livewire::test(EditVoucher::class)
        ->set('form.tenant_id', 1)
        ->set('form.code', 'WELCOME10')
        ->set('form.amount', 10.00)
        ->set('form.expires_at', now()->addYear()->toDateTimeString())
        ->call('save')
        ->assertOk();

    expect(Voucher::query()->where('code', 'WELCOME10')->exists())->toBeTrue();
});
```

Notes:

- One smoke test per Livewire component, minimum. The first test asserts the component _renders_; the second asserts the happy path of its primary action.
- Tests hit a real database (in-memory SQLite in CI). No DB mocking.
- `actingAs(User::factory()->create())` puts a user in auth — the action's permission check would otherwise throw.

## What you didn't have to write

The point of going through this is to notice everything you _didn't_ touch:

- Permission middleware — `Controller::__construct` derives from the route name automatically.
- Activity logging — the `LogsActivity` trait records create/update/delete events.
- Tenant scoping — the `HasTenants` trait scopes queries automatically.
- User modification — `HasUserModification` stamps `created_by` / `updated_by`.
- Validation message resolution — Laravel's translator works against your `lang/en.json` and `lang/de.json` automatically.
- Action discovery — `models()` is enough; Scramble picks it up.
- Search endpoint — if you need an async select on `Voucher`, `route('search', Voucher::class)` works without further wiring.
- Broadcast channel — `private-voucher.{voucher}` and `private-voucher.` are auto-registered because `FluxModel` uses `BroadcastsEvents`.
- Toast notifications — model events fire `BroadcastsEvents`, so Livewire components subscribed to the channel re-render live without polling.

That's the leverage. Once a model is wired into the framework, a lot of behaviour comes for free.

## Related

- [Conventions](1-conventions.md) — naming, layout, code style.
- [Actions](../3-actions/0-index.md) — the action lifecycle in detail.
- [Models](../2-models/0-index.md) — the trait ecosystem.
- [Livewire](../4-livewire/0-index.md) — forms, data tables, the auto-render trait.

[Back to chapter](0-index.md) · [Back to index](../0-index.md)
