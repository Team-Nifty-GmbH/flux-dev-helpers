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

it('ships the developer docs with every chapter populated', function (): void {
    $docsRoot = __DIR__.'/../../docs/en';

    expect(is_dir($docsRoot))->toBeTrue();
    expect(is_file($docsRoot.'/0-index.md'))->toBeTrue();
    expect(is_file($docsRoot.'/9-helpers.md'))->toBeTrue();
    expect(is_file($docsRoot.'/10-extending-the-frontend.md'))->toBeTrue();

    $chapters = [
        '1-getting-started' => [
            '0-index.md',
            '1-conventions.md',
            '2-anatomy-of-a-feature.md',
        ],
        '2-models' => [
            '0-index.md',
            '1-flux-model.md',
            '2-model-traits.md',
            '3-states.md',
        ],
        '3-actions' => [
            '0-index.md',
            '1-flux-action.md',
            '2-rulesets.md',
            '3-dispatchable-actions.md',
            '4-permissions.md',
        ],
        '4-livewire' => [
            '0-index.md',
            '1-form-objects.md',
            '2-data-tables.md',
            '3-widgets.md',
            '4-tallstackui-conventions.md',
        ],
        '5-notifications' => [
            '0-index.md',
            '1-notification-base.md',
            '2-toast-notifications.md',
            '3-notification-actions.md',
        ],
        '6-queue-monitoring' => [
            '0-index.md',
            '1-monitored-jobs.md',
            '2-monitored-batches.md',
            '3-progress-and-toasts.md',
            '4-recipes.md',
        ],
        '7-events-and-broadcasting' => [
            '0-index.md',
            '1-broadcast-channels.md',
            '2-broadcast-now.md',
        ],
        '8-routes-and-search' => [
            '0-index.md',
            '1-base-controller.md',
            '2-search-endpoint.md',
        ],
    ];

    foreach ($chapters as $chapter => $files) {
        foreach ($files as $file) {
            expect(is_file($docsRoot.'/'.$chapter.'/'.$file))
                ->toBeTrue("Missing developer doc: {$chapter}/{$file}");
        }
    }
});
