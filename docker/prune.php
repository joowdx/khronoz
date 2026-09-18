<?php

/*
|--------------------------------------------------------------------------
| Remove unused AWS service definitions
|--------------------------------------------------------------------------
|
| aws/aws-sdk-php ships one monolithic package covering 436 services, and
| ~48 MB of that is machine-readable API models under src/data. They are not
| optional overhead: Aws\S3\S3Client declares no real operations, and every
| call is resolved at runtime by AwsClientTrait::__call() reading that
| service's api-2.json.php. Ship the model or the operation does not exist.
|
| This mirrors Aws\Script\Composer\Composer::removeUnusedServices, which the
| SDK provides for exactly this purpose. It is reimplemented here rather than
| registered as a composer script hook so it only ever runs inside the image
| build -- a hook would also fire on `composer install` on a developer machine.
|
| KEEP carries the vendor's own $unsafeForDeletion list (the credential
| providers the SDK resolves internally, which are reachable even when nothing
| calls them directly) plus the services this application actually uses.
|
| Adding an AWS-backed feature means adding its namespace here. A pruned
| service fails at class load the first time it is constructed, not at build
| time -- see .ai/rules/deployment.md.
|
*/

const KEEP = [
    // Vendor's $unsafeForDeletion.
    'Kms', 'S3', 'SSO', 'SSOOIDC', 'Sts', 'Signin',
];

$root = $argv[1] ?? __DIR__.'/../vendor/aws/aws-sdk-php/src';

if (! is_dir($root)) {
    fwrite(STDERR, "aws-sdk-php not found at {$root}\n");

    exit(1);
}

$removed = 0;

foreach (require "{$root}/data/manifest.json.php" as $service => $attributes) {
    if (in_array($attributes['namespace'], KEEP, true)) {
        continue;
    }

    foreach (["{$root}/{$attributes['namespace']}", "{$root}/data/{$service}"] as $directory) {
        if (! is_dir($directory)) {
            continue;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }

    $removed++;
}

fwrite(STDERR, "Removed {$removed} AWS services.\n");
