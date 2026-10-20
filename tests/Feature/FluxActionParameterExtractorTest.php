<?php

use FluxErp\Actions\ActionManager;
use FluxErp\Actions\EventSubscription\UpdateEventSubscription;
use Illuminate\Support\Facades\Log;
use TeamNiftyGmbH\FluxDevHelpers\Scramble\FluxActionParameterExtractor;

function validationRules(string $actionClass): array
{
    $extractor = (new ReflectionClass(FluxActionParameterExtractor::class))
        ->newInstanceWithoutConstructor();

    return (new ReflectionMethod($extractor, 'getValidationRules'))
        ->invoke($extractor, $actionClass);
}

it('builds rules for actions whose rulesets need an authenticated user', function (): void {
    expect(auth()->check())->toBeFalse()
        ->and(validationRules(UpdateEventSubscription::class))
        ->toHaveKeys(['id', 'channel', 'event'])
        ->and(auth()->check())->toBeFalse();
});

it('builds rules for every registered action without failing', function (): void {
    $failed = [];
    Log::listen(function ($message) use (&$failed): void {
        $failed[] = $message->message;
    });

    app(ActionManager::class)->all()
        ->pluck('class')
        ->each(fn (string $actionClass) => validationRules($actionClass));

    expect($failed)->toBe([]);
});
