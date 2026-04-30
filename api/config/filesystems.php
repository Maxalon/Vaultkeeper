<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            // Honour ASSETS_PUBLIC_URL when set so prod/staging build the
            // same absolute URLs whether the assets disk is local or s3.
            'url' => env('ASSETS_PUBLIC_URL', env('APP_URL').'/storage'),
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // Static set-symbol / mana-symbol / card-back assets (sourced from
        // mtg-vectors + Scryfall). In prod served from MinIO via Caddy at
        // assets.vault[-staging].kontrollzentrale.de; in dev served from
        // the local filesystem under /storage.
        //
        // Driver is env-driven so dev runs on the local filesystem while
        // prod / staging point at MinIO without any code change. Same
        // code path handles both.
        'assets' => env('ASSETS_DISK_DRIVER', 'local') === 's3'
            ? [
                'driver'                  => 's3',
                'key'                     => env('ASSETS_AWS_ACCESS_KEY_ID'),
                'secret'                  => env('ASSETS_AWS_SECRET_ACCESS_KEY'),
                'region'                  => env('ASSETS_AWS_DEFAULT_REGION', 'us-east-1'),
                'bucket'                  => env('ASSETS_BUCKET'),
                'endpoint'                => env('ASSETS_AWS_ENDPOINT'),
                'url'                     => env('ASSETS_PUBLIC_URL'),
                'use_path_style_endpoint' => true,
                'visibility'              => 'public',
                'throw'                   => false,
                'report'                  => false,
            ]
            : [
                'driver'     => 'local',
                'root'       => storage_path('app/public'),
                'url'        => env('ASSETS_PUBLIC_URL', env('APP_URL').'/storage'),
                'visibility' => 'public',
                'throw'      => false,
                'report'     => false,
            ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
