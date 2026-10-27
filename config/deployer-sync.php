<?php

return [

    /*
     * Disk that holds user-uploaded files in production.
     * Leave null to fall back to filesystems.default (resolved at runtime).
     * Only set SYNC_DISK if the uploads disk differs from the app's default disk.
     */
    'disk' => env('SYNC_DISK'),

    /*
     * Destination bucket used by "sync:clone-storage" when the uploads disk
     * is S3-compatible (e.g. DigitalOcean Spaces). Shared by all developers;
     * each one writes to their own prefix (see filesystems.disks.s3.root / DO_PREFIX).
     * Only needs to be set in the production environment.
     */
    'dev_bucket' => env('SYNC_DEV_BUCKET'),

    /*
     * Tables left out of the database dump made by "sync:export".
     * Supports "*" wildcards (e.g. phpMyAdmin's configuration storage tables),
     * since tables that don't exist locally make the import fail.
     */
    'exclude_tables' => [
        'pma__*',
    ],

    /*
     * Paths of the mysqldump (used by "sync:export") and mysql (used by "sync:import") binaries.
     * mysqldump_path falls back to database.connections.mysql.dump_path, then to "mysqldump".
     * Host, port or socket and credentials come from database.connections.mysql.
     */
    'mysqldump_path' => env('SYNC_MYSQLDUMP_PATH'),

    'mysql_path' => env('SYNC_MYSQL_PATH', 'mysql'),

    /*
     * File declaring how every table is exported (kept, excluded or anonymized).
     * Leave null for database/sync-schema.php. Maintained with "php artisan sync:check --fix".
     */
    'schema_path' => null,

    /*
     * Locale of the Faker instance used to anonymize data. Leave null to use app.faker_locale.
     */
    'faker_locale' => null,

    /*
     * Admin user restored by "php artisan sync:local-admin", which "dep sync:import-from-local" (and so
     * "dep sync:prod-to-local") offers to run after importing, since the import replaces your local users
     * with the anonymized production ones.
     *
     * - model:      Eloquent model of the users that log into the admin.
     * - id:         primary key updated by default (the alternative is creating a new user).
     * - fields:     columns asked for, with their defaults (set them in your .env). Empty values keep
     *               what the imported row has. "password" is hashed.
     * - attributes: values forced on every restored admin, e.g. ['is_admin' => true].
     * - reset:      columns set to NULL, e.g. remember tokens or 2FA secrets.
     */
    'local_admin' => [
        'model' => env('SYNC_ADMIN_MODEL', 'App\\Models\\User'),
        'id' => 1,
        'fields' => [
            'name' => env('SYNC_ADMIN_NAME'),
            'email' => env('SYNC_ADMIN_EMAIL'),
            'password' => env('SYNC_ADMIN_PASSWORD'),
        ],
        'attributes' => [],
        'reset' => ['remember_token'],
    ],

];
