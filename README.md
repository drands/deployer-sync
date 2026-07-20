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

## Usage

### Full sync files and database from remote server to local machine
```bash
dep sync:prod-to-local
```

### Only export files and database from remote server and download to local machine
```bash
dep sync:export-to-local
```

Add `-o s3=true` (to either command above) to also clone uploaded files from the production
bucket to your local development bucket, if your uploads disk is S3-compatible:
```bash
dep sync:prod-to-local -o s3=true
```

### Only import files and database from sync_export.zip
```bash
dep sync:import-from-local
```

### Only clone uploaded files from the production bucket to your local development prefix
```bash
dep sync:clone-storage
```

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

If the configured uploads disk isn't S3-compatible, `--s3` is a no-op and `storage/app` files
continue to sync through the existing ZIP-based flow.