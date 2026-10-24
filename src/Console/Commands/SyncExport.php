<?php

namespace Drands\DeployerSync\Console\Commands;

use Drands\DeployerSync\Anonymization\Strategies;
use Drands\DeployerSync\Anonymization\SyncSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SyncExport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:export
        {--clean : Only clean up old files without creating a new ZIP file}
        {--s3 : Also clone uploaded files from the production bucket to the development bucket}
        {--dest-prefix= : Prefix to write copies under in the development bucket (used with --s3)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates a ZIP file containing a database dump and uploaded files.';

    protected $zipFileName = 'sync_export.zip';

    protected $zipFilePath;

    protected $zip;

    protected $lockFilePath;

    protected SyncSchema $schema;


    public function __construct()
    {
        parent::__construct();
        $this->lockFilePath = storage_path('sync.lock');
        $this->zipFilePath = storage_path() . DIRECTORY_SEPARATOR . $this->zipFileName;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting Sync Export...');

        $this->checkLockFile();

        $this->checkStorageDirectoryPermissions();

        $this->cleanUpOldFile();

        $onlyClean = $this->option('clean');
        if ($onlyClean) {
            $this->info('Clean option is set. Only cleaning up old files.');
            return;
        }

        // Refuse to export anything while some table's data isn't declared as kept, excluded or anonymized.
        $this->schema = SyncSchema::make();
        $issues = $this->schema->issues(strict: false);
        if ($issues) {
            $this->error($this->schema->relativePath() . ' is not up to date, exporting could leak personal data:');
            foreach ($issues as $issue) {
                $this->line('  - ' . $issue);
            }
            $this->error('Run "php artisan sync:check --fix" locally, review the result, commit and deploy it.');

            return self::FAILURE;
        }

        $this->info('Creating ZIP file...');
        $this->zip = new \ZipArchive();
        if ($this->zip->open($this->zipFilePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return $this->error('Failed to create ZIP file.');
        }

        $dbDumpPath = $this->databaseDump();
        if ($dbDumpPath) {
            $this->zip->addFile($dbDumpPath, 'database_dump.sql');
            $this->info('Database dump created successfully.');
        } else {
            return $this->error('Failed to create database dump.');
        }

        $this->addUploadedFilesToZip();

        $this->zip->close();

        unlink($dbDumpPath); // Remove the database dump file after zip close

        $this->info('ZIP file created successfully.');

        if ($this->option('s3')) {
            $this->call(SyncCloneStorage::class, [
                '--dest-prefix' => $this->option('dest-prefix'),
            ]);
        }
    }


    protected function cleanUpOldFile()
    {
        $this->info('Cleaning up old file...');
        if (file_exists($this->zipFilePath)) {
            unlink($this->zipFilePath);
            $this->info('Old file deleted.');
        } else {
            $this->info('No old file found.');
        }
    }

    protected function databaseDump()
    {
        $this->info('Creating database dump...');

        $path = storage_path('temp_database_dump.sql');
        $output = "";
        $outputCode = 0;
        $mysqlDumpPath = config('database.connections.mysql.dump_path', 'mysqldump');
        $dbUsername = config('database.connections.mysql.username', 'root');
        $dbPassword = config('database.connections.mysql.password', '');
        $dbName = config('database.connections.mysql.database', 'forge');
        // Redirect stderr to the original stdout (captured by exec()) *before* redirecting
        // stdout to the dump file, so mysqldump errors/warnings don't end up mixed into the
        // dump file's SQL content.
        $command = escapeshellarg($mysqlDumpPath)
            . ' --user=' . escapeshellarg($dbUsername)
            . ' --password=' . escapeshellarg($dbPassword)
            . ' --no-create-info --no-tablespaces --replace --skip-triggers '
            . $this->ignoreTableOptions($dbName)
            . escapeshellarg($dbName)
            . ' 2>&1 1>' . escapeshellarg($path);
        exec($command, $output, $outputCode);
        if ($outputCode !== 0) {
            $this->error(implode("\n", $output));
            return false;
        }

        $this->appendAnonymizedTables($path);

        return $path;
    }

    /**
     * Build the --ignore-table options for the tables matching the configured exclude patterns,
     * the ones excluded in the sync schema and the anonymized ones (dumped by appendAnonymizedTables()).
     * mysqldump doesn't support wildcards, so patterns are resolved against the existing tables.
     */
    protected function ignoreTableOptions(string $dbName): string
    {
        $patterns = config('deployer-sync.exclude_tables', []);
        $skipped = $this->schema->tablesSkippedByMysqldump();
        $anonymized = $this->schema->anonymizedTables();

        $tables = array_map(fn ($row) => array_values((array) $row)[0], DB::select('SHOW TABLES'));

        $options = '';
        foreach ($tables as $table) {
            if (Str::is($patterns, $table) || in_array($table, $skipped, true)) {
                if (!isset($anonymized[$table])) {
                    $this->info('Excluding table: ' . $table);
                }
                $options .= '--ignore-table=' . escapeshellarg($dbName . '.' . $table) . ' ';
            }
        }

        return $options;
    }

    /**
     * Append the anonymized tables to the dump, in the same format mysqldump uses (REPLACE INTO).
     * Rows are read in chunks and Faker is seeded per row, so every export generates the same fake values.
     */
    protected function appendAnonymizedTables(string $path)
    {
        $tables = $this->schema->anonymizedTables();
        if (!$tables) {
            return;
        }

        $file = fopen($path, 'a');
        // Same session settings as mysqldump's header, which its footer resets before our rows.
        fwrite($file, "\n-- Anonymized tables (drands/deployer-sync)\n"
            . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");

        foreach ($tables as $table => $strategies) {
            $this->info('Anonymizing table: ' . $table);
            $this->dumpAnonymizedTable($file, $table, $strategies);
        }

        fwrite($file, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($file);
    }

    protected function dumpAnonymizedTable($file, string $table, array $strategies)
    {
        $columns = collect($this->schema->columns($table))->keyBy('name');
        $primaryKey = $this->schema->inspector()->primaryKey($table);
        $anonymizer = $this->schema->strategies();
        $pdo = DB::getPdo();

        $query = DB::table($table);
        if (count($primaryKey) === 1) {
            $rows = $query->lazyById(1000, $primaryKey[0]);
        } else {
            foreach ($primaryKey ?: $columns->keys()->all() as $column) {
                $query->orderBy($column);
            }
            $rows = $query->lazy(1000);
        }

        $insert = 'REPLACE INTO ' . $this->quoteIdentifier($table) . ' ('
            . $columns->keys()->map(fn ($column) => $this->quoteIdentifier($column))->implode(',') . ') VALUES ';
        $batch = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $rowKey = $primaryKey
                ? implode('-', array_map(fn ($column) => $row[$column], $primaryKey))
                : md5(serialize($row));
            $anonymizer->faker()->seed(crc32($table . '|' . $rowKey));

            $values = [];
            foreach ($columns as $name => $column) {
                $strategy = $strategies[$name];
                $value = $anonymizer->apply($strategy, $row[$name], $row, $rowKey);
                if ($strategy !== Strategies::KEEP && is_string($value) && $column['max_length'] && mb_strlen($value) > $column['max_length']) {
                    $value = mb_substr($value, 0, $column['max_length']);
                }
                $values[] = $this->sqlValue($value, $pdo);
            }
            $batch[] = '(' . implode(',', $values) . ')';

            if (count($batch) === 500) {
                fwrite($file, $insert . implode(',', $batch) . ";\n");
                $batch = [];
            }
        }

        if ($batch) {
            fwrite($file, $insert . implode(',', $batch) . ";\n");
        }
    }

    protected function sqlValue(mixed $value, \PDO $pdo): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => (string) (int) $value,
            is_int($value), is_float($value) => (string) $value,
            mb_check_encoding((string) $value, 'UTF-8') => $pdo->quote((string) $value),
            default => '0x' . bin2hex($value), // Binary data
        };
    }

    protected function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    protected function addUploadedFilesToZip()
    {
        $this->info('Adding uploaded files to ZIP...');

        // add all files from the storage/app/* directories excluding the livewire-tmp directory, keep the directory structure
        $basePath = storage_path('app');
        $files = File::allFiles($basePath, hidden: true);

        foreach ($files as $file) {
            $filePath = $file->getPathname();

            if (Str::contains($filePath, 'livewire-tmp')) {
                continue;
            }

            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $filePath);
            $this->zip->addFile($filePath, $relativePath);
        }
    }

    protected function checkStorageDirectoryPermissions()
    {
        $storagePath = storage_path();
        if (!is_writable($storagePath)) {
            $this->error('Storage directory is not writable. Please check permissions.');
            exit(1);
        }
        $this->info('Storage directory is writable.');
    }

    protected function checkLockFile()
    {
        if (file_exists($this->lockFilePath)) {
            $this->error('Another sync process is running. Please wait until it finishes.');
            exit(1);
        }
        file_put_contents($this->lockFilePath, 'locked');
        register_shutdown_function(function () {
            $this->removeLockFile();
        });
    }
    protected function removeLockFile()
    {
        if (file_exists($this->lockFilePath)) {
            unlink($this->lockFilePath);
        }
    }
}
