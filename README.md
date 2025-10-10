<p align="center"><a href="https://flux-erp.com" target="_blank"><img src="https://user-images.githubusercontent.com/40495041/160839207-0e1593e0-ff3d-4407-b9d2-d3513c366ab9.svg" width="400"></a></p>

## 1. Installation

Install the package via composer:

```bash
composer require team-nifty-gmbh/flux-dev-helpers --dev
```

## 2. Publishing the workflow

```bash
php artisan vendor:publish --tag=flux-dev-helpers-laravel-workflow
```

## 3. Commands

### Update from Remote Server

Sync database and storage from a remote server to your local environment.

```bash
php artisan flux-dev:update-from-remote
```

#### Configuration

Configure remote servers in `config/flux-dev-helpers.php`:

```php
'remote_servers' => [
    'flux.example.com' => 'ssh_user',
    'staging.example.com' => 'forge',
],
```

#### Options

- `--local` - Use existing local dump file instead of pulling from server
- `--remote` - Pull new dump from server (default behavior)
- `--keep-dump` - Keep dump file after import
- `--delete-dump` - Delete dump file after import
- `--skip-storage` - Skip storage synchronization via rsync

#### Examples

```bash
# Interactive mode (will prompt for choices)
php artisan flux-dev:update-from-remote

# Use local dump and skip storage sync
php artisan flux-dev:update-from-remote --local --skip-storage --keep-dump

# Pull from remote and clean up
php artisan flux-dev:update-from-remote --remote --delete-dump
```

#### What it does

1. Selects remote server (prompts if multiple configured)
2. Either pulls database dump from server or uses existing local dump
3. Drops and recreates local database
4. Imports dump into local database
5. Runs Laravel migrations
6. Truncates logs table
7. Clears cache and creates storage link
8. Optionally syncs storage from remote server (skipped when using `--local` or `--skip-storage`)
9. Optionally deletes local dump file

### Other Commands

#### Generate Livewire Smoke Tests

```bash
php artisan flux-dev:generate-livewire-smoke-tests {name?} {--all} {--stub}
```

#### Fix Order Position Sort

```bash
php artisan flux-dev:fix-order-positions-sort
```

#### Make Commands

```bash
php artisan flux-dev:make-model {name}
php artisan flux-dev:make-flux-model {name}
php artisan flux-dev:make-flux-datatable {name}
```
