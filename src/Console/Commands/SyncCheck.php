<?php

namespace Drands\DeployerSync\Console\Commands;

use Drands\DeployerSync\Anonymization\SyncSchema;
use Illuminate\Console\Command;

class SyncCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:check
        {--fix : Declare missing tables/columns with suggested strategies and remove the ones that no longer exist}
        {--hook : Run as a Claude Code Stop hook (reports to stderr and exits with code 2 on problems)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Checks that database/sync-schema.php declares how every table is exported.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('hook')) {
            return $this->handleHook();
        }

        $schema = SyncSchema::make();

        if ($this->option('fix')) {
            $changes = $schema->fix();
            foreach ($changes as $change) {
                $this->line('  ' . $change);
            }
            $this->info($changes ? 'Updated ' . $schema->relativePath() . '. Review the suggested strategies.' : 'Nothing to fix.');
        }

        $issues = $schema->issues();
        if ($issues) {
            $this->error($schema->relativePath() . ' is not up to date:');
            foreach ($issues as $issue) {
                $this->line('  - ' . $issue);
            }
            if (!$this->option('fix')) {
                $this->line('Run "php artisan sync:check --fix" to declare what is missing, then review the result.');
            }

            return self::FAILURE;
        }

        $this->info($schema->relativePath() . ' declares every table.');

        return self::SUCCESS;
    }

    /**
     * Stop hook: exit code 2 makes Claude Code keep working with stderr as feedback.
     * Never blocks twice in a row (stop_hook_active), nor when the check can't run at all
     * (e.g. the database is down): the tests and the export itself still catch it.
     */
    protected function handleHook(): int
    {
        $input = json_decode((string) stream_get_contents(STDIN), true);
        if (!empty($input['stop_hook_active'])) {
            return self::SUCCESS;
        }

        try {
            $schema = SyncSchema::make();
            $issues = $schema->issues();
        } catch (\Throwable) {
            return self::SUCCESS;
        }

        if (!$issues) {
            return self::SUCCESS;
        }

        fwrite(STDERR, $schema->relativePath() . " doesn't declare how every table is exported (drands/deployer-sync):\n"
            . implode("\n", array_map(fn ($issue) => '- ' . $issue, $issues)) . "\n"
            . 'Run "php artisan sync:check --fix" and review the result following the sync-schema skill. '
            . "If the migrations haven't been run yet, run them first.\n");

        return 2;
    }
}
