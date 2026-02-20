<?php

namespace TeamNiftyGmbH\FluxDevHelpers\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use TeamNiftyGmbH\FluxDevHelpers\Events\DatabaseImportCompleted;
use TeamNiftyGmbH\FluxDevHelpers\Events\DatabaseImportStarted;
use TeamNiftyGmbH\FluxDevHelpers\Events\DumpDownloaded;
use TeamNiftyGmbH\FluxDevHelpers\Events\MigrationsCompleted;
use TeamNiftyGmbH\FluxDevHelpers\Events\RemoteDumpCreated;
use TeamNiftyGmbH\FluxDevHelpers\Events\StorageSyncCompleted;
use TeamNiftyGmbH\FluxDevHelpers\Events\StorageSyncStarted;
use TeamNiftyGmbH\FluxDevHelpers\Events\UpdateFromRemoteCompleted;
use TeamNiftyGmbH\FluxDevHelpers\Events\UpdateFromRemoteFailed;
use TeamNiftyGmbH\FluxDevHelpers\Events\UpdateFromRemoteStarted;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;

class UpdateFromRemote extends Command
{
    protected $description = 'Update database and storage from remote server';

    protected $signature = 'flux-dev:update-from-remote
                            {server? : Remote server host (optional)}
                            {--full : Full sync: pull new dump, keep it, sync storage}
                            {--fast : Fast sync: use local dump if exists, no storage sync}
                            {--local : Use existing local dump file}
                            {--remote : Pull new dump from server}
                            {--keep-dump : Keep dump file after import}
                            {--delete-dump : Delete dump file after import}
                            {--sync-storage : Sync storage from remote server}
                            {--skip-storage : Skip storage synchronization}';

    protected string $serverName;

    protected string $remoteUser;

    protected string $sshHost;

    protected ?int $sshPort = null;

    protected ?string $identityFile = null;

    protected ?string $proxyJump = null;

    protected string $remoteDirectory;

    protected string $dumpFile = 'dump.sql';

    protected bool $shouldDeleteDump = false;

    protected bool $shouldSyncStorage = true;

    public function handle(): int
    {
        $useLocal = $this->determineUseLocal();

        // Collect all choices upfront
        if (! $useLocal) {
            if (! $this->selectRemoteServer()) {
                return Command::FAILURE;
            }
        } else {
            $this->serverName = 'local';
            $this->remoteUser = 'local';
            $this->sshHost = 'local';
            $this->remoteDirectory = '';
        }

        $this->shouldSyncStorage = $this->determineSyncStorage($useLocal);
        $this->shouldDeleteDump = $this->determineDumpDeletion();

        // Display summary
        info(__('Starting update process...'));
        $this->displaySummary($useLocal);

        UpdateFromRemoteStarted::dispatch(
            $this->serverName,
            $this->remoteUser,
            $useLocal,
            $this->shouldSyncStorage,
            $this->shouldDeleteDump
        );

        if ($useLocal) {
            if (! $this->handleLocalDump()) {
                UpdateFromRemoteFailed::dispatch(
                    $this->serverName,
                    $this->remoteUser,
                    'Local dump file not found'
                );

                return Command::FAILURE;
            }
        } else {
            if (! $this->handleRemoteDump()) {
                UpdateFromRemoteFailed::dispatch(
                    $this->serverName,
                    $this->remoteUser,
                    'Failed to handle remote dump'
                );

                return Command::FAILURE;
            }
        }

        if (! $this->importDatabase()) {
            UpdateFromRemoteFailed::dispatch(
                $this->serverName,
                $this->remoteUser,
                'Database import failed'
            );

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

        UpdateFromRemoteCompleted::dispatch(
            $this->serverName,
            $this->remoteUser,
            true
        );

        return Command::SUCCESS;
    }

    protected function determineUseLocal(): bool
    {
        // --full always pulls new dump
        if ($this->option('full')) {
            return false;
        }

        // --fast uses local if exists, otherwise remote
        if ($this->option('fast')) {
            return file_exists($this->dumpFile);
        }

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
        // Skip storage sync if using local dump (unless explicitly requested)
        if ($useLocal && ! $this->option('sync-storage')) {
            return false;
        }

        // --full always syncs storage
        if ($this->option('full')) {
            return true;
        }

        // --fast skips storage by default (unless --sync-storage is set)
        if ($this->option('fast')) {
            return $this->option('sync-storage');
        }

        // Check if explicitly set via options
        if ($this->option('sync-storage')) {
            return true;
        }

        if ($this->option('skip-storage')) {
            return false;
        }

        // Ask user
        return confirm(
            label: __('Sync storage from remote server?')
        );
    }

    protected function determineDumpDeletion(): bool
    {
        // --full and --fast always keep the dump
        if ($this->option('full') || $this->option('fast')) {
            return false;
        }

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
        note(__('  Server: :name (:user)', ['name' => $this->serverName, 'user' => $this->remoteUser]));
        if ($this->sshHost !== $this->serverName) {
            note(__('  SSH: :host::port', ['host' => $this->sshHost, 'port' => $this->sshPort ?? 22]));
        }
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

            $this->resolveServerConfig($serverArg, $servers[$serverArg]);

            return true;
        }

        if (count($servers) === 1) {
            $key = array_key_first($servers);
            $this->resolveServerConfig($key, $servers[$key]);
            note(__('Using server: :host', ['host' => $this->serverName]));

            return true;
        }

        $options = [];
        foreach ($servers as $host => $config) {
            $user = is_string($config) ? $config : $config['user'];
            $sshHost = is_string($config) ? $host : ($config['host'] ?? $host);
            $label = $host === $sshHost
                ? sprintf('%s (%s)', $host, $user)
                : sprintf('%s → %s (%s)', $host, $sshHost, $user);
            $options[$host] = $label;
        }

        $selected = select(
            label: __('Select remote server'),
            options: $options
        );

        $this->resolveServerConfig($selected, $servers[$selected]);

        return true;
    }

    protected function resolveServerConfig(string $key, string|array $config): void
    {
        $this->serverName = $key;

        if (is_string($config)) {
            $this->remoteUser = $config;
            $this->sshHost = $key;
            $this->remoteDirectory = '~/' . $key;

            return;
        }

        $this->remoteUser = $config['user'];
        $this->sshHost = $config['host'] ?? $key;
        $this->sshPort = $config['port'] ?? null;
        $this->identityFile = $config['identity_file'] ?? null;
        $this->proxyJump = $config['proxy_jump'] ?? null;
        $this->remoteDirectory = $config['directory'] ?? '~/' . $key;
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
        $sshTarget = sprintf('%s@%s', $this->remoteUser, $this->sshHost);

        $credentials = spin(
            fn () => $this->getRemoteCredentials($this->remoteDirectory),
            __('Reading database credentials from server...')
        );

        if (! $credentials) {
            error(__('Could not read database credentials from server!'));

            return false;
        }

        ['user' => $dbUser, 'pass' => $dbPass, 'name' => $dbName] = $credentials;

        $result = spin(
            fn () => Process::timeout(900)->run([
                'ssh', ...$this->sshOptions(), $sshTarget,
                sprintf("mysqldump -u'%s' -p'%s' '%s' > ~/dump.sql", $dbUser, $dbPass, $dbName),
            ]),
            __('Creating dump on server...')
        );

        if ($result->failed()) {
            error(__('Failed to create dump on server'));
            error($result->errorOutput());

            return false;
        }

        RemoteDumpCreated::dispatch($this->serverName, $this->remoteUser);

        $result = spin(
            fn () => Process::timeout(900)->run([
                'scp', ...$this->scpOptions(),
                sprintf('%s:~/dump.sql', $sshTarget),
                '.',
            ]),
            __('Downloading dump...')
        );

        if ($result->failed()) {
            error(__('Failed to download dump'));
            error($result->errorOutput());

            return false;
        }

        DumpDownloaded::dispatch($this->serverName, $this->remoteUser, $this->dumpFile);

        spin(
            fn () => Process::run(['ssh', ...$this->sshOptions(), $sshTarget, 'rm -f ~/dump.sql']),
            __('Cleaning up on server...')
        );

        return true;
    }

    protected function getRemoteCredentials(string $rootDirectory): ?array
    {
        $tempEnvFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'remote_env_' . uniqid();

        $result = Process::run([
            'scp', ...$this->scpOptions(),
            sprintf('%s@%s:%s/.env', $this->remoteUser, $this->sshHost, $rootDirectory),
            $tempEnvFile,
        ]);

        if ($result->failed() || ! file_exists($tempEnvFile)) {
            return null;
        }

        try {
            $envContent = file_get_contents($tempEnvFile);

            $mapping = [
                'DB_USERNAME' => 'user',
                'DB_PASSWORD' => 'pass',
                'DB_DATABASE' => 'name',
            ];

            $credentials = [];
            foreach ($mapping as $envKey => $credKey) {
                if (preg_match('/^' . preg_quote($envKey, '/') . '=(.*)$/m', $envContent, $matches)) {
                    $credentials[$credKey] = trim($matches[1], " \t\n\r\0\x0B\"'");
                } else {
                    return null;
                }
            }

            return $credentials;
        } finally {
            @unlink($tempEnvFile);
        }
    }

    protected function importDatabase(): bool
    {
        DatabaseImportStarted::dispatch($this->dumpFile);

        $dbDatabase = config('database.connections.mysql.database');

        // Array-based: bypasses shell, backticks in SQL are safe
        $result = spin(
            fn () => Process::run([
                ...$this->buildMysqlArgs(),
                '-e',
                sprintf('DROP DATABASE IF EXISTS `%s`; CREATE DATABASE `%s`;', $dbDatabase, $dbDatabase),
            ]),
            __('Dropping and recreating database...')
        );

        if ($result->failed()) {
            error(__('Failed to create database'));
            error($result->errorOutput());

            return false;
        }

        // String-based: shell needed for < redirection, works on bash and cmd.exe
        $result = spin(
            fn () => Process::timeout(900)->run(sprintf(
                '%s %s < %s',
                $this->buildMysqlCommandString(),
                escapeshellarg($dbDatabase),
                escapeshellarg($this->dumpFile)
            )),
            __('Importing dump into database...')
        );

        if ($result->failed()) {
            error(__('Failed to import dump'));
            error($result->errorOutput());

            return false;
        }

        DatabaseImportCompleted::dispatch($this->dumpFile);

        return true;
    }

    protected function buildMysqlArgs(): array
    {
        $args = [
            'mysql',
            '-h' . config('database.connections.mysql.host'),
            '-P' . config('database.connections.mysql.port'),
            '-u' . config('database.connections.mysql.username'),
            '--protocol=TCP',
        ];

        $dbPass = config('database.connections.mysql.password');
        if ($dbPass) {
            $args[] = '-p' . $dbPass;
        }

        return $args;
    }

    protected function buildMysqlCommandString(): string
    {
        $cmd = sprintf(
            'mysql -h%s -P%s -u%s --protocol=TCP',
            escapeshellarg(config('database.connections.mysql.host')),
            escapeshellarg(config('database.connections.mysql.port')),
            escapeshellarg(config('database.connections.mysql.username'))
        );

        $dbPass = config('database.connections.mysql.password');
        if ($dbPass) {
            $cmd .= ' -p' . escapeshellarg($dbPass);
        }

        return $cmd;
    }

    protected function runLaravelCommands(): void
    {
        $artisan = $this->getArtisanCommand();

        info(__('Running migrations...'));
        $result = Process::run($artisan . ' migrate --force');

        $this->line($result->output());

        if ($result->failed()) {
            MigrationsCompleted::dispatch(false);

            throw new RuntimeException($result->errorOutput());
        }

        MigrationsCompleted::dispatch(true);

        spin(
            function (): void {
                try {
                    DB::statement('TRUNCATE TABLE logs');
                } catch (Exception $e) {
                    // Silently fail if table doesn't exist
                }
            },
            __('Truncating logs table...')
        );

        spin(
            fn () => Process::run($artisan . ' cache:clear'),
            __('Clearing cache...')
        );

        spin(
            fn () => Process::run($artisan . ' storage:link'),
            __('Creating storage link...')
        );
    }

    protected function getArtisanCommand(): string
    {
        // Already running inside Sail container
        if (env('LARAVEL_SAIL')) {
            return 'php artisan';
        }

        // Sail is a bash script - skip on Windows where it can't run natively
        if (! $this->isWindows() && file_exists(base_path('vendor/bin/sail'))) {
            return base_path('vendor/bin/sail') . ' artisan';
        }

        // Fallback to direct PHP execution
        return 'php artisan';
    }

    protected function syncStorage(): void
    {
        StorageSyncStarted::dispatch($this->serverName, $this->remoteUser);

        if ($this->isCommandAvailable('rsync')) {
            $result = spin(
                fn () => Process::timeout(900)->run(sprintf(
                    'rsync -az --info=progress2 --delete --exclude "logs" --exclude "framework" -e "ssh %s" %s@%s:%s/storage .',
                    implode(' ', $this->sshOptions()),
                    $this->remoteUser,
                    $this->sshHost,
                    $this->remoteDirectory
                )),
                __('Syncing storage from server (rsync)...')
            );
        } else {
            $result = $this->syncStorageViaTar();
        }

        if ($result->failed()) {
            error(__('Storage sync failed'));
            error($result->errorOutput());
            StorageSyncCompleted::dispatch($this->serverName, $this->remoteUser, false);
        } else {
            StorageSyncCompleted::dispatch($this->serverName, $this->remoteUser, true);
        }
    }

    protected function syncStorageViaTar(): ProcessResult
    {
        $archiveName = 'storage_sync_' . uniqid() . '.tar.gz';
        $sshTarget = sprintf('%s@%s', $this->remoteUser, $this->sshHost);

        $result = spin(
            fn () => Process::timeout(300)->run([
                'ssh', ...$this->sshOptions(), $sshTarget,
                sprintf('cd %s && tar -czf ~/%s --exclude="logs" --exclude="framework" storage', $this->remoteDirectory, $archiveName),
            ]),
            __('Creating storage archive on server...')
        );

        if ($result->failed()) {
            return $result;
        }

        $result = spin(
            fn () => Process::timeout(900)->run([
                'scp', ...$this->scpOptions(),
                sprintf('%s:~/%s', $sshTarget, $archiveName),
                '.',
            ]),
            __('Downloading storage archive...')
        );

        if ($result->failed()) {
            Process::run(['ssh', ...$this->sshOptions(), $sshTarget, sprintf('rm -f ~/%s', $archiveName)]);

            return $result;
        }

        $result = spin(
            fn () => Process::run(['tar', '-xzf', $archiveName]),
            __('Extracting storage archive...')
        );

        @unlink($archiveName);
        Process::run(['ssh', ...$this->sshOptions(), $sshTarget, sprintf('rm -f ~/%s', $archiveName)]);

        return $result;
    }

    protected function deleteDump(): void
    {
        if (file_exists($this->dumpFile)) {
            unlink($this->dumpFile);
            note(__('Dump file has been deleted.'));
        }
    }

    protected function sshOptions(): array
    {
        $devNull = $this->isWindows() ? 'NUL' : '/dev/null';
        $options = ['-o', 'StrictHostKeyChecking=no', '-o', "UserKnownHostsFile=$devNull"];

        if ($this->sshPort) {
            $options[] = '-p';
            $options[] = (string) $this->sshPort;
        }

        if ($this->identityFile) {
            $options[] = '-i';
            $options[] = $this->identityFile;
        }

        if ($this->proxyJump) {
            $options[] = '-J';
            $options[] = $this->proxyJump;
        }

        return $options;
    }

    protected function scpOptions(): array
    {
        $devNull = $this->isWindows() ? 'NUL' : '/dev/null';
        $options = ['-o', 'StrictHostKeyChecking=no', '-o', "UserKnownHostsFile=$devNull"];

        if ($this->sshPort) {
            $options[] = '-P';
            $options[] = (string) $this->sshPort;
        }

        if ($this->identityFile) {
            $options[] = '-i';
            $options[] = $this->identityFile;
        }

        if ($this->proxyJump) {
            $options[] = '-o';
            $options[] = sprintf('ProxyJump=%s', $this->proxyJump);
        }

        return $options;
    }

    protected function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    protected function isCommandAvailable(string $command): bool
    {
        $result = Process::run(
            $this->isWindows() ? ['where', $command] : ['which', $command]
        );

        return $result->successful();
    }
}
