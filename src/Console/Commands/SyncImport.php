<?php

namespace Drands\DeployerSync\Console\Commands;

use Drands\DeployerSync\Console\Commands\Concerns\MysqlConnectionOptions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class SyncImport extends Command
{
    use MysqlConnectionOptions;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:import';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restores the database and uploaded files locally from a ZIP file.';

    protected $zipFileName = 'sync_export.zip';

    protected $hasGitignoreInPublic;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting Sync Import...');

        $zipFilePath = storage_path() . DIRECTORY_SEPARATOR . $this->zipFileName;

        // Check if the ZIP file exists
        if (!file_exists($zipFilePath)) {
            $this->error('ZIP file not found. Please create it first using sync:export.');
            return self::FAILURE;
        }

        $this->info('Unzipping the file...');
        $unzipPath = storage_path() . DIRECTORY_SEPARATOR . 'sync_import';
        if (!is_dir($unzipPath)) {
            mkdir($unzipPath, 0755, true);
        } else {
            $this->cleanDirectory($unzipPath);
        }

        $zip = new \ZipArchive();

        if ($zip->open($zipFilePath) === TRUE) {
            $zip->extractTo($unzipPath);
            $zip->close();
            $this->info('Unzipped successfully.');
        } else {
            $this->error('Failed to unzip the file.');
            return self::FAILURE;
        }

        $this->info('Restoring database...');
        $dbDumpPath = $unzipPath . DIRECTORY_SEPARATOR . 'database_dump.sql';
        if (file_exists($dbDumpPath)) {
            // DB wipe (artisan db:wipe)
            $this->call('db:wipe', ['--force' => true]);
            $this->info('Database wiped successfully.');
            $this->call('migrate', ['--force' => true]);
            $this->info('Database migrated successfully.');

            // The dump has values for stored generated columns, which MySQL rejects (error 3105):
            // they are plain columns while it loads, and generated again (recomputing every row) afterwards.
            $generatedColumns = $this->storedGeneratedColumns();
            try {
                $this->makeColumnsPlain($generatedColumns);
                [$outputCode, $output] = $this->restoreDump($dbDumpPath);
            } finally {
                $this->restoreGeneratedColumns($generatedColumns);
            }

            if ($outputCode === 0) {
                $this->info('Database restored successfully.');
                unlink($dbDumpPath); // Remove the dump file after restoring
            } else {
                $this->error('Failed to restore the database.');
                $this->error($output);
                return self::FAILURE;
            }
        } else {
            $this->error('Database dump file not found.');
            return self::FAILURE;
        }

        $this->info('Restoring uploaded files...');

        $appPath = storage_path('app');
        $keep = array_map(fn ($path) => trim($path, '/\\'), config('deployer-sync.keep_local_paths', []));

        // Remove everything in storage/app (but its hidden files and the local-only paths)...
        $this->info('Cleaning directory: ' . $appPath);
        foreach (File::glob($appPath . DIRECTORY_SEPARATOR . '*') as $path) {
            if (in_array(basename($path), $keep, true)) {
                $this->info('Keeping local ' . basename($path));
                continue;
            }
            is_dir($path) && !is_link($path) ? File::deleteDirectory($path) : File::delete($path);
        }

        // ...and move in the top-level directories and files (hidden ones included) from the ZIP.
        foreach (array_diff(scandir($unzipPath), ['.', '..']) as $name) {
            $source = $unzipPath . DIRECTORY_SEPARATOR . $name;
            if (in_array($name, $keep, true)) {
                is_dir($source) ? File::deleteDirectory($source) : File::delete($source);
                continue;
            }
            rename($source, $appPath . DIRECTORY_SEPARATOR . $name);
        }
        $this->info('Uploaded files restored successfully.');

        // Clean up
        rmdir($unzipPath); // Remove the unzipped directory
        unlink($zipFilePath); // Remove the ZIP file after processing

        $this->info('Sync Import completed successfully.');
    }

    /**
     * Pipe the dump into mysql, dropping the first line when it's MariaDB's sandbox-mode comment
     * ("/*M!999999\- enable the sandbox mode *\/", mariadb-dump 11.x), which the MySQL client rejects.
     *
     * @return array{0: int, 1: string} exit code and output of mysql
     */
    protected function restoreDump(string $dbDumpPath): array
    {
        $mysqlPath = config('deployer-sync.mysql_path') ?: 'mysql';
        $dbUsername = config('database.connections.mysql.username', 'root');
        $dbPassword = config('database.connections.mysql.password', '');
        $dbName = config('database.connections.mysql.database', 'forge');

        $command = escapeshellarg($mysqlPath) . $this->mysqlConnectionOptions() . ' -u' . escapeshellarg($dbUsername);
        if ($dbPassword !== '') {
            // Must stay attached to -p with no space; a bare -p makes mysql prompt interactively.
            $command .= ' -p' . escapeshellarg($dbPassword);
        }
        $command .= ' ' . escapeshellarg($dbName) . ' 2>&1';

        $outputPath = tempnam(sys_get_temp_dir(), 'sync_import_');
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $outputPath, 'w']], $pipes);
        if (!is_resource($process)) {
            return [1, 'Could not start ' . $mysqlPath];
        }

        $dump = fopen($dbDumpPath, 'r');
        $firstLine = fgets($dump);
        if ($firstLine !== false && !str_starts_with($firstLine, '/*M!999999')) {
            @fwrite($pipes[0], $firstLine);
        }
        // Errors writing here mean mysql already exited: its output says why.
        @stream_copy_to_stream($dump, $pipes[0]);
        fclose($dump);
        fclose($pipes[0]);

        $exitCode = proc_close($process);
        $output = trim((string) file_get_contents($outputPath));
        unlink($outputPath);

        return [$exitCode, $output];
    }

    /**
     * @return list<array{table: string, column: string, type: string, definition: string}>
     */
    protected function storedGeneratedColumns(): array
    {
        $columns = DB::select(
            "SELECT TABLE_NAME AS `table`, COLUMN_NAME AS `column`, COLUMN_TYPE AS `type`
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND EXTRA IN ('STORED GENERATED', 'PERSISTENT GENERATED')"
        );

        $definitions = [];

        return array_map(function ($column) use (&$definitions) {
            // SHOW CREATE TABLE gives the exact definition (expression, collation, NULL...) to put it back as it was;
            // information_schema's GENERATION_EXPRESSION comes escaped differently in MySQL and MariaDB.
            $definitions[$column->table] ??= array_values((array) DB::selectOne('SHOW CREATE TABLE ' . $this->quoteIdentifier($column->table)))[1];
            $prefix = $this->quoteIdentifier($column->column) . ' ';
            foreach (explode("\n", $definitions[$column->table]) as $line) {
                $line = rtrim(trim($line), ',');
                if (str_starts_with($line, $prefix)) {
                    return ['table' => $column->table, 'column' => $column->column, 'type' => $column->type, 'definition' => $line];
                }
            }

            throw new \RuntimeException("Definition of {$column->table}.{$column->column} not found");
        }, $columns);
    }

    protected function makeColumnsPlain(array $columns): void
    {
        foreach ($columns as $column) {
            DB::statement('ALTER TABLE ' . $this->quoteIdentifier($column['table'])
                . ' MODIFY ' . $this->quoteIdentifier($column['column']) . ' ' . $column['type'] . ' NULL');
        }
    }

    protected function restoreGeneratedColumns(array $columns): void
    {
        foreach ($columns as $column) {
            DB::statement('ALTER TABLE ' . $this->quoteIdentifier($column['table']) . ' MODIFY ' . $column['definition']);
        }
    }

    protected function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * Recursively clean a directory by removing all files and subdirectories but keeping the directory itself.
     *
     * @param string $dir
     * @return void
     */
    protected function cleanDirectory($dir)
    {
        $this->info('Cleaning directory: ' . $dir);

        if (!is_dir($dir)) {
            return;
        }

        system('rm -rf ' . escapeshellarg($dir) . '/*');
    }
}
