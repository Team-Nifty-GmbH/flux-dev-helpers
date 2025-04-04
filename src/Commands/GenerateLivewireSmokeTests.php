<?php

namespace TeamNiftyGmbH\FluxDevHelpers\Commands;

use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Features\SupportConsoleCommands\Commands\ComponentParser;
use Livewire\Features\SupportConsoleCommands\Commands\MakeLivewireCommand;
use Livewire\Livewire;
use Livewire\Mechanisms\ComponentRegistry;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RegexIterator;
use Throwable;
use function Livewire\invade;

class GenerateLivewireSmokeTests extends MakeLivewireCommand
{
    protected $description = 'Command description';

    protected $signature = 'flux-dev:generate-livewire-smoke-tests {name?} {--all} {--stub}';

    public function handle(): void
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

            return;
        }

        $this->parser = new ComponentParser(
            config('livewire.class_namespace'),
            config('livewire.view_path'),
            $this->argument('name'),
            $this->option('stub')
        );

        $test = $this->createTest();

        if ($test) {
            $test && $this->line("<options=bold;fg=green>TEST:</>  {$this->parser->relativeTestPath()}");
        }
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
}
