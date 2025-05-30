<?php

namespace Drands\DeployerSync\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SyncExport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:export {--clean : Only clean up old files without creating a new ZIP file}';

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
        $command = "$mysqlDumpPath --user=\"$dbUsername\" --password=\"$dbPassword\" --no-create-info --no-tablespaces --replace --skip-triggers $dbName > $path";
        exec($command, $output, $outputCode);
        if ($outputCode !== 0) {
            return false;
        }

        return $path;
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
