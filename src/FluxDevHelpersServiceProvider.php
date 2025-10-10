<?php

namespace TeamNiftyGmbH\FluxDevHelpers;

use Illuminate\Support\ServiceProvider;
use TeamNiftyGmbH\FluxDevHelpers\Commands\FixOrderPositionSort;
use TeamNiftyGmbH\FluxDevHelpers\Commands\GenerateLivewireSmokeTests;
use TeamNiftyGmbH\FluxDevHelpers\Commands\MakeFluxDataTableCommand;
use TeamNiftyGmbH\FluxDevHelpers\Commands\MakeFluxModelCommand;
use TeamNiftyGmbH\FluxDevHelpers\Commands\MakeModelCommand;
use TeamNiftyGmbH\FluxDevHelpers\Commands\PublishPintConfig;
use TeamNiftyGmbH\FluxDevHelpers\Commands\UpdateFromRemote;

class FluxDevHelpersServiceProvider extends ServiceProvider
{
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
            FixOrderPositionSort::class,
            UpdateFromRemote::class,
            PublishPintConfig::class,
        ]);

        $this->offerPublishing();
    }

    protected function offerPublishing(): void
    {
        $this->publishes([
            __DIR__ . '/../stubs/laravel.yml' => base_path('.github/workflows/laravel.yml'),
        ], 'flux-dev-helpers-laravel-workflow');
    }
}
