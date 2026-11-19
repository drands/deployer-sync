# Deployer Sync

Brings a copy of production to your local machine: the database (with personal data anonymized) and the
uploaded files, using Deployer.

How it works: `sync:export` runs on the server and packs a database dump plus `storage/app` into
`storage/sync_export.zip`, Deployer downloads it, and `sync:import` loads it locally.

> [!WARNING]
> The import **wipes your local database** and replaces your local `storage/app` with the production files.

## Requirements

- PHP 8.1+ and Laravel 8–13.
- MySQL or MariaDB (`mysqldump` on the server, `mysql` locally).
- Deployer 7.3+ or 8. It isn't a dependency of the package: it's whatever `dep` you run (e.g. your
  project's `deployer/deployer` dev dependency).
- For S3-compatible uploads only: `aws/aws-sdk-php` (already installed if you use Laravel's `s3` driver).

## Installation

1. Install the package. It has to be a regular dependency, **not** `--dev`: `sync:export` runs on the
   server, where dev dependencies aren't installed.

   ```bash
   composer require drands/deployer-sync
   ```

2. Import the recipe and set the path to your local PHP executable, in `deploy.yml`:

   ```yml
   import:
     - vendor/drands/deployer-sync/src/recipe/sync.php

   config:
     local_php_path: php
   ```

   or in `deploy.php`:

   ```php
   require 'vendor/drands/deployer-sync/src/recipe/sync.php';

   set('local_php_path', 'php');
   ```

3. Declare how every table is exported (see [Anonymizing personal data](#anonymizing-personal-data)). With
   your local migrations up to date:

   ```bash
   php artisan sync:check --fix
   ```

   Review the generated `database/sync-schema.php` and commit it. Without it, the export fails.

4. Deploy, so the package and `database/sync-schema.php` are on the server.

5. Sync:

   ```bash
   dep sync:prod-to-local
   ```

Optionally, publish the configuration file to change any of the settings mentioned below:

```bash
php artisan vendor:publish --tag=deployer-sync-config
```

`mysqldump` and `mysql` connect with the app's `mysql` connection settings (host and port, or
`unix_socket`). If they aren't in the `PATH`, set their paths with `SYNC_MYSQLDUMP_PATH` and
`SYNC_MYSQL_PATH` (`database.connections.mysql.dump_path` is still honored for `mysqldump`).

## Usage

### Deployer tasks

| Task | What it does |
|---|---|
| `dep sync:prod-to-local` | Export on the server, download and import: the full sync |
| `dep sync:export-to-local` | Export on the server and download `storage/sync_export.zip`, without importing |
| `dep sync:import-from-local` | Import an already downloaded `storage/sync_export.zip` |
| `dep sync:prod-to-local-s3` | Full sync, also cloning S3 uploads (same as `dep sync:prod-to-local -o s3=true`) |
| `dep sync:clone-storage` | Only clone S3 uploads from the production bucket to your development prefix |

`-o s3=true` also works on `sync:export-to-local`. See [Syncing uploaded files on S3-compatible
storage](#syncing-uploaded-files-on-s3-compatible-storage-eg-digitalocean-spaces).

### Artisan commands

| Command | Where | What it does |
|---|---|---|
| `sync:export` | Server | Creates `storage/sync_export.zip` (`--s3` also clones S3 uploads, `--clean` only removes old exports) |
| `sync:import` | Local | Loads `storage/sync_export.zip` |
| `sync:check` | Local | Checks `database/sync-schema.php` (`--fix` updates it) |
| `sync:local-admin` | Local | Creates or updates your local admin after an import |
| `sync:clone-storage` | Server | Clones S3 uploads to the development bucket |
| `sync:install-claude` | Local | Installs the Claude Code hook, skill and test |

### What the import does

It wipes the local database, runs your local migrations and then loads the dump. The dump names the
columns in every insert, so local columns that production doesn't have yet (pending migrations) just get
their default values. Files under any `livewire-tmp` directory of `storage/app` are never exported.

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

## Restoring your local admin after an import

The import replaces your local users with the anonymized production ones, so `dep sync:import-from-local`
(and therefore `dep sync:prod-to-local`) then asks whether to create or update a local admin: by default it
updates user `1`, with the name, email and password pre-filled from your `.env`:

```dotenv
SYNC_ADMIN_NAME="Your Name"
SYNC_ADMIN_EMAIL=you@example.com
SYNC_ADMIN_PASSWORD=your-local-password
```

Fields left empty keep what the imported user has. With `SYNC_ADMIN_ASK_DEFAULTS=false` nothing that already
has a value is asked: user `1` is updated with your `.env` values and you're only asked for the empty ones
(typically the password, which is then typed hidden). If your admin model isn't `App\Models\User`, set
`SYNC_ADMIN_MODEL`. If its fields aren't `name`/`email`/`password`, override `local_admin` in
`config/deployer-sync.php` (only that key is needed):

```php
return [
    'local_admin' => [
        'model' => env('SYNC_ADMIN_MODEL', \App\Models\Admin::class),
        'ask_defaults' => env('SYNC_ADMIN_ASK_DEFAULTS', true),
        'id' => 1,
        'fields' => [                       // asked for, with their defaults
            'first_name' => env('SYNC_ADMIN_FIRST_NAME'),
            'last_name' => env('SYNC_ADMIN_LAST_NAME'),
            'email' => env('SYNC_ADMIN_EMAIL'),
            'password' => env('SYNC_ADMIN_PASSWORD'), // hashed
        ],
        'attributes' => ['is_admin' => true],          // forced on the restored admin
        'reset' => ['remember_token', 'two_factor_secret'], // set to NULL
    ],
];
```

The same runs without prompts with `php artisan sync:local-admin` (options: `--create`, `--id=`,
`--field=name=value`). It refuses to run in production. To skip the question in Deployer, add
`set('sync_local_admin', false);` to your `deploy.php`.

## Large uploads, timeouts and MariaDB dumps

- **Timeout:** the remote export and the local import may run for up to `sync_timeout` seconds
  (default `3600`; Deployer's own `default_timeout` is 300). Change it with `set('sync_timeout', 7200);`
  in your `deploy.php` or under `config:` in `deploy.yml`. The download has no timeout.
- **Memory:** `sync:export` walks `storage/app` lazily, so the number of uploaded files doesn't raise
  its memory usage (a 128 MB `memory_limit` is enough).
- **Compression:** files whose extension is in `store_uncompressed` (images, videos, PDFs, archives,
  office files…) are stored in the ZIP as is: deflating them barely shrinks it but makes the export
  several times slower. Adjust the list in `config/deployer-sync.php`.
- **MariaDB to MySQL:** `sync:import` drops the sandbox-mode first line of `mariadb-dump` 11.x dumps
  (`/*M!999999\- enable the sandbox mode */`), which the MySQL client rejects. Stored generated columns
  (e.g. Laravel's `storedAs()`) become plain columns while the dump loads, since MySQL refuses values for
  them, and are generated again afterwards. Anonymized tables never dump generated columns.
- **Local-only files:** top-level paths of `storage/app` listed in `keep_local_paths` (e.g. `'imports'`)
  are kept by `sync:import` instead of being replaced by the production ones:

```php
// config/deployer-sync.php
return [
    'keep_local_paths' => ['imports'],
];
```

## Excluding tables from the database dump

The dump only contains data, so any table that doesn't exist locally makes the import fail.
Tables matching the `exclude_tables` patterns in `config/deployer-sync.php` are skipped
(`*` wildcards allowed). By default this covers phpMyAdmin's configuration storage (`pma__*`).
They don't need to be declared in `database/sync-schema.php`. For tables that exist locally,
use `'exclude'` in `database/sync-schema.php` instead.

## Syncing uploaded files on S3-compatible storage (e.g. DigitalOcean Spaces)

The ZIP only contains files on the server's local disk (`storage/app`). If your uploads disk is
S3-compatible, those files never touch that disk, so they're copied separately:

- `-o s3=true` on `sync:export-to-local` or `sync:prod-to-local` (or the `sync:prod-to-local-s3` task)
  makes the remote `sync:export` also copy them from the production bucket to a development bucket.
- The copy runs server-side, in the same remote call: no data passes through your machine or the
  deploy server's disk.
- `dep sync:clone-storage` runs only the copy, without a full export.
- Without `-o s3=true`, nothing S3-related runs.

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

```dotenv
SYNC_DEV_BUCKET=your-dev-bucket-name
```

- If your uploads disk isn't the app's default filesystem disk, set which disk to read from:

```dotenv
SYNC_DISK=s3
```

`sync:clone-storage` mirrors production into your development prefix: unchanged files (matched
by ETag) are skipped, and any file under your prefix that no longer exists in production is
**deleted**, including files you may have uploaded manually while testing locally. It's safe
and idempotent to run repeatedly.

If the configured uploads disk isn't S3-compatible, `--s3` is a no-op and `storage/app` files
continue to sync through the existing ZIP-based flow.