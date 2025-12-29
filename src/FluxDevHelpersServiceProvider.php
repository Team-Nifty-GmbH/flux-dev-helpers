<?php

namespace TeamNiftyGmbH\FluxDevHelpers;

use Dedoc\Scramble\Configuration\OperationTransformers;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\RouteInfo;
use FluxErp\Actions\FluxAction;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use TeamNiftyGmbH\FluxDevHelpers\Commands\GenerateLivewireSmokeTests;
use TeamNiftyGmbH\FluxDevHelpers\Commands\MakeFluxDataTableCommand;
use TeamNiftyGmbH\FluxDevHelpers\Commands\MakeFluxModelCommand;
use TeamNiftyGmbH\FluxDevHelpers\Commands\MakeModelCommand;
use TeamNiftyGmbH\FluxDevHelpers\Commands\PublishPintConfig;
use TeamNiftyGmbH\FluxDevHelpers\Commands\SetupTests;
use TeamNiftyGmbH\FluxDevHelpers\Commands\UpdateFromRemote;
use TeamNiftyGmbH\FluxDevHelpers\Scramble\FluxActionOperationExtension;
use TeamNiftyGmbH\FluxDevHelpers\Scramble\FluxActionParameterExtractor;

class FluxDevHelpersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureScramble();
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/flux-dev-helpers.php',
            'flux-dev-helpers'
        );

        $this->commands([
            GenerateLivewireSmokeTests::class,
            MakeModelCommand::class,
            MakeFluxModelCommand::class,
            MakeFluxDataTableCommand::class,
            UpdateFromRemote::class,
            PublishPintConfig::class,
            SetupTests::class,
        ]);

        $this->offerPublishing();
    }

    protected function configureScramble(): void
    {
        if (! class_exists(Scramble::class)) {
            return;
        }

        // Set a custom tag resolver to group by model
        Scramble::resolveTagsUsing(function (RouteInfo $routeInfo, Operation $operation): array {
            // Check if this is a FluxAction
            $uses = $routeInfo->route->getAction('uses');
            if (is_string($uses)) {
                // Handle "Class@method" format (e.g., "UpdateAddress@__invoke")
                if (str_contains($uses, '@')) {
                    $uses = explode('@', $uses)[0];
                }
                if (class_exists($uses) && is_subclass_of($uses, FluxAction::class)) {
                    $models = $uses::models();
                    if (! empty($models)) {
                        return [class_basename($models[0])];
                    }
                }
            }

            // Check for model default (BaseController routes)
            $model = $routeInfo->route->getAction('model');
            if ($model && class_exists($model)) {
                return [class_basename($model)];
            }

            // Fallback: extract from URI (e.g., /api/addresses -> Address)
            $uri = $routeInfo->route->uri();
            if (preg_match('#^api/([^/\{]+)#', $uri, $matches)) {
                return [Str::studly(Str::singular($matches[1]))];
            }

            return ['General'];
        });

        Scramble::configure()
            ->routes(function (Route $route) {
                return Str::startsWith($route->uri, 'api/');
            })
            ->withParametersExtractors(function ($extractors): void {
                $extractors->prepend(FluxActionParameterExtractor::class);
            })
            ->withOperationTransformers(function (OperationTransformers $transformers): void {
                // Add our FluxAction extension to customize operation info and responses
                $transformers->append(FluxActionOperationExtension::class);
            })
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->info->title = config('app.name', 'Nuxbe ERP') . ' API';
                $openApi->info->version = config('app.version', '1.0.0');
                $openApi->info->description = 'API documentation for Nuxbe ERP. All endpoints require authentication via Bearer token (Laravel Sanctum).';

                // Clear default servers and add the correct API server
                $openApi->servers = [];
                $openApi->addServer(
                    Server::make(url('/api'))
                        ->setDescription('API Server')
                );

                // Add Bearer authentication
                $openApi->secure(
                    SecurityScheme::http('bearer', 'JWT')
                );
            });
    }

    protected function offerPublishing(): void
    {
        $this->publishes([
            __DIR__ . '/../stubs/laravel.yml' => base_path('.github/workflows/laravel.yml'),
        ], 'flux-dev-helpers-laravel-workflow');
    }
}
