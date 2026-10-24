<?php

namespace Drands\DeployerSync\Anonymization;

use Faker\Generator;

/**
 * Resolves and applies the strategies declared in database/sync-schema.php:
 *
 * - 'keep'               the value is exported as is
 * - null                 the value is replaced by NULL
 * - 'static:<value>'     the value is replaced by <value>
 * - 'unique_email'       user<primary key>@example.test (keeps UNIQUE indexes happy)
 * - 'password'           bcrypt hash of "password", so you can log in locally
 * - '<formatter>'        any Faker formatter (e.g. 'name', 'phoneNumber')
 * - '<formatter>:<arg>'  Faker formatter with one argument (e.g. 'regexify:[0-9]{8}[A-Z]')
 * - '<FQCN>'             class implementing Anonymizer
 *
 * NULL values stay NULL whatever the strategy.
 */
class Strategies
{
    public const KEEP = 'keep';

    public const EXCLUDE = 'exclude';

    /**
     * Bcrypt hash of "password". Fixed (instead of hashed on each export) so dumps are reproducible.
     */
    protected const PASSWORD_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public function __construct(protected Generator $faker)
    {
    }

    public function faker(): Generator
    {
        return $this->faker;
    }

    /**
     * Returns an error message if the strategy can't be applied to the given column, null otherwise.
     *
     * @param array{name: string, nullable: bool, max_length: ?int} $column
     */
    public function validate(mixed $strategy, array $column): ?string
    {
        if ($strategy === null) {
            return $column['nullable'] ? null : 'is set to null but the column is NOT NULL';
        }

        if (!is_string($strategy)) {
            return 'has an invalid strategy (expected a string or null)';
        }

        if ($strategy === self::KEEP || $strategy === 'unique_email' || $strategy === 'password' || str_starts_with($strategy, 'static:')) {
            return null;
        }

        if ($strategy === self::EXCLUDE) {
            return "uses 'exclude', which is only allowed for whole tables";
        }

        if ($this->isClass($strategy)) {
            return is_subclass_of($strategy, Anonymizer::class)
                ? null
                : "uses class {$strategy}, which doesn't implement " . Anonymizer::class;
        }

        [$formatter] = explode(':', $strategy, 2);
        try {
            $this->faker->getFormatter($formatter);
        } catch (\InvalidArgumentException) {
            return "uses unknown Faker formatter '{$formatter}'";
        }

        return null;
    }

    /**
     * @param string $rowKey Stable identifier of the row (its primary key), used by 'unique_email'.
     */
    public function apply(mixed $strategy, mixed $value, array $row, string $rowKey): mixed
    {
        if ($strategy === self::KEEP || $value === null) {
            return $value;
        }

        if ($strategy === null) {
            return null;
        }

        if (str_starts_with($strategy, 'static:')) {
            return substr($strategy, strlen('static:'));
        }

        $result = match (true) {
            $strategy === 'unique_email' => 'user' . (ctype_alnum($rowKey) ? $rowKey : substr(md5($rowKey), 0, 12)) . '@example.test',
            $strategy === 'password' => self::PASSWORD_HASH,
            $this->isClass($strategy) => (new $strategy())->anonymize($value, $row, $this->faker),
            default => $this->format($strategy),
        };

        return $this->normalize($result);
    }

    protected function format(string $strategy): mixed
    {
        $parts = explode(':', $strategy, 2);

        return isset($parts[1])
            ? $this->faker->format($parts[0], [$parts[1]])
            : $this->faker->format($parts[0]);
    }

    protected function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_array($value), is_object($value) => json_encode($value),
            is_bool($value) => (int) $value,
            default => $value,
        };
    }

    protected function isClass(string $strategy): bool
    {
        return str_contains($strategy, '\\') && class_exists($strategy);
    }
}
