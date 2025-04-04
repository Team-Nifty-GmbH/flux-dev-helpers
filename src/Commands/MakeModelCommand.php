<?php

namespace TeamNiftyGmbH\FluxDevHelpers\Commands;

use FluxErp\Console\Commands\MakeAction;
use FluxErp\Console\Commands\MakeRuleset;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use function Livewire\invade;

class MakeModelCommand extends GeneratorCommand
{
    protected $description = 'Command description';

    protected $signature = 'flux-dev:make-model {name}';

    public function handle(): void
    {
        $name = $this->argument('name');
        $name = Str::of($name)->singular()->studly();
        $modelClass = $this->qualifyModel($name);
        $name = class_basename($modelClass);

        $this->call('make:model', ['name' => $modelClass, '--migration' => true]);

        // create the datatable
        $this->call('make:data-table', ['name' => $name . 'List', 'model' => $modelClass]);
        // create rulesets
        $this->call('make:ruleset', ['name' => 'Create' . $name . 'Ruleset', '--model' => $modelClass]);
        $createRuleset = $this->invadeCommand(
            MakeRuleset::class,
            ['name' => 'ExampleAction'],
            ['model' => $modelClass]
        )
            ->getDefaultNamespace($this->rootNamespace()) . '\Create' . $name . 'Ruleset::class';
        $this->call('make:ruleset', ['name' => 'Update' . $name . 'Ruleset', '--model' => $modelClass]);
        $updateRuleset = $this->invadeCommand(
            MakeRuleset::class,
            ['name' => 'ExampleAction'],
            ['model' => $modelClass]
        )
            ->getDefaultNamespace($this->rootNamespace()) . '\Update' . $name . 'Ruleset::class';

        $this->call('make:ruleset', ['name' => 'Delete' . $name . 'Ruleset', '--model' => $modelClass]);
        $deleteRuleset = $this->invadeCommand(
            MakeRuleset::class,
            ['name' => 'ExampleAction'],
            ['model' => $modelClass]
        )
            ->getDefaultNamespace($this->rootNamespace()) . '\Delete' . $name . 'Ruleset::class';

        // create CRUD action classes
        $this->call('make:action', ['name' => 'Create' . $name, '--model' => $modelClass, '--ruleset' => Str::deduplicate($createRuleset, '\\')]);
        $this->call('make:action', ['name' => 'Update' . $name, '--model' => $modelClass, '--ruleset' => Str::deduplicate($updateRuleset, '\\')]);
        $this->call('make:action', ['name' => 'Delete' . $name, '--model' => $modelClass, '--ruleset' => Str::deduplicate($deleteRuleset, '\\')]);

        // create the livewire form
        $command = $this->invadeCommand(
            MakeAction::class,
            ['name' => 'ExampleAction'],
            ['model' => $modelClass]
        );
        $this->call(
            'make:flux-form',
            [
                'name' => $name . 'Form',
                '--createAction' => Str::deduplicate($command->getDefaultNamespace($this->rootNamespace()) . '\Create' . $name . '::class', '\\'),
                '--updateAction' => Str::deduplicate($command->getDefaultNamespace($this->rootNamespace()) . '\Update' . $name . '::class', '\\'),
                '--deleteAction' => Str::deduplicate($command->getDefaultNamespace($this->rootNamespace()) . '\Delete' . $name . '::class', '\\'),
            ]
        );
    }

    protected function getStub(): void
    {
        // TODO: Implement getStub() method.
    }

    protected function invadeCommand(string $command, array $arguments = [], array $options = []): object
    {
        $command = app()->make($command);
        $input = new ArrayInput($arguments, $command->getDefinition());
        foreach ($options as $option => $value) {
            $input->setOption($option, $value);
        }
        $symfonyOutput = new BufferedOutput();
        $output = new OutputStyle($input, $symfonyOutput);
        $command->setLaravel(app());
        $command->setInput($input);
        $command->setOutput($output);

        return invade($command);
    }
}
