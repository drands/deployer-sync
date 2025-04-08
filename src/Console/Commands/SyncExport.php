<?php

namespace Drands\DeployerSync\Console\Commands;

use Illuminate\Console\Command;
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

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting Sync Export...');

        $this->zipFilePath = storage_path() . DIRECTORY_SEPARATOR . $this->zipFileName;

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

        $dumpPath = $this->databaseDump();
        if ($dumpPath) {
            $this->info('Database dump created successfully.');
            $this->zip->addFile($dumpPath, 'database_dump.sql');
        } else {
            return $this->error('Failed to create database dump.');
        }
        
        $this->addUploadedFilesToZip();

        $this->zip->close();

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

        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'database_dump.sql';
        $output = "";
        $outputCode = 0;
        $mysqlDumpPath = config('database.connections.mysql.dump_path', 'mysqldump');
        $dbUsername = config('database.connections.mysql.username', 'root');
        $dbPassword = config('database.connections.mysql.password', '');
        $dbName = config('database.connections.mysql.database', 'forge');
        $command = "$mysqlDumpPath --user=\"$dbUsername\" --password=\"$dbPassword\" --no-create-info --replace --skip-triggers $dbName > $path";
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
        $files = glob(storage_path('app') . DIRECTORY_SEPARATOR . '*/*.*');
        foreach ($files as $file) {
            if (Str::contains($file, 'livewire-tmp')) {
                continue;
            }
            $this->zip->addFile($file, str_replace(storage_path('app') . DIRECTORY_SEPARATOR, '', $file));
        }
    }

}
