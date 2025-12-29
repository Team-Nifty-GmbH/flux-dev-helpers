<?php

namespace TeamNiftyGmbH\FluxDevHelpers\Scramble;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use FluxErp\Actions\FluxAction;
use Illuminate\Support\Str;

class FluxActionOperationExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $actionClass = $this->getActionClass($routeInfo);

        if (! $actionClass || ! is_subclass_of($actionClass, FluxAction::class)) {
            return;
        }

        // Set operation ID and summary from action class
        $operation->operationId = $this->generateOperationId($actionClass);
        $operation->summary = $this->generateSummary($actionClass);
        $operation->description = $this->generateDescription($actionClass);

        // Note: Tags are set by the global tagResolver in FluxDevHelpersServiceProvider
        // Note: Request body parameters are handled by FluxActionParameterExtractor

        // Add response schema based on model
        $this->addResponseSchema($operation, $actionClass, $routeInfo);
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

    protected function generateOperationId(string $actionClass): string
    {
        $models = $actionClass::models();
        $modelName = ! empty($models) ? Str::camel(class_basename($models[0])) : '';
        $actionName = Str::camel(class_basename($actionClass));

        // Format: model.actionName (e.g., address.createAddress)
        if ($modelName) {
            return Str::lower($modelName) . '.' . $actionName;
        }

        return $actionName;
    }

    protected function generateSummary(string $actionClass): string
    {
        return Str::of(class_basename($actionClass))
            ->headline()
            ->toString();
    }

    protected function generateDescription(string $actionClass): string
    {
        $description = $actionClass::description();

        if ($description) {
            return ucfirst($description);
        }

        return $this->generateSummary($actionClass);
    }

    protected function addResponseSchema(Operation $operation, string $actionClass, RouteInfo $routeInfo): void
    {
        $method = $routeInfo->route->methods()[0] ?? 'GET';

        // Clear existing responses to replace with our custom ones
        // Keep only auth-related responses (401)
        $existingResponses = $operation->responses ?? [];
        $operation->responses = [];

        // Re-add auth responses
        foreach ($existingResponses as $response) {
            if ($response instanceof \Dedoc\Scramble\Support\Generator\Reference) {
                $operation->addResponse($response);
            }
        }

        // Success response
        $models = $actionClass::models();
        $responseSchema = new ObjectType();

        if ($method === 'DELETE') {
            // DELETE returns 204 No Content or status message
            $operation->addResponse(
                Response::make(204)->description('No Content')
            );
        } else {
            // Add standard response structure
            $responseSchema->addProperty('message', (new StringType())->example('success'));
            $responseSchema->addProperty('status', (new IntegerType())->example($method === 'POST' ? 201 : 200));

            if (! empty($models)) {
                $dataType = new ObjectType();
                $dataType->setDescription('The ' . class_basename($models[0]) . ' resource');
                $responseSchema->addProperty('data', $dataType);
            }

            $statusCode = $method === 'POST' ? 201 : 200;
            $operation->addResponse(
                Response::make($statusCode)
                    ->description('Successful operation')
                    ->setContent('application/json', Schema::fromType($responseSchema))
            );
        }

        // Error responses
        $this->addErrorResponses($operation);
    }

    protected function addErrorResponses(Operation $operation): void
    {
        // 422 Validation Error
        $validationError = new ObjectType();
        $validationError->addProperty('message', (new StringType())->example('validation failed'));
        $validationError->addProperty('status', (new IntegerType())->example(422));
        $validationError->addProperty('errors', new ObjectType());

        $operation->addResponse(
            Response::make(422)
                ->description('Validation Error')
                ->setContent('application/json', Schema::fromType($validationError))
        );

        // 403 Forbidden
        $forbiddenError = new ObjectType();
        $forbiddenError->addProperty('message', (new StringType())->example('forbidden'));
        $forbiddenError->addProperty('status', (new IntegerType())->example(403));

        $operation->addResponse(
            Response::make(403)
                ->description('Forbidden - insufficient permissions')
                ->setContent('application/json', Schema::fromType($forbiddenError))
        );
    }
}
