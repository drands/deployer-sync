<?php

namespace Drands\DeployerSync;

use Illuminate\Support\ServiceProvider;

class DeployerSyncServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/deployer-sync.php', 'deployer-sync');
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Drands\DeployerSync\Console\Commands\SyncExport::class,
                \Drands\DeployerSync\Console\Commands\SyncImport::class,
                \Drands\DeployerSync\Console\Commands\SyncCloneStorage::class,
                \Drands\DeployerSync\Console\Commands\SyncConfigValue::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/deployer-sync.php' => config_path('deployer-sync.php'),
            ], 'deployer-sync-config');
        }
    }
}
