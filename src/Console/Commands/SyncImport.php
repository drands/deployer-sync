<?php

namespace Drands\DeployerSync\Console\Commands;

use Illuminate\Console\Command;

class SyncImport extends Command
{
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
            return;
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
            return;
        }

        $this->info('Restoring database...');
        $dbDumpPath = $unzipPath . DIRECTORY_SEPARATOR . 'database_dump.sql';
        if (file_exists($dbDumpPath)) {
            // DB wipe (artisan db:wipe)
            $this->call('db:wipe', ['--force' => true]);
            $this->info('Database wiped successfully.');
            $this->call('migrate', ['--force' => true]);
            $this->info('Database migrated successfully.');

            $output = "";
            $outputCode = 0;
            $mysqlPath = config('database.connections.mysql.dump_path', 'mysql');
            $dbUsername = config('database.connections.mysql.username', 'root');
            $dbPassword = config('database.connections.mysql.password', '');
            $dbName = config('database.connections.mysql.database', 'forge');

            exec("$mysqlPath -u$dbUsername -p$dbPassword $dbName < $dbDumpPath", $output, $outputCode);

            if ($outputCode === 0) {
                $this->info('Database restored successfully.');
                unlink($dbDumpPath); // Remove the dump file after restoring
            } else {
                $this->error('Failed to restore the database.');
                return;
            }
        } else {
            $this->error('Database dump file not found.');
            return;
        }

        $this->info('Restoring uploaded files...');
        
        //remove all directories and files in the app directory
        $this->cleanDirectory(storage_path('app'));

        // Move files from the unzipped directory to the app directory
        $files = glob($unzipPath . DIRECTORY_SEPARATOR . '*');
        foreach ($files as $file) {
            $destination = storage_path('app') . DIRECTORY_SEPARATOR . basename($file);
            if (is_dir($file)) {
                rename($file, $destination);
            } else {
                copy($file, $destination);
            }
        }
        $this->info('Uploaded files restored successfully.');

        // Clean up
        rmdir($unzipPath); // Remove the unzipped directory
        unlink($zipFilePath); // Remove the ZIP file after processing

        $this->info('Sync Import completed successfully.');
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
