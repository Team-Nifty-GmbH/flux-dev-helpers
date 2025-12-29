<?php

namespace TeamNiftyGmbH\FluxDevHelpers\Scramble;

use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\OperationExtensions\ParameterExtractor\ParameterExtractor;
use Dedoc\Scramble\Support\OperationExtensions\RequestBodyExtension;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\GeneratesParametersFromRules;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\ParametersExtractionResult;
use Dedoc\Scramble\Support\RouteInfo;
use FluxErp\Actions\FluxAction;
use Illuminate\Support\Arr;
use ReflectionClass;
use Throwable;

class FluxActionParameterExtractor implements ParameterExtractor
{
    use GeneratesParametersFromRules;

    public function __construct(
        private TypeTransformer $openApiTransformer,
    ) {}

    /**
     * @param  ParametersExtractionResult[]  $parameterExtractionResults
     * @return ParametersExtractionResult[]
     */
    public function handle(RouteInfo $routeInfo, array $parameterExtractionResults): array
    {
        $actionClass = $this->getActionClass($routeInfo);

        if (! $actionClass || ! is_subclass_of($actionClass, FluxAction::class)) {
            return $parameterExtractionResults;
        }

        $rules = $this->getValidationRules($actionClass);

        if (empty($rules)) {
            return $parameterExtractionResults;
        }

        $method = mb_strtolower($routeInfo->route->methods()[0] ?? 'GET');
        $in = in_array($method, RequestBodyExtension::HTTP_METHODS_WITHOUT_REQUEST_BODY)
            ? 'query'
            : 'body';

        $parameterExtractionResults[] = new ParametersExtractionResult(
            parameters: $this->makeParameters(
                rules: $rules,
                typeTransformer: $this->openApiTransformer,
                rulesDocsRetriever: [],
                in: $in,
            ),
        );

        return $parameterExtractionResults;
    }

    protected function getActionClass(RouteInfo $routeInfo): ?string
    {
        $uses = $routeInfo->route->getAction('uses');

        if (! is_string($uses)) {
            return null;
        }

        // Handle "Class@method" format (e.g., "UpdateAddress@__invoke")
        if (str_contains($uses, '@')) {
            $uses = explode('@', $uses)[0];
        }

        if (class_exists($uses)) {
            return $uses;
        }

        return null;
    }

    protected function getValidationRules(string $actionClass): array
    {
        try {
            $action = new $actionClass([]);
            $reflection = new ReflectionClass($action);

            // Get rulesets from the action
            if ($reflection->hasMethod('getRulesets')) {
                $method = $reflection->getMethod('getRulesets');
                $method->setAccessible(true);
                $rulesets = $method->invoke($action);

                // Handle both single string and array of rulesets
                $rulesets = Arr::wrap($rulesets);
                $rules = [];

                foreach ($rulesets as $ruleset) {
                    if (is_string($ruleset) && class_exists($ruleset)) {
                        // Call getRules() statically on the ruleset class
                        if (method_exists($ruleset, 'getRules')) {
                            $rulesetRules = $ruleset::getRules();
                            $rules = array_merge($rules, $rulesetRules);
                        } elseif (method_exists($ruleset, 'rules')) {
                            $rulesetRules = (new $ruleset())->rules();
                            $rules = array_merge($rules, $rulesetRules);
                        }
                    }
                }

                return $rules;
            }
        } catch (Throwable $e) {
            // Log error for debugging
            logger()->error('FluxActionParameterExtractor: Failed to get rules for ' . $actionClass, [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return [];
    }
}
