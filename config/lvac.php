<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Apple Container Binary
    |--------------------------------------------------------------------------
    |
    | The package defaults to the "container" CLI from github.com/apple/container.
    | You may override it with the CONTAINER_CLI environment variable.
    |
    */

    'container_cli' => env('CONTAINER_CLI', 'container'),

    /*
    |--------------------------------------------------------------------------
    | Node Image
    |--------------------------------------------------------------------------
    |
    | Keep this pinned for reproducibility. Update intentionally after reviewing
    | the new image digest.
    |
    */

    'node_image' => env(
        'LVAC_NODE_IMAGE',
        'docker.io/library/node:24-alpine@sha256:d1b3b4da11eefd5941e7f0b9cf17783fc99d9c6fc34884a665f40a06dbdfc94f'
    ),

    /*
    |--------------------------------------------------------------------------
    | Default Resource Limits
    |--------------------------------------------------------------------------
    */

    'memory' => env('LVAC_MEMORY', '2g'),
    'cpus' => env('LVAC_CPUS', '2'),
];
