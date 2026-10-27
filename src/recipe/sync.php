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

    // Not an after() hook: those are skipped when the task runs through invoke() (e.g. sync:prod-to-local-s3).
    invoke('sync:local-admin');
});

desc('Create or update a local admin user (run after sync:import-from-local; disable with set(\'sync_local_admin\', false))');
task('sync:local-admin', function () {
    if (!get('sync_local_admin', true)) {
        return;
    }

    $artisan = escapeshellarg(get('local_php_path')) . ' artisan sync:local-admin';
    $defaults = json_decode(runLocally("$artisan --defaults"), true);
    $ask = $defaults['ask_defaults']; // false: update the default id with the .env values, asking only for empty fields

    if ($ask && !askConfirmation('Create or update a local admin user?', true)) {
        return;
    }

    $create = $ask && askConfirmation('Create a new user instead of updating an existing one?', false);
    $options = $create ? ' --create' : ' --id=' . escapeshellarg($ask ? (string) ask('Id of the user to update', $defaults['id']) : $defaults['id']);

    if (!$ask) {
        info("Restoring the local admin: updating user #{$defaults['id']} with the SYNC_ADMIN_* values from your .env");
    }

    foreach ($defaults['fields'] as $field => $default) {
        $default = $default === null || $default === '' ? null : (string) $default;
        $label = ucfirst(str_replace('_', ' ', $field));
        $value = match (true) {
            !$ask && $default !== null => $default,
            $field === 'password' && $default === null => askHiddenResponse('Local admin password (empty keeps the imported one)'),
            default => ask($label, $default),
        };
        if ($value !== null && $value !== '') {
            $options .= ' --field=' . escapeshellarg("$field=$value");
        }
    }

    info(runLocally($artisan . $options));
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
