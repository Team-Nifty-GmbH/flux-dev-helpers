<?php

namespace TeamNiftyGmbH\FluxDevHelpers\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;

class MakeFluxModelCommand extends GeneratorCommand
{
    protected $description = 'Create a new Flux model';

    protected $signature = 'flux-dev:make-flux-model {name} {--migration}';

    protected $type = 'Model';

    public function handle(): void
    {
        parent::handle();

        if ($this->option('migration')) {
            $table = Str::snake(Str::pluralStudly(class_basename($this->argument('name'))));
            $this->call('make:migration', [
                'name' => "create_{$table}_table",
                '--create' => $table,
            ]);
        }
    }

    protected function buildClass($name): string
    {
        $stub = $this->files->get($this->getStub());

        $model = class_basename($name);
        $namespace = $this->getNamespace($name);
        $table = Str::snake(Str::pluralStudly($model));

        return str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ table }}'],
            [$namespace, $model, $table],
            $stub
        );
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\Models';
    }

    protected function getStub(): string
    {
        return __DIR__ . '/../../stubs/model.stub';
    }
}
