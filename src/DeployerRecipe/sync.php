<?php

namespace Deployer;

add('recipes', ['sync']);

desc('Sync files and database from remote server to local machine');
task('sync:prod-to-local', [
    'sync:export-to-local',
    'sync:import-from-local',
]);

desc('Import files and database from sync_export.zip'); 
task('sync:import-from-local', function () {
    $output = runLocally('{{local_php_path}} artisan sync:import');
    info($output);
});

desc('Export files and database from remote server and download to local machine');
task('sync:export-to-local', function () {
    run('cd {{release_or_current_path}} && {{bin/php}} artisan sync:export',
        real_time_output: true);
    download('{{release_or_current_path}}/storage/sync_export.zip', 'storage/sync_export.zip');
});
