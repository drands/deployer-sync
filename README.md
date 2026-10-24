# Deployer Sync 
This package provides a set of commands to synchronize uploaded files and database between a remote server and your local machine using Deployer.

## Installation

Install the package using Composer:

```bash
composer require drands/deployer-sync
```

Add the following to your `deploy.yml` file:

```yml
import:
  - vendor/drands/deployer-sync/src/recipe/sync.php
```

You must also set the `local_php_path` variable with the path to your PHP executable on your local machine. For example:

```yml
local_php_path: php
```

**Important:** Before you can execute actions on the remote server, you must perform a deployment.

`mysqldump` and `mysql` connect with the app's `mysql` connection settings (host and port, or
`unix_socket`). If they aren't in the `PATH`, set their paths with `SYNC_MYSQLDUMP_PATH` and
`SYNC_MYSQL_PATH` (`database.connections.mysql.dump_path` is still honored for `mysqldump`).

## Usage

### Full sync files and database from remote server to local machine
```bash
dep sync:prod-to-local
```

### Only export files and database from remote server and download to local machine
```bash
dep sync:export-to-local
```

### Full sync including S3 file clone (if your uploads disk is S3-compatible)
```bash
dep sync:prod-to-local-s3
```

This is a shortcut for `dep sync:prod-to-local -o s3=true` — `-o s3=true` also works directly
on `sync:prod-to-local` or `sync:export-to-local` if you'd rather not use the extra task.

### Only import files and database from sync_export.zip
```bash
dep sync:import-from-local
```

### Only clone uploaded files from the production bucket to your local development prefix
```bash
dep sync:clone-storage
```

## Anonymizing personal data

`sync:export` never copies personal data as is: `database/sync-schema.php` (in your project, committed)
declares, for **every** table, how its data is exported:

```php
return [
    'products' => 'keep',     // dumped as is: only for tables without columns that look personal
    'sessions' => 'exclude',  // not dumped: the table is empty locally
    'users' => [              // anonymized: every column must be declared
        'id' => 'keep',
        'name' => 'name',
        'email' => 'unique_email',
        'password' => 'password',
        'remember_token' => null,
        'created_at' => 'keep',
        'updated_at' => 'keep',
    ],
];
```

**The export fails** (in production, before dumping anything) if a table isn't declared, if a column of an
anonymized table isn't declared, if a strategy is invalid, or if a table marked `'keep'` has columns that
look personal (judging by their names: `email`, `phone`, `address`, `dni`, `iban`, `ip_address`, `notes`...).
Marking such a column `'keep'` explicitly is allowed: it has to be a conscious decision.

Column strategies (NULL values always stay NULL):

| Strategy | Result |
|---|---|
| `'keep'` | Original value |
| `null` | NULL (nullable columns only) |
| `'static:<value>'` | That literal value |
| `'unique_email'` | `user<primary key>@example.test`, safe for UNIQUE indexes |
| `'password'` | Bcrypt hash of `password`, so you can log in locally |
| `'<formatter>'` | Any [Faker](https://fakerphp.org/formatters/) formatter, e.g. `'name'`, `'phoneNumber'`, `'streetAddress'` |
| `'<formatter>:<arg>'` | Faker formatter with one argument, e.g. `'regexify:[0-9]{8}[A-Z]'` |
| `'App\\Sync\\MyAnonymizer'` | Class implementing `Drands\DeployerSync\Anonymization\Anonymizer` (e.g. for JSON columns) |

Faker is seeded per row, so every export generates the same fake values. Its locale is `app.faker_locale`
(override it with `faker_locale` in `config/deployer-sync.php`). Fake strings longer than the column are truncated.

### Keeping it up to date

```bash
php artisan sync:check        # lists what's missing or wrong (exit code 1)
php artisan sync:check --fix  # declares what's missing with suggested strategies and removes what no longer exists
```

`--fix` guesses from column names, so **review its suggestions** (e.g. free-text columns it doesn't
recognize stay `'keep'`). It regenerates the file, so comments in it are lost. Both commands read the
local database: run your migrations first.

### Claude Code integration

In the `local` environment, the package installs these files into your project on install/update (more
precisely, after `package:discover`, which Laravel runs after every `composer install/update`, or after
the first artisan command if the package wasn't discovered yet). Review them in `git status` before committing:

- `.claude/settings.json`: a `Stop` hook running `sync:check --hook`, so Claude can't finish a task while
  `database/sync-schema.php` is out of date (it doesn't block twice in a row, nor when the database is down).
  Existing settings are kept.
- `.claude/skills/sync-schema/SKILL.md`: how to fill in the file and what counts as personal data.
  Overwritten on every update.
- `tests/Feature/SyncSchemaTest.php`: runs `sync:check` (with `RefreshDatabase`), so the test suite fails
  too. Only created if missing.

Run `php artisan sync:install-claude` to install them manually (`--force` also overwrites the test).

## Excluding tables from the database dump

The dump only contains data, so any table that doesn't exist locally makes the import fail.
Tables matching the `exclude_tables` patterns in `config/deployer-sync.php` are skipped
(`*` wildcards allowed). By default this covers phpMyAdmin's configuration storage (`pma__*`).
They don't need to be declared in `database/sync-schema.php`. For tables that exist locally,
use `'exclude'` in `database/sync-schema.php` instead.

## Syncing uploaded files on S3-compatible storage (e.g. DigitalOcean Spaces)

`sync:export`/`sync:export-to-local` only handle files that live on local disk (`storage/app`)
on the remote server. If your uploads disk is S3-compatible, those files never touch the
server's local disk, so passing `-o s3=true` to `sync:export-to-local` (or `sync:prod-to-local`)
makes it also pass `--s3` to the remote `sync:export` call, which copies the files directly from
the production bucket to a development bucket, server-side, in the same remote call (no data
passes through your local machine or the deploy server's disk). Without `-o s3=true`, nothing
S3-related runs. A standalone `sync:clone-storage` task/command is also available if you need to
(re-)run just the file clone without a full export.

This requires:

- A **separate bucket for development**, shared by all developers, since S3-compatible
  providers (e.g. DigitalOcean Spaces) generally scope access keys per bucket, not per prefix
  within a bucket. Keeping production and development in the same bucket would mean a
  developer's key would also need read access to production data.
- Each developer's own key restricted to the development bucket only, writing under
  their own prefix (`filesystems.disks.s3.root`, e.g. via a `DO_PREFIX=local/yourname` env var).
- The production key additionally granted **write access to the development bucket** (on top
  of its existing production access), so the copy can run entirely on the server. Set the
  destination bucket name in the production environment:

```
SYNC_DEV_BUCKET=your-dev-bucket-name
```

- If your uploads disk isn't the app's default filesystem disk, set which disk to read from:

```
SYNC_DISK=s3
```

`sync:clone-storage` mirrors production into your development prefix: unchanged files (matched
by ETag) are skipped, and any file under your prefix that no longer exists in production is
**deleted**, including files you may have uploaded manually while testing locally. It's safe
and idempotent to run repeatedly.

If the configured uploads disk isn't S3-compatible, `--s3` is a no-op and `storage/app` files
continue to sync through the existing ZIP-based flow.