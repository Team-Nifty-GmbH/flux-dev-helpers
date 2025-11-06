<?php

namespace TeamNiftyGmbH\FluxDevHelpers\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class SetupTests extends Command
{
    protected $description = 'Setup test environment with Pest, GitHub Actions workflow, and test directories';

    protected $signature = 'flux-dev:setup-tests {--force : Overwrite existing files}';

    protected array $testDirectories = [
        'tests/Browser',
        'tests/Feature',
        'tests/Livewire',
        'tests/Unit',
    ];

    public function handle(): int
    {
        info(__('Setting up test environment...'));

        if (! $this->publishWorkflowFile()) {
            return Command::FAILURE;
        }

        $this->createTestDirectories();
        $this->installPest();

        info(__('Test setup completed successfully!'));

        return Command::SUCCESS;
    }

    protected function createTestDirectories(): void
    {
        foreach ($this->testDirectories as $directory) {
            $path = base_path($directory);
            $gitkeepPath = $path . '/.gitkeep';

            File::ensureDirectoryExists($path);

            if (! File::exists($gitkeepPath)) {
                File::put($gitkeepPath, '');
                info(__('Created directory with .gitkeep: :path', ['path' => $directory]));
            } else {
                info(__('Directory already exists: :path', ['path' => $directory]));
            }
        }
    }

    protected function installPest(): void
    {
        $result = spin(
            fn () => Process::timeout(300)->run('composer require --dev pestphp/pest --with-all-dependencies'),
            __('Installing Pest with all dependencies...')
        );

        if ($result->failed()) {
            error(__('Failed to install Pest'));
            error($result->errorOutput());
        } else {
            info(__('Pest installed successfully!'));
        }
    }

    protected function publishWorkflowFile(): bool
    {
        $sourcePath = __DIR__ . '/../../stubs/laravel.yml';
        $targetPath = base_path('.github/workflows/tests.yml');

        if (! File::exists($sourcePath)) {
            error(__('Source file not found: :path', ['path' => $sourcePath]));

            return false;
        }

        if (File::exists($targetPath) && ! $this->option('force')) {
            warning(__('File .github/workflows/tests.yml already exists. Use --force to overwrite.'));

            return false;
        }

        // Create .github/workflows directory if it doesn't exist
        File::ensureDirectoryExists(dirname($targetPath));

        File::copy($sourcePath, $targetPath);

        info(__('GitHub Actions workflow published to .github/workflows/tests.yml'));

        return true;
    }
}
