<?php

namespace Deployer;

add('recipes', ['sync']);

desc('Sync files and database from remote server to local machine');
task('sync:prod-to-local', [
    'sync:export-to-local',
    'sync:import-from-local',
]);

desc('Sync files and database from remote server to local machine, including S3 file clone');
task('sync:prod-to-local-s3', function () {
    set('s3', true);
    invoke('sync:prod-to-local');
});

desc('Import files and database from sync_export.zip');
task('sync:import-from-local', function () {
    $output = runLocally(escapeshellarg(get('local_php_path')) . ' artisan sync:import');
    info($output);
});

desc('Export files and database from remote server and download to local machine');
task('sync:export-to-local', function () {
    $artisanCommand = 'sync:export';

    if (get('s3', false)) {
        $destPrefix = trim(runLocally(escapeshellarg(get('local_php_path')) . ' artisan sync:config-value filesystems.disks.s3.root'));
        $artisanCommand .= ' --s3 --dest-prefix=' . escapeshellarg($destPrefix);
    }

    run("cd {{release_or_current_path}} && {{bin/php}} artisan $artisanCommand",
        forceOutput: true);
    download('{{release_or_current_path}}/storage/sync_export.zip', 'storage/sync_export.zip');
});

desc('Clone uploaded files from the production bucket to your local development prefix (S3-compatible disks only)');
task('sync:clone-storage', function () {
    $destPrefix = trim(runLocally(escapeshellarg(get('local_php_path')) . ' artisan sync:config-value filesystems.disks.s3.root'));
    run('cd {{release_or_current_path}} && {{bin/php}} artisan sync:clone-storage --dest-prefix=' . escapeshellarg($destPrefix),
        forceOutput: true);
});
