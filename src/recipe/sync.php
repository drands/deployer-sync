<?php

namespace Deployer;

add('recipes', ['sync']);

// Max seconds for the remote export and the local import: Deployer's default_timeout (300)
// isn't enough for a few GB of uploads. The download has no timeout.
set('sync_timeout', 3600);

if (!function_exists(__NAMESPACE__ . '\\syncRunRemote')) {
    /**
     * run() with its output streamed and sync_timeout, on Deployer 7 (real_time_output) and 8 (forceOutput).
     */
    function syncRunRemote(string $command): string
    {
        static $outputArgument;
        $outputArgument ??= in_array('forceOutput', array_map(
            fn (\ReflectionParameter $parameter) => $parameter->getName(),
            (new \ReflectionFunction(__NAMESPACE__ . '\\run'))->getParameters()
        ), true) ? 'forceOutput' : 'real_time_output';

        return run($command, ...[$outputArgument => true, 'timeout' => get('sync_timeout')]);
    }
}

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
    $output = runLocally(escapeshellarg(get('local_php_path')) . ' artisan sync:import', timeout: get('sync_timeout'));
    info($output);
});

desc('Export files and database from remote server and download to local machine');
task('sync:export-to-local', function () {
    $artisanCommand = 'sync:export';

    if (get('s3', false)) {
        $destPrefix = trim(runLocally(escapeshellarg(get('local_php_path')) . ' artisan sync:config-value filesystems.disks.s3.root'));
        $artisanCommand .= ' --s3 --dest-prefix=' . escapeshellarg($destPrefix);
    }

    syncRunRemote("cd {{release_or_current_path}} && {{bin/php}} artisan $artisanCommand");
    download('{{release_or_current_path}}/storage/sync_export.zip', 'storage/sync_export.zip');
});

desc('Clone uploaded files from the production bucket to your local development prefix (S3-compatible disks only)');
task('sync:clone-storage', function () {
    $destPrefix = trim(runLocally(escapeshellarg(get('local_php_path')) . ' artisan sync:config-value filesystems.disks.s3.root'));
    syncRunRemote('cd {{release_or_current_path}} && {{bin/php}} artisan sync:clone-storage --dest-prefix=' . escapeshellarg($destPrefix));
});
