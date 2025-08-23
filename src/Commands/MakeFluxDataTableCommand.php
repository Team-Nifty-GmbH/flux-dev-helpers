<?php

namespace TeamNiftyGmbH\FluxDevHelpers\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;

class MakeFluxDataTableCommand extends GeneratorCommand
{
    protected $description = 'Create a new Flux DataTable';

    protected $signature = 'flux-dev:make-flux-data-table {name} {model}';

    protected $type = 'DataTable';

    protected function buildClass($name): string
    {
        $stub = $this->files->get($this->getStub());

        $class = class_basename($name);
        $namespace = $this->getNamespace($name);

        $modelArgument = $this->argument('model');

        // Check if model is already fully qualified
        if (class_exists($modelArgument)) {
            $model = class_basename($modelArgument);
            $modelImport = $modelArgument;
        } else {
            // Try to find the model in the app's Models directory
            $model = Str::studly($modelArgument);
            $modelImport = $this->rootNamespace() . 'Models\\' . $model;

            // If model doesn't exist in default location, use as-is
            if (! class_exists($modelImport)) {
                $modelImport = $modelArgument;
                $model = class_basename($modelArgument);
            }
        }

        return str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ model }}', '{{ model_import }}'],
            [$namespace, $class, $model, $modelImport],
            $stub
        );
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\Livewire\DataTables';
    }

    protected function getStub(): string
    {
        return __DIR__ . '/../../stubs/data-table.stub';
    }
}
