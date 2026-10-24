<?php

namespace Drands\DeployerSync\Anonymization;

use Illuminate\Support\Str;

/**
 * The project's database/sync-schema.php: declares, for every table, whether "sync:export"
 * dumps it as is ('keep'), leaves it out ('exclude') or anonymizes it column by column.
 * Any table (or column of an anonymized table) missing from it makes the export fail,
 * so new tables never reach developers' machines without someone deciding what they hold.
 */
class SyncSchema
{
    /**
     * Tables that never hold personal data and don't need to be declared.
     */
    protected const IMPLICIT_TABLES = ['migrations'];

    protected ?array $tables = null;

    protected array $columns = [];

    public function __construct(
        protected DatabaseInspector $inspector,
        protected Heuristics $heuristics,
        protected Strategies $strategies,
        protected string $path,
    ) {
    }

    public static function make(): static
    {
        $locale = config('deployer-sync.faker_locale') ?? config('app.faker_locale', 'en_US');

        return new static(
            new DatabaseInspector(),
            new Heuristics(),
            new Strategies(\Faker\Factory::create($locale)),
            config('deployer-sync.schema_path') ?? database_path('sync-schema.php'),
        );
    }

    public function path(): string
    {
        return $this->path;
    }

    public function relativePath(): string
    {
        return Str::after($this->path, base_path() . DIRECTORY_SEPARATOR);
    }

    public function strategies(): Strategies
    {
        return $this->strategies;
    }

    public function inspector(): DatabaseInspector
    {
        return $this->inspector;
    }

    /**
     * Problems that must be fixed before exporting.
     *
     * @param bool $strict Also report declared tables/columns that no longer exist. They're harmless
     *                     for the export (e.g. a migration not run yet), so only "sync:check" uses it.
     * @return string[]
     */
    public function issues(bool $strict = true): array
    {
        if (!file_exists($this->path)) {
            return [$this->relativePath() . " doesn't exist."];
        }

        $declarations = $this->declarations();
        $issues = [];

        foreach ($this->tables() as $table) {
            if (!array_key_exists($table, $declarations)) {
                $issues[] = "Table `{$table}` is not declared.";
                continue;
            }

            $declaration = $declarations[$table];

            if ($declaration === Strategies::EXCLUDE) {
                continue;
            }

            if ($declaration === Strategies::KEEP) {
                $personal = $this->personalColumns($table);
                if ($personal) {
                    $issues[] = "Table `{$table}` is marked 'keep' but these columns look personal: " . implode(', ', $personal)
                        . '. Declare it column by column (an explicit \'keep\' on a column is fine).';
                }
                continue;
            }

            if (!is_array($declaration)) {
                $issues[] = "Table `{$table}` has an invalid declaration (expected 'keep', 'exclude' or an array of columns).";
                continue;
            }

            $columns = collect($this->columns($table))->keyBy('name');

            foreach ($columns as $name => $column) {
                if (!array_key_exists($name, $declaration)) {
                    $issues[] = "Column `{$table}.{$name}` is not declared.";
                } elseif ($error = $this->strategies->validate($declaration[$name], $column)) {
                    $issues[] = "Column `{$table}.{$name}` {$error}.";
                }
            }

            if ($strict) {
                foreach (array_diff(array_keys($declaration), $columns->keys()->all()) as $name) {
                    $issues[] = "Column `{$table}.{$name}` is declared but doesn't exist.";
                }
            }
        }

        if ($strict) {
            foreach (array_diff(array_keys($declarations), $this->tables()) as $table) {
                if (!$this->isIgnored($table)) {
                    $issues[] = "Table `{$table}` is declared but doesn't exist.";
                }
            }
        }

        return $issues;
    }

    /**
     * Declares every missing table/column using the heuristics' suggestions, removes the ones that
     * no longer exist and writes the file. Invalid strategies are left for a human to fix.
     *
     * @return string[] Description of every change made.
     */
    public function fix(): array
    {
        $declarations = file_exists($this->path) ? $this->declarations() : [];
        $changes = [];

        foreach ($this->tables() as $table) {
            $columns = $this->columns($table);

            if (!array_key_exists($table, $declarations)) {
                $declarations[$table] = $this->suggestTable($table, $columns);
                $changes[] = "Added table `{$table}` as " . (is_array($declarations[$table]) ? 'anonymized' : "'{$declarations[$table]}'") . '.';
                continue;
            }

            $declaration = $declarations[$table];

            if ($declaration === Strategies::KEEP && $this->personalColumns($table)) {
                $declarations[$table] = $this->suggestColumns($table, $columns);
                $changes[] = "Table `{$table}` had columns that look personal; declared it column by column.";
                continue;
            }

            if (!is_array($declaration)) {
                continue;
            }

            $fixed = [];
            foreach ($columns as $column) {
                if (array_key_exists($column['name'], $declaration)) {
                    $fixed[$column['name']] = $declaration[$column['name']];
                } else {
                    $fixed[$column['name']] = $this->heuristics->suggest($table, $column);
                    $changes[] = "Added column `{$table}.{$column['name']}` as " . $this->export($fixed[$column['name']]) . '.';
                }
            }
            foreach (array_diff_key($declaration, $fixed) as $name => $strategy) {
                $changes[] = "Removed column `{$table}.{$name}`, which no longer exists.";
            }
            $declarations[$table] = $fixed;
        }

        foreach (array_diff(array_keys($declarations), $this->tables()) as $table) {
            if (!$this->isIgnored($table)) {
                unset($declarations[$table]);
                $changes[] = "Removed table `{$table}`, which no longer exists.";
            }
        }

        ksort($declarations);
        file_put_contents($this->path, $this->render($declarations));

        return $changes;
    }

    /**
     * Tables mysqldump must skip: excluded ones and the ones dumped (anonymized) from PHP.
     *
     * @return string[]
     */
    public function tablesSkippedByMysqldump(): array
    {
        $declarations = $this->declarations();

        return array_values(array_filter(
            $this->tables(),
            fn ($table) => ($declarations[$table] ?? null) === Strategies::EXCLUDE || is_array($declarations[$table] ?? null)
        ));
    }

    /**
     * @return array<string, array<string, string|null>> Table => column => strategy.
     */
    public function anonymizedTables(): array
    {
        return array_filter(
            array_intersect_key($this->declarations(), array_flip($this->tables())),
            'is_array'
        );
    }

    /**
     * @return list<array{name: string, nullable: bool, max_length: ?int}>
     */
    public function columns(string $table): array
    {
        return $this->columns[$table] ??= $this->inspector->columns($table);
    }

    /**
     * Existing tables that have to be declared.
     *
     * @return string[]
     */
    protected function tables(): array
    {
        return $this->tables ??= array_values(array_filter(
            $this->inspector->tables(),
            fn ($table) => !$this->isIgnored($table)
        ));
    }

    protected function isIgnored(string $table): bool
    {
        return in_array($table, self::IMPLICIT_TABLES, true)
            || Str::is(config('deployer-sync.exclude_tables', []), $table);
    }

    protected function declarations(): array
    {
        $declarations = require $this->path;

        if (!is_array($declarations)) {
            throw new \UnexpectedValueException($this->relativePath() . ' must return an array.');
        }

        return $declarations;
    }

    /**
     * @return string[]
     */
    protected function personalColumns(string $table): array
    {
        return array_values(array_filter(
            array_column($this->columns($table), 'name'),
            fn ($name) => $this->heuristics->looksPersonal($table, $name)
        ));
    }

    protected function suggestTable(string $table, array $columns): string|array
    {
        if ($this->heuristics->shouldExclude($table)) {
            return Strategies::EXCLUDE;
        }

        return $this->personalColumns($table) ? $this->suggestColumns($table, $columns) : Strategies::KEEP;
    }

    protected function suggestColumns(string $table, array $columns): array
    {
        $suggestions = [];
        foreach ($columns as $column) {
            $suggestions[$column['name']] = $this->heuristics->suggest($table, $column);
        }

        return $suggestions;
    }

    protected function render(array $declarations): string
    {
        $body = '';
        foreach ($declarations as $table => $declaration) {
            if (is_array($declaration)) {
                $body .= '    ' . $this->export($table) . " => [\n";
                foreach ($declaration as $column => $strategy) {
                    $body .= '        ' . $this->export($column) . ' => ' . $this->export($strategy) . ",\n";
                }
                $body .= "    ],\n\n";
            } else {
                $body .= '    ' . $this->export($table) . ' => ' . $this->export($declaration) . ",\n\n";
            }
        }

        return <<<PHP
        <?php

        /*
         * How "sync:export" dumps each table (see drands/deployer-sync's README):
         *
         * - 'keep'     dumped as is. Only allowed for tables without columns that look personal.
         * - 'exclude'  left out of the dump (the table is empty locally).
         * - [...]      anonymized: every column must be declared, with 'keep', null,
         *              'static:<value>', 'unique_email', 'password', a Faker formatter
         *              (optionally with one argument, e.g. 'regexify:[0-9]{8}[A-Z]') or the FQCN
         *              of a class implementing Drands\DeployerSync\Anonymization\Anonymizer.
         *
         * The export fails if any table or column is missing. "php artisan sync:check --fix"
         * adds them with suggested strategies: review them. This file is regenerated by --fix,
         * so comments added here are lost.
         */

        return [

        {$body}];

        PHP;
    }

    protected function export(mixed $value): string
    {
        return $value === null ? 'null' : var_export($value, true);
    }
}
