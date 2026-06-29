<?php

namespace TeamNiftyGmbH\FluxDevHelpers\Scramble\Rules;

use Dedoc\Scramble\Contracts\RuleTransformer;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\RuleTransforming\NormalizedRule;
use Dedoc\Scramble\Support\RuleTransforming\RuleTransformerContext;
use FluxErp\Rules\ValidStateRule;
use ReflectionProperty;
use Spatie\ModelStates\State;
use Throwable;

/**
 * Surfaces the allowed state values of a FluxErp ValidStateRule as an enum on
 * the generated parameter schema.
 */
class ValidStateRuleTransformer implements RuleTransformer
{
    public function shouldHandle(NormalizedRule $rule): bool
    {
        return $rule->is(ValidStateRule::class);
    }

    public function toSchema(Type $previous, NormalizedRule $rule, RuleTransformerContext $context): Type
    {
        $states = $this->resolveStates($rule->getRule());

        $type = new StringType;

        return $states ? $type->enum($states) : $type;
    }

    /**
     * @return list<string>
     */
    protected function resolveStates(object|string $rule): array
    {
        if (! is_object($rule)) {
            return [];
        }

        try {
            // baseStateClass is private on Spatie's ValidStateRule.
            $property = new ReflectionProperty(\Spatie\ModelStates\Validation\ValidStateRule::class, 'baseStateClass');
            $property->setAccessible(true);

            /** @var class-string<State> $stateClass */
            $stateClass = $property->getValue($rule);

            return $stateClass::getStateMapping()->keys()->all();
        } catch (Throwable) {
            return [];
        }
    }
}
