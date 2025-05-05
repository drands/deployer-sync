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

### Only import files and database from sync_export.zip
```bash
dep sync:import-from-local
```