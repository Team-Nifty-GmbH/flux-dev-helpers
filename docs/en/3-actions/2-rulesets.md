# Rulesets

A ruleset is a class that returns an array of Laravel validation rules. An action declares which rulesets it uses; the framework merges them. The point is to make rules composable — a `CreateOrder` ruleset can pull in an address ruleset, a bank-connection ruleset, and a user ruleset without copy-pasting.

## The base class

```php
// FluxErp\Rulesets\FluxRuleset
abstract class FluxRuleset
{
    protected static bool $addTranslationRules = true;
    protected static ?string $model = null;

    abstract public function rules(): array;

    public static function getRules(): array
    {
        $rules = (new static())->rules();

        if (static::$addTranslationRules && static::$model) {
            $model = app(static::$model);
            if (in_array(HasAttributeTranslations::class, class_uses_recursive($model))) {
                $rules = array_merge($model->attributeTranslationRules(), $rules);
            }
        }

        return $rules;
    }
}
```

Two abstract concerns and one piece of magic:

- `rules(): array` — what you implement. Standard Laravel rules.
- `static $model` — set to the model class if this ruleset belongs to a single model. Enables the auto-merging of attribute-translation rules (next paragraph).
- `static $addTranslationRules` — toggle the auto-merge. Default `true`. Set to `false` if a ruleset is for a model with translations but you don't want the translation rules in this particular call site.

If `$model` points at a model that uses `HasAttributeTranslations`, the model's `attributeTranslationRules()` are merged in front of the explicit rules. Translatable attributes get their per-language constraints automatically; you only write the explicit ones.

## A simple ruleset

```php
namespace FluxErp\Rulesets\Address;

use FluxErp\Rulesets\FluxRuleset;

class CreateAddressRuleset extends FluxRuleset
{
    public function rules(): array
    {
        return [
            'contact_id' => 'required|integer|exists:contacts,id',
            'street'     => 'required|string|max:255',
            'zip'        => 'required|string|max:20',
            'city'       => 'required|string|max:255',
            'country_id' => 'required|integer|exists:countries,id',
        ];
    }
}
```

That's it. No constructor, no DI — it's a stateless declaration of rules.

## Wiring a ruleset to an action

```php
class CreateAddress extends FluxAction
{
    public static function models(): array
    {
        return [Address::class];
    }

    protected function getRulesets(): string|array
    {
        return CreateAddressRuleset::class;
    }

    public function performAction(): Address
    {
        // ...
    }
}
```

`getRulesets()` returns either:

- a single class string (`CreateAddressRuleset::class`), or
- an array of class strings (`[CreateAddressRuleset::class, ContactScopedRuleset::class]`).

The base `setRulesFromRulesets()` walks each, calls its `getRules()`, and merges with `mergeRules()`:

```php
public function setRulesFromRulesets(): static
{
    foreach (Arr::wrap($this->getRulesets()) as $ruleset) {
        $this->mergeRules(resolve_static($ruleset, 'getRules'));
    }
    return $this;
}
```

`validate()` calls this automatically if `$this->rules` is empty when validation starts.

## Composing rulesets within rulesets

The pattern that makes rulesets really useful: a ruleset's `getRules()` can pull in other rulesets. The canonical example is `CreateOrderRuleset`:

```php
class CreateOrderRuleset extends FluxRuleset
{
    protected static ?string $model = Order::class;

    public static function getRules(): array
    {
        return array_merge(
            parent::getRules(),                                                       // own rules + translations
            resolve_static(BankConnectionRuleset::class, 'getRules'),
            Arr::prependKeysWith(
                resolve_static(PostalAddressRuleset::class, 'getRules'),
                'address_delivery.'                                                   // namespaced
            ),
            resolve_static(AddressRuleset::class, 'getRules'),
            resolve_static(UserRuleset::class, 'getRules'),
        );
    }

    public function rules(): array
    {
        return [
            'uuid' => 'nullable|string|uuid|unique:orders,uuid',
            // ...
        ];
    }
}
```

Three things to notice:

- **Override `getRules()`, not just `rules()`.** Composition happens at the static level. `parent::getRules()` returns the own rules merged with translation rules; you wrap that.
- **`Arr::prependKeysWith()` for namespacing.** A `PostalAddressRuleset` doesn't know it's being used to validate the `address_delivery.*` subtree; the ruleset that consumes it adds the prefix.
- **`resolve_static($ruleset, 'getRules')` rather than `$ruleset::getRules()`.** This goes through the container, which means downstream packages can swap in custom rulesets via container bindings — the same way they swap models.

## When to write a new ruleset

Three signs:

- The same set of rules appears in two or more actions (`CreateOrder` + `UpdateOrder` + `CreateOrderFromTemplate` all need the address subset).
- The action's `rules()` would be unwieldy in one place — pulling out a ruleset clarifies the structure.
- A downstream package needs to extend the rules. A ruleset class can be swapped via the container; an inline rules array can't.

If none of those apply, putting the rules directly on the action via `addRules()` is fine for one-off cases.

## Naming and location

```
src/Rulesets/
├── FluxRuleset.php
├── Address/
│   ├── CreateAddressRuleset.php
│   ├── UpdateAddressRuleset.php
│   ├── PostalAddressRuleset.php       ← reusable building block
│   └── ...
├── Order/
│   ├── CreateOrderRuleset.php
│   └── ...
└── ...
```

Convention: one file per ruleset, named `<Verb><Domain>Ruleset.php` (matching the action it primarily backs), in the domain subdirectory. Reusable cross-action rulesets get descriptive names like `PostalAddressRuleset`, `BankConnectionRuleset` — they aren't bound to a verb.

## The translation merging gotcha

Auto-merging attribute-translation rules is great when you want them, surprising when you don't. The interaction:

- A model with `HasAttributeTranslations` declares `attributeTranslationRules()` via the trait.
- A ruleset with `static::$model = SomeModel::class` and `static::$addTranslationRules = true` (the default) merges those rules in front of `rules()`.
- If your `rules()` redefines a key that the translation rules already covered, the explicit rule wins (it's later in the array merge).

If you want a ruleset that operates on a model but doesn't include the translation rules — for example, a partial-update ruleset where translations are managed separately — set `protected static bool $addTranslationRules = false;`.

## Custom rule classes

The `FluxErp\Rules\` namespace contains framework-specific rule classes:

- `ModelExists` — checks existence with morph-alias resolution.
- `ExistsWithForeign` — existence check with a tenant or other foreign-key constraint.
- `ValidStateRule` — checks Spatie ModelStates transitions.
- `Numeric` — locale-aware numeric coercion.

Use them in your `rules()` arrays the same way as Laravel's built-ins:

```php
public function rules(): array
{
    return [
        'order_type_id' => ['required', 'integer', new ModelExists(OrderType::class)],
    ];
}
```

## Per-call rule overrides

Two options on the action side, when a specific call site needs to layer rules on top of the rulesets:

```php
SendMail::make($data)
    ->mergeRules([
        'subject' => ['required', 'min:5'],     // overwrite per key
    ])
    ->validate()
    ->execute();

// or, append to existing rules per key:
SendMail::make($data)
    ->addRules([
        'cc' => ['array', 'max:5'],
    ])
    ->validate()
    ->execute();
```

`mergeRules()` does an `array_merge` — same-keyed entries are overwritten.
`addRules()` walks each key and appends, so `['cc' => 'array']` plus `addRules(['cc' => 'max:5'])` yields `['array', 'max:5']`.

Use these sparingly. If a rule is needed at most call sites, it belongs in a ruleset, not bolted on per call.

## Related

- [FluxAction](1-flux-action.md) — `getRulesets()`, `validate()`, `addRules`/`mergeRules`.
- [Models](../2-models/0-index.md) — `HasAttributeTranslations` and how `attributeTranslationRules()` is produced.

[Back to chapter](0-index.md) · [Back to index](../0-index.md)
