<?php

namespace TeamNiftyGmbH\FluxDevHelpers\Scramble\Rules;

use Dedoc\Scramble\Contracts\RuleTransformer;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\RuleTransforming\NormalizedRule;
use Dedoc\Scramble\Support\RuleTransforming\RuleTransformerContext;
use FluxErp\Rules\ModelExists;
use Throwable;

/**
 * Notes on the parameter schema that a FluxErp ModelExists rule expects the
 * value to reference an existing record of the related model.
 */
class ModelExistsRuleTransformer implements RuleTransformer
{
    public function shouldHandle(NormalizedRule $rule): bool
    {
        return $rule->is(ModelExists::class);
    }

    public function toSchema(Type $previous, NormalizedRule $rule, RuleTransformerContext $context): Type
    {
        $modelName = $this->resolveModelName($rule->getRule());

        if ($modelName) {
            $previous->setDescription(
                trim($previous->description.' Must reference an existing '.$modelName.'.')
            );
        }

        return $previous;
    }

    protected function resolveModelName(object|string $rule): ?string
    {
        if (! is_object($rule)) {
            return null;
        }

        try {
            // ModelExists extends Eloquent\Builder.
            return class_basename($rule->getModel());
        } catch (Throwable) {
            return null;
        }
    }
}
