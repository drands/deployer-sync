## Installation

```bash
composer require drands/deployer-sync dev-main
```

Add the following to your `deploy.yml` file:

```yml
import:
  - vendor/drands/deployer-sync/src/recipe/sync.php
```

## Usage

### Full sync files and database from remote server to local machine
```bash
dep sync:prod-to-local
```

### Only export files and database from remote server and download to local machine
```bash
dep export-to-local
```

### Only import files and database from sync_export.zip
```bash
dep sync:import-from-local
```