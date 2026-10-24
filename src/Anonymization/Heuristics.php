<?php

namespace Drands\DeployerSync\Anonymization;

use Illuminate\Support\Str;

/**
 * Guesses, from table and column names, what probably holds personal data.
 * Used by "sync:check" to flag tables marked as 'keep' and by "sync:check --fix" to suggest strategies.
 */
class Heuristics
{
    /**
     * Tables that only hold transient or secret data, suggested as 'exclude'.
     */
    protected const EXCLUDED_TABLES = [
        'sessions',
        'password_reset_tokens',
        'password_resets',
        'personal_access_tokens',
        'jobs',
        'job_batches',
        'failed_jobs',
        'cache',
        'cache_locks',
        'telescope_*',
        'pulse_*',
        'oauth_access_tokens',
        'oauth_refresh_tokens',
        'oauth_auth_codes',
    ];

    /**
     * Column name patterns => suggested strategy. First match wins, so order matters
     * (e.g. ip_address must be matched before *_address).
     */
    protected const COLUMNS = [
        [['password', '*_password', 'contrasena'], 'password'],
        [['remember_token', 'token', '*_token', 'api_key', '*_api_key', 'secret', '*_secret', 'two_factor_recovery_codes'], null],
        [['email'], 'unique_email'],
        [['*_email', 'email_address', 'correo', 'correo_electronico'], 'safeEmail'],
        [['ip', 'ip_address', '*_ip', '*_ip_address'], 'ipv4'],
        [['user_agent'], 'userAgent'],
        [['first_name', '*_first_name', 'firstname'], 'firstName'],
        [['last_name', '*_last_name', 'lastname', 'surname', 'apellido', 'apellidos'], 'lastName'],
        [['full_name', '*_full_name', 'fullname', 'customer_name', 'billing_name', 'shipping_name', 'contact_name', 'nombre_completo'], 'name'],
        [['phone', '*_phone', 'phone_number', '*_phone_number', 'mobile', '*_mobile', 'movil', 'telefono', '*_telefono'], 'phoneNumber'],
        [['address', '*_address', 'address_line_*', '*_address_line_*', 'address_?', '*_address_?', 'street', '*_street', 'direccion', '*_direccion'], 'streetAddress'],
        [['city', '*_city', 'ciudad', 'poblacion', 'localidad'], 'city'],
        [['zip', '*_zip', 'zip_code', '*_zip_code', 'zipcode', 'postcode', '*_postcode', 'postal_code', '*_postal_code', 'codigo_postal'], 'postcode'],
        [['dni', '*_dni', 'nif', '*_nif', 'nie', 'vat_number', '*_vat_number', 'vat_id', 'tax_id', '*_tax_id', 'document_number', 'id_number', 'passport', 'passport_number'], 'regexify:[0-9]{8}[A-Z]'],
        [['iban', '*_iban'], 'iban'],
        [['card_number', '*_card_number', 'credit_card', 'credit_card_number'], 'creditCardNumber'],
        [['birthday', 'birthdate', 'birth_date', 'date_of_birth', 'dob', 'fecha_nacimiento'], 'date'],
        [['lat', '*_lat', 'latitude', '*_latitude'], 'latitude'],
        [['lng', '*_lng', 'lon', 'longitude', '*_longitude'], 'longitude'],
        // Free text written by or about customers.
        [['note', 'notes', '*_note', '*_notes', 'comment', 'comments', '*_comment', '*_comments', 'message', '*_message', 'observaciones', 'comentarios'], 'paragraph'],
    ];

    /**
     * Tables that hold people, where a generic "name" column is a person's name
     * (elsewhere it's usually a product, category... name).
     */
    protected const PEOPLE_TABLES = [
        'users', '*_users', 'customers', '*_customers', 'clients', 'contacts', 'members', 'employees',
        'people', 'persons', 'subscribers', 'leads', 'usuarios', 'clientes', 'contactos', 'empleados',
    ];

    protected const PEOPLE_NAME_COLUMNS = [
        [['name', 'nombre'], 'name'],
    ];

    public function shouldExclude(string $table): bool
    {
        return Str::is(self::EXCLUDED_TABLES, $table);
    }

    public function looksPersonal(string $table, string $column): bool
    {
        return $this->match($table, $column) !== false;
    }

    /**
     * Suggested strategy for a column: a strategy for columns that look personal, 'keep' otherwise.
     *
     * @param array{name: string, nullable: bool, max_length: ?int} $column
     */
    public function suggest(string $table, array $column): ?string
    {
        $strategy = $this->match($table, $column['name']);

        if ($strategy === false) {
            return Strategies::KEEP;
        }

        // Secrets can't be nulled on NOT NULL columns, so replace them with a random hash instead.
        if ($strategy === null && !$column['nullable']) {
            return 'sha256';
        }

        return $strategy;
    }

    /**
     * @return string|null|false The suggested strategy, or false if the column doesn't look personal.
     */
    protected function match(string $table, string $column): string|null|false
    {
        $column = strtolower($column);

        $rules = Str::is(self::PEOPLE_TABLES, strtolower($table))
            ? [...self::PEOPLE_NAME_COLUMNS, ...self::COLUMNS]
            : self::COLUMNS;

        foreach ($rules as [$patterns, $strategy]) {
            if (Str::is($patterns, $column)) {
                return $strategy;
            }
        }

        return false;
    }
}
