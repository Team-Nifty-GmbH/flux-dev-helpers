<?php

use Illuminate\Support\Facades\Process;
use Illuminate\Support\ServiceProvider;

it('is the command hooked into optimize', function (): void {
    expect(ServiceProvider::$optimizeCommands)
        ->toContain('flux-dev:cache-api-docs')
        ->and(ServiceProvider::$optimizeClearCommands)
        ->toContain('scramble:clear');
});

it('runs scramble:cache in a separate process', function (): void {
    Process::fake();

    $this->artisan('flux-dev:cache-api-docs')
        ->assertSuccessful();

    Process::assertRan(fn ($process) => in_array('scramble:cache', $process->command));
});

it('fails when the separate process fails', function (): void {
    Process::fake([
        '*' => Process::result(output: 'boom', exitCode: 1),
    ]);

    $this->artisan('flux-dev:cache-api-docs')
        ->assertFailed();
});
