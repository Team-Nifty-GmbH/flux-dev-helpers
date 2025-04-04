<?php

namespace TeamNiftyGmbH\FluxDevHelpers;

use Illuminate\Support\ServiceProvider;
use TeamNiftyGmbH\FluxDevHelpers\Commands\FixOrderPositionSort;
use TeamNiftyGmbH\FluxDevHelpers\Commands\GenerateLivewireSmokeTests;
use TeamNiftyGmbH\FluxDevHelpers\Commands\MakeModelCommand;

class FluxDevHelpersServiceProvider extends ServiceProvider
{
    public function boot(): void {}

    public function register(): void
    {
        $this->commands([
            GenerateLivewireSmokeTests::class,
            MakeModelCommand::class,
            FixOrderPositionSort::class,
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
