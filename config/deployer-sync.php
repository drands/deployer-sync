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

];
