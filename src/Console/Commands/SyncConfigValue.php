<?php

namespace Drands\DeployerSync\Console\Commands;

use Illuminate\Console\Command;

class SyncConfigValue extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:config-value {key : The config key to print, e.g. filesystems.disks.s3.root}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prints a resolved config value. Used by the Deployer recipe to read local config before running remote commands.';

    public function handle()
    {
        $this->line((string) config($this->argument('key')));
    }
}
