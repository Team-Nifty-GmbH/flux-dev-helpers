<?php

use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\RouteInfo;
use FluxErp\Http\Controllers\AuthController;
use FluxErp\Http\Controllers\BaseController;
use FluxErp\Models\AbsencePolicy;
use Illuminate\Routing\Route;
use TeamNiftyGmbH\FluxDevHelpers\Scramble\FluxActionOperationExtension;

function handleRoute(Route $route, string $method): Operation
{
    $extension = (new ReflectionClass(FluxActionOperationExtension::class))
        ->newInstanceWithoutConstructor();

    $routeInfo = (new ReflectionClass(RouteInfo::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(RouteInfo::class, 'route'))->setValue($routeInfo, $route);

    $operation = new Operation($method);
    $extension->handle($operation, $routeInfo);

    return $operation;
}

function paramNames(Operation $operation): array
{
    return array_map(fn ($parameter) => $parameter->name, $operation->parameters);
}

function responseCodes(Operation $operation): array
{
    return array_map(fn ($response) => $response->code, $operation->responses);
}

it('sets operation id, summary and query params for BaseController index routes', function (): void {
    $route = (new Route(['GET'], '/api/absence-policies', [BaseController::class, 'index']))
        ->defaults('model', AbsencePolicy::class);

    $operation = handleRoute($route, 'get');

    expect($operation->operationId)->toBe('absencepolicy.index')
        ->and($operation->summary)->toBe('List Absence Policies')
        ->and(paramNames($operation))->toBe(['page', 'per_page', 'search', 'filter', 'sort', 'include'])
        ->and(responseCodes($operation))->toContain(200);
});

it('sets operation id, include param and responses for BaseController show routes', function (): void {
    $route = (new Route(['GET'], '/api/absence-policies/{id}', [BaseController::class, 'show']))
        ->defaults('model', AbsencePolicy::class);

    $operation = handleRoute($route, 'get');

    expect($operation->operationId)->toBe('absencepolicy.show')
        ->and($operation->summary)->toBe('Show Absence Policy')
        ->and(paramNames($operation))->toBe(['include'])
        ->and(responseCodes($operation))->toContain(200)
        ->and(responseCodes($operation))->toContain(404);
});

it('derives a stable id and summary for non-CRUD controller routes', function (): void {
    $route = new Route(['POST'], '/api/login', [AuthController::class, 'login']);

    $operation = handleRoute($route, 'post');

    expect($operation->operationId)->toBe('auth.login')
        ->and($operation->summary)->toBe('Login Auth');
});
