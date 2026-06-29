<?php

namespace TeamNiftyGmbH\FluxDevHelpers\Scramble;

use Dedoc\Scramble\Support\RouteInfo;
use FluxErp\Actions\FluxAction;
use Illuminate\Routing\Route;

class FluxActionRoute
{
    /**
     * Resolve the controller/action class backing a route, or null when the
     * route is a closure or its class does not exist.
     */
    public static function controllerClass(RouteInfo $routeInfo): ?string
    {
        $uses = $routeInfo->route->getAction('uses');

        if (! is_string($uses)) {
            return null;
        }

        // Handle "Class@method" format (e.g., "UpdateAddress@__invoke")
        if (str_contains($uses, '@')) {
            $uses = explode('@', $uses)[0];
        }

        return class_exists($uses) ? $uses : null;
    }

    /**
     * Resolve the FluxAction class backing a route, or null when it is not one.
     */
    public static function fluxActionClass(RouteInfo $routeInfo): ?string
    {
        $class = static::controllerClass($routeInfo);

        return $class && is_subclass_of($class, FluxAction::class) ? $class : null;
    }

    public static function method(RouteInfo $routeInfo): ?string
    {
        $uses = $routeInfo->route->getAction('uses');

        return is_string($uses) && str_contains($uses, '@')
            ? explode('@', $uses)[1]
            : null;
    }

    /**
     * The model class a BaseController route was bound to via ->defaults('model', ...).
     */
    public static function defaultModel(Route $route): ?string
    {
        $model = $route->defaults['model'] ?? $route->getAction('model');

        return is_string($model) && class_exists($model) ? $model : null;
    }
}
