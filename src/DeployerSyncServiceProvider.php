<?php

namespace Drands\DeployerSync;

use Illuminate\Console\Events\CommandFinished;
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
                \Drands\DeployerSync\Console\Commands\SyncCheck::class,
                \Drands\DeployerSync\Console\Commands\SyncInstallClaude::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/deployer-sync.php' => config_path('deployer-sync.php'),
            ], 'deployer-sync-config');

            $this->installClaudeFilesAfterCommands();
        }
    }

    /**
     * Keeps the Claude Code hook, skill and test installed in local environments. Composer can't run
     * scripts from dependencies, so this hooks into artisan instead: "package:discover" runs after every
     * "composer install/update", but on a first install it runs before this provider is discovered,
     * so any other artisan command (silently) installs them too. The changes show up in git status.
     */
    protected function installClaudeFilesAfterCommands()
    {
        if (!$this->app->environment('local')) {
            return;
        }

        $installed = false;
        $this->app['events']->listen(CommandFinished::class, function (CommandFinished $event) use (&$installed) {
            if ($installed || $event->exitCode !== 0) {
                return;
            }
            $installed = true;

            try {
                $changes = (new ClaudeInstaller($this->app->basePath()))->install();
            } catch (\Throwable) {
                return; // Never break composer or the command that just ran.
            }

            if ($event->command === 'package:discover') {
                foreach ($changes as $change) {
                    $event->output->writeln('  <info>deployer-sync:</info> ' . $change);
                }
            }
        });
    }
}
