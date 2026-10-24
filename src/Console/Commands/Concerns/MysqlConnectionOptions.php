<?php

namespace Drands\DeployerSync\Console\Commands\Concerns;

trait MysqlConnectionOptions
{
    /**
     * Build the --socket or --host/--port options for mysql/mysqldump from the app's connection config,
     * so they connect the same way the app does (without them, they'd always use the local socket).
     */
    protected function mysqlConnectionOptions(): string
    {
        $socket = config('database.connections.mysql.unix_socket');
        if ($socket) {
            return ' --socket=' . escapeshellarg($socket);
        }

        $options = '';

        $host = config('database.connections.mysql.host');
        $host = is_array($host) ? reset($host) : $host;
        if ($host) {
            $options .= ' --host=' . escapeshellarg($host);
        }

        $port = config('database.connections.mysql.port');
        if ($port) {
            $options .= ' --port=' . escapeshellarg((string) $port);
        }

        return $options;
    }
}
