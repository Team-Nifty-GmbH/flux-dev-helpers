<?php

namespace TeamNiftyGmbH\FluxDevHelpers\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class CacheApiDocs extends Command
{
    protected $description = 'Caches the OpenAPI document in a separate process';

    protected $signature = 'flux-dev:cache-api-docs';

    public function handle(): int
    {
        // Running in the same process as "optimize" would hit the routes cached moments earlier,
        // which are not bound and blow up as soon as Scramble gathers their middleware
        $result = Process::path(base_path())
            ->run([PHP_BINARY, 'artisan', 'scramble:cache']);

        $this->output->write($result->output());

        if ($result->failed()) {
            $this->output->write($result->errorOutput());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
