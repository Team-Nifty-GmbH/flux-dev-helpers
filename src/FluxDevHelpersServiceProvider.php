<?php

namespace TeamNiftyGmbH\FluxDevHelpers;

use Illuminate\Support\ServiceProvider;
use TeamNiftyGmbH\FluxDevHelpers\Commands\GenerateLivewireSmokeTests;

class FluxDevHelpersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->commands([
            GenerateLivewireSmokeTests::class,
        ]);

        $this->offerPublishing();
    }

    public function boot(): void {}

    protected function offerPublishing(): void
    {
        $this->publishes([
            __DIR__ . '/../stubs/laravel.yml' => base_path('.github/workflows/laravel.yml'),
        ], 'flux-dev-helpers-laravel-workflow');
    }
}
