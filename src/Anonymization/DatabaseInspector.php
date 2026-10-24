<?php

namespace Drands\DeployerSync\Anonymization;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Reads tables, columns and primary keys of the default connection.
 * MySQL/MariaDB go through information_schema (works on every supported Laravel version);
 * other drivers (e.g. SQLite in tests) need Laravel 11+'s schema introspection.
 */
class DatabaseInspector
{
    protected Connection $connection;

    public function __construct(?Connection $connection = null)
    {
        $this->connection = $connection ?? DB::connection();
    }

    /**
     * @return string[]
     */
    public function tables(): array
    {
        if ($this->isMySql()) {
            return array_map(fn ($row) => $row->name, $this->connection->select(
                "SELECT TABLE_NAME AS name FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
            ));
        }

        $schema = $this->schemaBuilder();
        $tables = method_exists($schema, 'getCurrentSchemaName')
            ? $schema->getTables($schema->getCurrentSchemaName())
            : $schema->getTables();

        return array_column($tables, 'name');
    }

    /**
     * @return list<array{name: string, nullable: bool, max_length: ?int}>
     */
    public function columns(string $table): array
    {
        if ($this->isMySql()) {
            return array_map(fn ($row) => [
                'name' => $row->name,
                'nullable' => $row->nullable === 'YES',
                'max_length' => $row->max_length !== null ? (int) $row->max_length : null,
            ], $this->connection->select(
                'SELECT COLUMN_NAME AS name, IS_NULLABLE AS nullable, CHARACTER_MAXIMUM_LENGTH AS max_length
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
                [$table]
            ));
        }

        return array_map(fn ($column) => [
            'name' => $column['name'],
            'nullable' => (bool) $column['nullable'],
            'max_length' => null,
        ], $this->schemaBuilder()->getColumns($table));
    }

    /**
     * @return string[]
     */
    public function primaryKey(string $table): array
    {
        if ($this->isMySql()) {
            return array_map(fn ($row) => $row->name, $this->connection->select(
                "SELECT COLUMN_NAME AS name FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY'
                 ORDER BY ORDINAL_POSITION",
                [$table]
            ));
        }

        foreach ($this->schemaBuilder()->getIndexes($table) as $index) {
            if ($index['primary']) {
                return $index['columns'];
            }
        }

        return [];
    }

    protected function isMySql(): bool
    {
        return in_array($this->connection->getDriverName(), ['mysql', 'mariadb'], true);
    }

    protected function schemaBuilder()
    {
        $schema = $this->connection->getSchemaBuilder();

        if (!method_exists($schema, 'getColumns')) {
            throw new \RuntimeException('Schema introspection for the ' . $this->connection->getDriverName() . ' driver requires Laravel 11 or newer.');
        }

        return $schema;
    }
}
