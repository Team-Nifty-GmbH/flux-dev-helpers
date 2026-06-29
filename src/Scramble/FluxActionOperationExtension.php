<?php

namespace TeamNiftyGmbH\FluxDevHelpers\Scramble;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use FluxErp\Actions\Media\DownloadMedia;
use FluxErp\Actions\Media\DownloadMultipleMedia;
use Illuminate\Support\Str;

class FluxActionOperationExtension extends OperationExtension
{
    /**
     * FluxAction classes that stream a file instead of returning the JSON envelope.
     */
    protected const FILE_DOWNLOAD_ACTIONS = [
        DownloadMedia::class,
        DownloadMultipleMedia::class,
    ];

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $actionClass = FluxActionRoute::fluxActionClass($routeInfo);

        if (! $actionClass) {
            $this->handleNonFluxActionRoute($operation, $routeInfo);

            return;
        }

        $operation->operationId = $this->generateOperationId($actionClass);
        $operation->summary = $this->generateSummary($actionClass);

        if ($description = $this->generateDescription($actionClass)) {
            $operation->description = $description;
        }

        // Note: Tags are set by the global tagResolver in FluxDevHelpersServiceProvider
        // Note: Request body parameters are handled by FluxActionParameterExtractor

        $this->addResponseSchema($operation, $actionClass, $routeInfo);
    }

    protected function handleNonFluxActionRoute(Operation $operation, RouteInfo $routeInfo): void
    {
        $model = FluxActionRoute::defaultModel($routeInfo->route);
        $method = FluxActionRoute::method($routeInfo);

        // BaseController index/show CRUD endpoints carry a ->defaults('model', ...).
        if ($model && in_array($method, ['index', 'show'], true)) {
            $this->handleBaseControllerRoute($operation, $model, $method);

            return;
        }

        // Any other controller route (auth, settings, print, ...) — give it a
        // stable id/summary instead of Scramble's "fluxErp.base.show_0" default.
        $this->handleFallbackRoute($operation, $routeInfo, $method);
    }

    protected function handleBaseControllerRoute(Operation $operation, string $model, string $method): void
    {
        $modelName = class_basename($model);
        $idPrefix = Str::lower($modelName);
        $modelLabel = Str::headline($modelName);

        if ($method === 'index') {
            $operation->operationId = $idPrefix.'.index';
            $operation->summary = 'List '.Str::plural($modelLabel);
            $operation->description = 'Get a paginated list of '.$modelLabel.' resources.';
            $operation->addParameters($this->indexQueryParameters());
            $operation->addResponse($this->listResponse($modelName));
        } else {
            $operation->operationId = $idPrefix.'.show';
            $operation->summary = 'Show '.$modelLabel;
            $operation->description = 'Get a single '.$modelLabel.' resource by ID.';
            $operation->addParameters([
                Parameter::make('include', 'query')
                    ->setSchema(Schema::fromType(new StringType))
                    ->description('Comma-separated list of relations to eager load.'),
            ]);
            $operation->addResponse($this->resourceResponse(200, $modelName, 'Successful operation'));
            $operation->addResponse($this->notFoundResponse());
        }
    }

    protected function handleFallbackRoute(Operation $operation, RouteInfo $routeInfo, ?string $method): void
    {
        $controller = FluxActionRoute::controllerClass($routeInfo);

        if ($controller && $method) {
            $resource = Str::replaceLast('Controller', '', class_basename($controller));
            $operation->operationId = Str::lcfirst($resource).'.'.$method;
            $operation->summary = Str::headline($method).' '.Str::headline($resource);

            return;
        }

        // Closure route — fall back to the URI.
        $uri = trim(Str::after($routeInfo->route->uri(), 'api/'), '/');

        if ($uri !== '') {
            $operation->summary = Str::headline(str_replace(['/', '{', '}'], ' ', $uri));
        }
    }

    protected function generateOperationId(string $actionClass): string
    {
        $models = $actionClass::models();
        $actionName = Str::camel(class_basename($actionClass));

        if (! empty($models)) {
            return Str::lower(class_basename($models[0])).'.'.$actionName;
        }

        return $actionName;
    }

    protected function generateSummary(string $actionClass): string
    {
        return Str::headline(class_basename($actionClass));
    }

    protected function generateDescription(string $actionClass): ?string
    {
        $description = $actionClass::description();

        // FluxAction::description() defaults to the (snake-cased) class name; when
        // an action does not override it, the result just echoes the summary.
        if (! $description) {
            return null;
        }

        return ucfirst($description);
    }

    protected function addResponseSchema(Operation $operation, string $actionClass, RouteInfo $routeInfo): void
    {
        $method = $routeInfo->route->methods()[0] ?? 'GET';

        // Clear existing responses to replace with our custom ones, keeping only
        // auth-related references (401).
        $existingResponses = $operation->responses ?? [];
        $operation->responses = [];

        foreach ($existingResponses as $response) {
            if ($response instanceof Reference) {
                $operation->addResponse($response);
            }
        }

        if (in_array($actionClass, static::FILE_DOWNLOAD_ACTIONS, true)) {
            $operation->addResponse(
                Response::make(200)
                    ->description('File download')
                    ->setContent('application/octet-stream', Schema::fromType((new StringType)->format('binary')))
            );
            $this->addErrorResponses($operation);

            return;
        }

        if ($method === 'DELETE') {
            $operation->addResponse(
                Response::make(204)->description('No Content')
            );
        } else {
            $models = $actionClass::models();
            $statusCode = $method === 'POST' ? 201 : 200;

            $operation->addResponse(
                $this->resourceResponse(
                    $statusCode,
                    ! empty($models) ? class_basename($models[0]) : null,
                    'Successful operation'
                )
            );
        }

        $this->addErrorResponses($operation);
    }

    /**
     * The standard `{status, data}` success envelope for a single resource.
     */
    protected function resourceResponse(int $statusCode, ?string $modelName, string $description): Response
    {
        $schema = new ObjectType;
        $schema->addProperty('status', (new IntegerType)->example($statusCode));

        $dataType = new ObjectType;

        if ($modelName) {
            $dataType->setDescription('The '.$modelName.' resource');
        }

        $schema->addProperty('data', $dataType);

        return Response::make($statusCode)
            ->description($description)
            ->setContent('application/json', Schema::fromType($schema));
    }

    /**
     * The paginated `{status, data: [...]}` envelope for index endpoints.
     */
    protected function listResponse(string $modelName): Response
    {
        $schema = new ObjectType;
        $schema->addProperty('status', (new IntegerType)->example(200));

        $item = new ObjectType;
        $item->setDescription('A '.$modelName.' resource');

        $data = new ArrayType;
        $data->setItems($item);

        $schema->addProperty('data', $data);

        return Response::make(200)
            ->description('Paginated list of '.$modelName.' resources')
            ->setContent('application/json', Schema::fromType($schema));
    }

    /**
     * @return Parameter[]
     */
    protected function indexQueryParameters(): array
    {
        return [
            Parameter::make('page', 'query')
                ->setSchema(Schema::fromType((new IntegerType)->default(1)))
                ->description('Page number.'),
            Parameter::make('per_page', 'query')
                ->setSchema(Schema::fromType((new IntegerType)->default(25)))
                ->description('Results per page (1-500, defaults to 25).'),
            Parameter::make('search', 'query')
                ->setSchema(Schema::fromType(new StringType))
                ->description('Full-text search term (only on searchable models).'),
            Parameter::make('filter', 'query')
                ->setSchema(Schema::fromType(new StringType))
                ->description('Filter expression.'),
            Parameter::make('sort', 'query')
                ->setSchema(Schema::fromType(new StringType))
                ->description('Sort column, prefix with "-" for descending.'),
            Parameter::make('include', 'query')
                ->setSchema(Schema::fromType(new StringType))
                ->description('Comma-separated list of relations to eager load.'),
        ];
    }

    protected function notFoundResponse(): Response
    {
        $schema = new ObjectType;
        $schema->addProperty('status', (new IntegerType)->example(404));
        $schema->addProperty('errors', new ObjectType);

        return Response::make(404)
            ->description('Not Found')
            ->setContent('application/json', Schema::fromType($schema));
    }

    protected function addErrorResponses(Operation $operation): void
    {
        // 422 Validation Error
        $validationError = new ObjectType;
        $validationError->addProperty('status', (new IntegerType)->example(422));
        $validationError->addProperty('errors', new ObjectType);

        $operation->addResponse(
            Response::make(422)
                ->description('Validation Error')
                ->setContent('application/json', Schema::fromType($validationError))
        );

        // 403 Forbidden
        $forbiddenError = new ObjectType;
        $forbiddenError->addProperty('status', (new IntegerType)->example(403));
        $forbiddenError->addProperty('errors', (new StringType)->example('forbidden'));

        $operation->addResponse(
            Response::make(403)
                ->description('Forbidden - insufficient permissions')
                ->setContent('application/json', Schema::fromType($forbiddenError))
        );
    }
}
