<?php

namespace Drands\DeployerSync\Anonymization;

use Faker\Generator;

/**
 * Custom strategy for columns the built-in ones don't cover (e.g. JSON columns).
 * Reference the implementing class by its FQCN in database/sync-schema.php.
 */
interface Anonymizer
{
    /**
     * @param mixed $value Original (non-null) value of the column.
     * @param array $row Whole original row, keyed by column name.
     * @param Generator $faker Faker instance, already seeded for this row.
     */
    public function anonymize(mixed $value, array $row, Generator $faker): mixed;
}
