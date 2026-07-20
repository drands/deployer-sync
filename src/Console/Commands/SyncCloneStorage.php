<?php

namespace Drands\DeployerSync\Console\Commands;

use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SyncCloneStorage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:clone-storage {--dest-prefix= : Prefix to write copies under in the development bucket, e.g. local/kolasin}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clones uploaded files from the production S3-compatible bucket to a development bucket, server-side.';

    public function handle()
    {
        $disk = config('deployer-sync.disk') ?: config('filesystems.default');
        $diskConfig = config("filesystems.disks.$disk");

        if (($diskConfig['driver'] ?? null) !== 's3') {
            $this->info("Disk [$disk] is not S3-compatible. Skipping cloud storage clone.");
            return;
        }

        $devBucket = config('deployer-sync.dev_bucket');
        if (!$devBucket) {
            $this->error('SYNC_DEV_BUCKET is not configured. Skipping cloud storage clone.');
            return;
        }

        $sourceBucket = $diskConfig['bucket'] ?? null;
        if (!$sourceBucket) {
            $this->error("Disk [$disk] has no bucket configured.");
            return;
        }

        $sourceRoot = trim((string) ($diskConfig['root'] ?? ''), '/');
        $destPrefix = trim((string) $this->option('dest-prefix'), '/');

        $client = $this->buildS3Client($diskConfig);
        $isPublic = ($diskConfig['visibility'] ?? null) === 'public';

        $this->info("Cloning s3://{$sourceBucket}/{$sourceRoot} -> s3://{$devBucket}/{$destPrefix} ...");

        // Existing destination objects, keyed by full key, so we can skip unchanged
        // files and detect which ones no longer exist in production.
        $destinationEtags = [];
        $destPaginator = $client->getPaginator('ListObjectsV2', [
            'Bucket' => $devBucket,
            'Prefix' => $destPrefix !== '' ? $destPrefix . '/' : '',
        ]);
        foreach ($destPaginator as $result) {
            foreach ($result['Contents'] ?? [] as $object) {
                $destinationEtags[$object['Key']] = $object['ETag'];
            }
        }

        $seenDestKeys = [];
        $copied = 0;
        $skipped = 0;

        $sourcePaginator = $client->getPaginator('ListObjectsV2', [
            'Bucket' => $sourceBucket,
            'Prefix' => $sourceRoot !== '' ? $sourceRoot . '/' : '',
        ]);

        foreach ($sourcePaginator as $result) {
            foreach ($result['Contents'] ?? [] as $object) {
                $key = $object['Key'];

                if (Str::contains($key, 'livewire-tmp')) {
                    continue;
                }

                $relative = $sourceRoot !== '' ? Str::after($key, $sourceRoot . '/') : $key;
                $destKey = $destPrefix !== '' ? $destPrefix . '/' . $relative : $relative;
                $seenDestKeys[$destKey] = true;

                if (($destinationEtags[$destKey] ?? null) === $object['ETag']) {
                    // Content unchanged, but still make sure visibility is correct
                    // (e.g. objects copied before this ACL fix existed).
                    if ($isPublic) {
                        $client->putObjectAcl([
                            'Bucket' => $devBucket,
                            'Key' => $destKey,
                            'ACL' => 'public-read',
                        ]);
                    }
                    $skipped++;
                    continue;
                }

                $copyParams = [
                    'Bucket' => $devBucket,
                    'Key' => $destKey,
                    'CopySource' => $sourceBucket . '/' . str_replace('%2F', '/', rawurlencode($key)),
                ];

                if ($isPublic) {
                    $copyParams['ACL'] = 'public-read';
                }

                $client->copyObject($copyParams);

                $copied++;
            }
        }

        // Remove destination objects that no longer exist in production, so the
        // development prefix stays a mirror instead of accumulating stale files.
        $staleKeys = array_diff(array_keys($destinationEtags), array_keys($seenDestKeys));
        foreach (array_chunk($staleKeys, 1000) as $chunk) {
            $client->deleteObjects([
                'Bucket' => $devBucket,
                'Delete' => [
                    'Objects' => array_map(fn ($key) => ['Key' => $key], $chunk),
                    'Quiet' => true,
                ],
            ]);
        }

        $this->info(sprintf(
            'Copied %d, skipped %d unchanged, removed %d stale objects in s3://%s/%s.',
            $copied,
            $skipped,
            count($staleKeys),
            $devBucket,
            $destPrefix
        ));
    }

    protected function buildS3Client(array $diskConfig): S3Client
    {
        $options = [
            'version' => 'latest',
            'region' => $diskConfig['region'] ?? 'us-east-1',
            'credentials' => [
                'key' => $diskConfig['key'],
                'secret' => $diskConfig['secret'],
            ],
        ];

        if (!empty($diskConfig['endpoint'])) {
            $options['endpoint'] = $diskConfig['endpoint'];
            $options['use_path_style_endpoint'] = $diskConfig['use_path_style_endpoint'] ?? true;
        }

        return new S3Client($options);
    }
}
