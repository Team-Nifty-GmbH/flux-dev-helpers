<?php

namespace TeamNiftyGmbH\FluxDevHelpers\Scramble;

use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\OperationExtensions\ParameterExtractor\ParameterExtractor;
use Dedoc\Scramble\Support\OperationExtensions\RequestBodyExtension;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\GeneratesParametersFromRules;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\ParametersExtractionResult;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Arr;
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
        $actionClass = FluxActionRoute::fluxActionClass($routeInfo);

        if (! $actionClass) {
            return $parameterExtractionResults;
        }

        $rules = $this->getValidationRules($actionClass);

        // Path parameters (e.g. {id}) are already extracted from the URI; drop
        // them from the rule set so they are not duplicated into query/body.
        $rules = Arr::except($rules, $routeInfo->route->parameterNames());

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

    protected function getValidationRules(string $actionClass): array
    {
        try {
            return $actionClass::make([])
                ->setRulesFromRulesets()
                ->getRules();
        } catch (Throwable $e) {
            logger()->error('FluxActionParameterExtractor: Failed to get rules for '.$actionClass, [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
