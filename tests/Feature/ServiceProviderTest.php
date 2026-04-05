<?php

use Illuminate\Support\Facades\Artisan;
use TeamNiftyGmbH\FluxDevHelpers\FluxDevHelpersServiceProvider;

it('boots the service provider', function (): void {
    expect(app()->getProviders(FluxDevHelpersServiceProvider::class))
        ->toHaveCount(1);
});

it('merges the config', function (): void {
    expect(config('flux-dev-helpers'))
        ->toBeArray()
        ->toHaveKey('remote_servers');
});

it('registers the commands', function (): void {
    $commands = Artisan::all();

    expect($commands)
        ->toHaveKey('flux-dev:generate-livewire-smoke-tests')
        ->toHaveKey('flux-dev:make-model')
        ->toHaveKey('flux-dev:make-flux-model')
        ->toHaveKey('flux-dev:make-flux-data-table')
        ->toHaveKey('flux-dev:update-from-remote')
        ->toHaveKey('flux-dev:publish-pint-config')
        ->toHaveKey('flux-dev:setup-tests');
});
