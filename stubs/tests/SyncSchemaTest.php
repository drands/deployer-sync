<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Installed by drands/deployer-sync: fails when database/sync-schema.php doesn't declare how every
 * table is exported, which would make "sync:export" fail in production.
 * Run "php artisan sync:check --fix" and review the result to fix it.
 */
class SyncSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_schema_declares_every_table(): void
    {
        $exitCode = Artisan::call('sync:check');

        $this->assertSame(0, $exitCode, Artisan::output());
    }
}
