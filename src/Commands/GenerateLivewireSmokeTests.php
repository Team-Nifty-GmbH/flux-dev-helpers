<?php

namespace TeamNiftyGmbH\FluxDevHelpers\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\Mechanisms\ComponentRegistry;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RegexIterator;
use Throwable;
use function Livewire\invade;

class GenerateLivewireSmokeTests extends Command
{
    protected $description = 'Generate Pest smoke tests for Livewire components';

    protected $signature = 'flux-dev:generate-livewire-smoke-tests {name?} {--all}';

    public function handle(): int
    {
        $this->registerLivewireComponents();

        if ($this->option('all') || ! $this->argument('name')) {
            $componentRegistry = invade(app(ComponentRegistry::class));
            collect($componentRegistry->aliases)->each(function ($class): void {
                if (str_starts_with($class, config('livewire.class_namespace'))) {
                    $class = Str::after($class, config('livewire.class_namespace') . '\\');
                    $this->call('flux-dev:generate-livewire-smoke-tests', ['name' => $class]);
                }
            });

            return Command::SUCCESS;
        }

        $componentName = $this->argument('name');
        $componentClass = $this->resolveComponentClass($componentName);

        if (! $componentClass) {
            $this->error("Component not found: {$componentName}");

            return Command::FAILURE;
        }

        $testPath = $this->getTestPath($componentName);

        if (File::exists($testPath)) {
            $this->line("<options=bold;fg=red>TEST ALREADY EXISTS:</> {$testPath}");

            return Command::FAILURE;
        }

        $this->ensureTestDirectoryExists($testPath);

        $stubPath = __DIR__ . '/../../stubs/livewire-smoke-test.stub';

        if (! File::exists($stubPath)) {
            $this->error("Stub file not found: {$stubPath}");

            return Command::FAILURE;
        }

        $stub = File::get($stubPath);
        $stub = str_replace('{{ componentClass }}', $componentClass, $stub);

        File::put($testPath, $stub);

        $this->line("<options=bold;fg=green>TEST:</> {$testPath}");

        return Command::SUCCESS;
    }

    protected function ensureTestDirectoryExists(string $path): void
    {
        $directory = dirname($path);

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }
    }

    protected function getTestPath(string $componentName): string
    {
        $testName = str_replace('.', '/', Str::studly($componentName)) . 'Test.php';

        return base_path('tests/Feature/Livewire/' . $testName);
    }

    protected function getViewClassAliasFromNamespace(string $namespace, ?string $directoryPath = null): array
    {
        $directoryPath = $directoryPath ?: app_path('Livewire');
        $directoryIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directoryPath));
        $phpFiles = new RegexIterator($directoryIterator, '/\.php$/');
        $components = [];

        foreach ($phpFiles as $phpFile) {
            $relativePath = Str::replace($directoryPath, '', $phpFile->getRealPath());
            $relativePath = Str::replace(DIRECTORY_SEPARATOR, '\\', $relativePath);
            $class = $namespace . str_replace(
                '/',
                '\\',
                pathinfo($relativePath, PATHINFO_FILENAME)
            );

            if (class_exists($class)) {
                $exploded = explode('\\', $relativePath);
                array_walk($exploded, function (&$value): void {
                    $value = Str::snake(Str::remove('.php', $value), '-');
                });

                $alias = ltrim(implode('.', $exploded), '.');
                $components[$alias] = $class;
            }
        }

        return $components;
    }

    protected function registerLivewireComponents(): void
    {
        $livewireNamespace = config('livewire.class_namespace');

        foreach ($this->getViewClassAliasFromNamespace($livewireNamespace) as $alias => $class) {
            try {
                if (is_a($class, Component::class, true)
                    && ! (new ReflectionClass($class))->isAbstract()
                ) {
                    Livewire::component($alias, $class);
                }
            } catch (Throwable) {
            }
        }
    }

    protected function resolveComponentClass(string $componentName): ?string
    {
        $namespace = config('livewire.class_namespace');
        $class = $namespace . '\\' . str_replace('.', '\\', Str::studly($componentName));

        return class_exists($class) ? $class : null;
    }
}
