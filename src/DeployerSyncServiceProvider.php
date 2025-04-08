<?php

namespace Drands\DeployerSync;

use Illuminate\Support\ServiceProvider;

class DeployerSyncServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Drands\DeployerSync\Console\Commands\SyncExport::class,
                \Drands\DeployerSync\Console\Commands\SyncImport::class,
            ]);
        }
    }
}
