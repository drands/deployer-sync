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
     * Extensions of uploaded files added to the ZIP without compression: they are already compressed,
     * so deflating them barely shrinks the ZIP but makes "sync:export" several times slower.
     */
    'store_uncompressed' => [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic',
        'mp4', 'mov', 'webm', 'mp3', 'm4a',
        'pdf', 'zip', 'gz', 'rar', '7z',
        'docx', 'xlsx', 'pptx', 'odt', 'ods',
        'woff', 'woff2',
    ],

    /*
     * Top-level paths inside storage/app (e.g. 'imports') that "sync:import" keeps instead of replacing
     * them with the production ones: local-only working files. Production files under them are discarded.
     */
    'keep_local_paths' => [],

];
