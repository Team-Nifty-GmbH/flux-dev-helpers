<?php

namespace TeamNiftyGmbH\FluxDevHelpers\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class PublishPintConfig extends Command
{
    protected $description = 'Publish pint.json from flux-core package to project root';

    protected $signature = 'flux-dev:publish-pint-config {--force : Overwrite existing pint.json}';

    public function handle(): int
    {
        $sourcePath = base_path('packages/flux-core/pint.json');
        $targetPath = base_path('pint.json');

        if (! File::exists($sourcePath)) {
            error(__('Source file not found: :path', ['path' => $sourcePath]));

            return Command::FAILURE;
        }

        if (File::exists($targetPath) && ! $this->option('force')) {
            warning(__('File pint.json already exists. Use --force to overwrite.'));

            return Command::FAILURE;
        }

        File::copy($sourcePath, $targetPath);

        info(__('pint.json published successfully to project root!'));

        return Command::SUCCESS;
    }
}
