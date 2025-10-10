<?php

namespace TeamNiftyGmbH\FluxDevHelpers\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class UpdateFromRemote extends Command
{
    protected $description = 'Update database and storage from remote server';

    protected $signature = 'flux-dev:update-from-remote
                            {server? : Remote server host (optional)}
                            {--local : Use existing local dump file}
                            {--remote : Pull new dump from server}
                            {--keep-dump : Keep dump file after import}
                            {--delete-dump : Delete dump file after import}
                            {--skip-storage : Skip storage synchronization}';

    protected string $remoteHost;

    protected string $remoteUser;

    protected string $dumpFile = 'dump.sql';

    protected bool $shouldDeleteDump = false;

    protected bool $shouldSyncStorage = true;

    public function handle(): int
    {
        // Collect all choices upfront
        if (! $this->selectRemoteServer()) {
            return Command::FAILURE;
        }

        $useLocal = $this->determineUseLocal();
        $this->shouldSyncStorage = $this->determineSyncStorage($useLocal);
        $this->shouldDeleteDump = $this->determineDumpDeletion();

        // Display summary
        info(__('Starting update process...'));
        $this->displaySummary($useLocal);

        if ($useLocal) {
            if (! $this->handleLocalDump()) {
                return Command::FAILURE;
            }
        } else {
            if (! $this->handleRemoteDump()) {
                return Command::FAILURE;
            }
        }

        if (! $this->importDatabase()) {
            return Command::FAILURE;
        }

        $this->runLaravelCommands();

        if ($this->shouldSyncStorage) {
            $this->syncStorage();
        }

        if ($this->shouldDeleteDump) {
            $this->deleteDump();
        }

        info(__('Update completed successfully!'));

        return Command::SUCCESS;
    }

    protected function determineUseLocal(): bool
    {
        if ($this->option('local')) {
            return true;
        }

        if ($this->option('remote')) {
            return false;
        }

        $choice = select(
            label: __('Choose dump source'),
            options: [
                'remote' => __('Pull new dump from server'),
                'local' => __('Use existing local dump'),
            ],
            default: 'remote'
        );

        return $choice === 'local';
    }

    protected function determineSyncStorage(bool $useLocal): bool
    {
        // Skip storage sync if using local dump
        if ($useLocal) {
            return false;
        }

        // Check if explicitly set via options
        if ($this->option('skip-storage')) {
            return false;
        }

        // Ask user
        return confirm(
            label: __('Sync storage from remote server?'),
            default: true
        );
    }

    protected function determineDumpDeletion(): bool
    {
        if ($this->option('delete-dump')) {
            return true;
        }

        if ($this->option('keep-dump')) {
            return false;
        }

        return confirm(
            label: __('Delete local dump file after import?'),
            default: false
        );
    }

    protected function displaySummary(bool $useLocal): void
    {
        note(__('Configuration:'));
        note(__('  Server: :host (:user)', ['host' => $this->remoteHost, 'user' => $this->remoteUser]));
        note(__('  Dump source: :source', ['source' => $useLocal ? __('Local') : __('Remote')]));
        note(__('  Sync storage: :sync', ['sync' => $this->shouldSyncStorage ? __('Yes') : __('No')]));
        note(__('  Delete dump: :delete', ['delete' => $this->shouldDeleteDump ? __('Yes') : __('No')]));
    }

    protected function selectRemoteServer(): bool
    {
        $servers = config('flux-dev-helpers.remote_servers', []);

        if (empty($servers)) {
            error(__('No remote servers configured in config/flux-dev-helpers.php'));
            note(__('Please add at least one server to the remote_servers array in the config file.'));

            return false;
        }

        // Check if server was provided as argument
        if ($serverArg = $this->argument('server')) {
            if (! isset($servers[$serverArg])) {
                error(__('Server ":server" not found in configuration.', ['server' => $serverArg]));
                note(__('Available servers: :servers', ['servers' => implode(', ', array_keys($servers))]));

                return false;
            }

            $this->remoteHost = $serverArg;
            $this->remoteUser = $servers[$serverArg];

            return true;
        }

        if (count($servers) === 1) {
            $this->remoteHost = array_key_first($servers);
            $this->remoteUser = $servers[$this->remoteHost];
            note(__('Using server: :host', ['host' => $this->remoteHost]));

            return true;
        }

        $options = [];
        foreach ($servers as $host => $user) {
            $options[$host] = sprintf('%s (%s)', $host, $user);
        }

        $selected = select(
            label: __('Select remote server'),
            options: $options
        );

        $this->remoteHost = $selected;
        $this->remoteUser = $servers[$selected];

        return true;
    }

    protected function handleLocalDump(): bool
    {
        if (! file_exists($this->dumpFile)) {
            error(__('Local dump file ":file" does not exist!', ['file' => $this->dumpFile]));

            return false;
        }

        info(__('Using existing local dump...'));

        return true;
    }

    protected function handleRemoteDump(): bool
    {
        $rootDirectory = '~/' . $this->remoteHost;

        $credentials = spin(
            fn () => $this->getRemoteCredentials($rootDirectory),
            __('Reading database credentials from server...')
        );

        if (! $credentials) {
            error(__('Could not read database credentials from server!'));

            return false;
        }

        ['user' => $dbUser, 'pass' => $dbPass, 'name' => $dbName] = $credentials;

        $result = spin(
            fn () => Process::run(sprintf(
                "ssh %s@%s \"mysqldump -u'%s' -p'%s' '%s' > ~/dump.sql\"",
                $this->remoteUser,
                $this->remoteHost,
                $dbUser,
                $dbPass,
                $dbName
            )),
            __('Creating dump on server...')
        );

        if ($result->failed()) {
            error(__('Failed to create dump on server'));
            error($result->errorOutput());

            return false;
        }

        $result = spin(
            fn () => Process::run(sprintf(
                'scp %s@%s:~/dump.sql .',
                $this->remoteUser,
                $this->remoteHost
            )),
            __('Downloading dump...')
        );

        if ($result->failed()) {
            error(__('Failed to download dump'));
            error($result->errorOutput());

            return false;
        }

        spin(
            fn () => Process::run(sprintf(
                'ssh %s@%s "rm -f ~/dump.sql"',
                $this->remoteUser,
                $this->remoteHost
            )),
            __('Cleaning up on server...')
        );

        return true;
    }

    protected function getRemoteCredentials(string $rootDirectory): ?array
    {
        $commands = [
            'user' => sprintf(
                'ssh %s@%s "grep \'^DB_USERNAME=\' %s/.env | cut -d \'=\' -f2- | tr -d \'\"\'  | xargs"',
                $this->remoteUser,
                $this->remoteHost,
                $rootDirectory
            ),
            'pass' => sprintf(
                'ssh %s@%s "grep \'^DB_PASSWORD=\' %s/.env | cut -d \'=\' -f2- | tr -d \'\"\'  | xargs"',
                $this->remoteUser,
                $this->remoteHost,
                $rootDirectory
            ),
            'name' => sprintf(
                'ssh %s@%s "grep \'^DB_DATABASE=\' %s/.env | cut -d \'=\' -f2- | tr -d \'\"\'  | xargs"',
                $this->remoteUser,
                $this->remoteHost,
                $rootDirectory
            ),
        ];

        $credentials = [];
        foreach ($commands as $key => $command) {
            $result = Process::run($command);
            if ($result->failed() || empty(trim($result->output()))) {
                return null;
            }
            $credentials[$key] = trim($result->output());
        }

        return $credentials;
    }

    protected function importDatabase(): bool
    {
        $dbHost = config('database.connections.mysql.host');
        $dbPort = config('database.connections.mysql.port');
        $dbDatabase = config('database.connections.mysql.database');
        $dbUser = config('database.connections.mysql.username');
        $dbPass = config('database.connections.mysql.password');

        $result = spin(
            fn () => Process::run(sprintf(
                'mysql -h%s -P%s -u"%s" -p"%s" --protocol=TCP -e "DROP DATABASE IF EXISTS %s; CREATE DATABASE %s;"',
                $dbHost,
                $dbPort,
                $dbUser,
                $dbPass,
                $dbDatabase,
                $dbDatabase
            )),
            __('Dropping and recreating database...')
        );

        if ($result->failed()) {
            error(__('Failed to create database'));
            error($result->errorOutput());

            return false;
        }

        $result = spin(
            fn () => Process::timeout(900)->run(sprintf(
                'mysql -h%s -P%s -u"%s" -p"%s" "%s" < "%s"',
                $dbHost,
                $dbPort,
                $dbUser,
                $dbPass,
                $dbDatabase,
                $this->dumpFile
            )),
            __('Importing dump into database...')
        );

        if ($result->failed()) {
            error(__('Failed to import dump'));
            error($result->errorOutput());

            return false;
        }

        return true;
    }

    protected function runLaravelCommands(): void
    {
        $result = spin(
            fn () => Process::run('./vendor/bin/sail artisan migrate --force'),
            __('Running migrations...')
        );
        
        if ($result->failed()) {
            warning(__('Migration failed'));
        }

        spin(
            function () {
                try {
                    DB::statement('TRUNCATE TABLE logs');
                } catch (\Exception $e) {
                    // Silently fail if table doesn't exist
                }
            },
            __('Truncating logs table...')
        );

        spin(
            fn () => Process::run('./vendor/bin/sail artisan cache:clear'),
            __('Clearing cache...')
        );

        spin(
            fn () => Process::run('./vendor/bin/sail artisan storage:link'),
            __('Creating storage link...')
        );
    }

    protected function syncStorage(): void
    {
        $rootDirectory = '~/' . $this->remoteHost;
        $result = spin(
            fn () => Process::run(sprintf(
                'rsync -az --info=progress2 --delete --exclude "logs" --exclude "framework" -e ssh %s@%s:%s/storage .',
                $this->remoteUser,
                $this->remoteHost,
                $rootDirectory
            )),
            __('Syncing storage from server...')
        );

        if ($result->failed()) {
            warning(__('Storage sync failed'));
        }
    }

    protected function deleteDump(): void
    {
        if (file_exists($this->dumpFile)) {
            unlink($this->dumpFile);
            note(__('Dump file has been deleted.'));
        }
    }
}
