<?php

namespace Drands\DeployerSync\Console\Commands;

use Drands\DeployerSync\ClaudeInstaller;
use Illuminate\Console\Command;

class SyncInstallClaude extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:install-claude
        {--force : Also overwrite tests/Feature/SyncSchemaTest.php if it already exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Installs the Claude Code hook, skill and test that keep database/sync-schema.php up to date.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $changes = (new ClaudeInstaller(base_path()))->install($this->option('force'));

        foreach ($changes as $change) {
            $this->info($change);
        }

        if (!$changes) {
            $this->info('Everything is already installed.');
        }
    }
}
