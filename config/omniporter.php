<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Import Settings
    |--------------------------------------------------------------------------
    |
    | Configure how OmniPorter handles incoming import jobs.
    |
    */

    'import' => [

        /*
         | The storage disk used for temporary import file uploads.
         | Files are uploaded here before being processed by GenericImport.
         */
        'disk' => env('OMNIPORTER_IMPORT_DISK', 'local'),

        /*
         | Base directory (relative to the disk root) where uploaded files land.
         */
        'upload_path' => env('OMNIPORTER_IMPORT_UPLOAD_PATH', 'imports/uploads'),

        /*
         | Base directory for generated result/report files.
         */
        'results_path' => env('OMNIPORTER_IMPORT_RESULTS_PATH', 'imports/results'),

        /*
         | Number of Excel rows processed per database transaction chunk.
         | Larger values improve throughput; smaller values improve atomicity
         | and reduce lock contention on busy tables.
         */
        'chunk_size' => (int) env('OMNIPORTER_IMPORT_CHUNK_SIZE', 500),

        /*
         | The queue connection to dispatch import jobs onto.
         | Set to "sync" during testing or for small datasets.
         */
        'queue_connection' => env('OMNIPORTER_IMPORT_QUEUE', 'sync'),

        /*
         | The queue name for import jobs.
         */
        'queue_name' => env('OMNIPORTER_IMPORT_QUEUE_NAME', 'imports'),

        /*
         | Maximum number of rows per import file that OmniPorter will accept.
         | Files exceeding this limit are rejected before any processing begins.
         | Set to null to disable the limit.
         */
        'max_rows' => (int) env('OMNIPORTER_IMPORT_MAX_ROWS', 10000),

    ],

    /*
    |--------------------------------------------------------------------------
    | Export Settings
    |--------------------------------------------------------------------------
    */

    'export' => [

        /*
         | The storage disk used for generated export files.
         */
        'disk' => env('OMNIPORTER_EXPORT_DISK', 'local'),

        /*
         | Base directory for generated export files.
         */
        'export_path' => env('OMNIPORTER_EXPORT_PATH', 'exports'),

        /*
         | Default writer type passed to Maatwebsite\Excel.
         | Common values: Xlsx, Csv, Ods
         */
        'writer_type' => env('OMNIPORTER_EXPORT_WRITER', 'Xlsx'),

        /*
         | The queue connection to dispatch export jobs onto.
         */
        'queue_connection' => env('OMNIPORTER_EXPORT_QUEUE', 'sync'),

        /*
         | The queue name for export jobs.
         */
        'queue_name' => env('OMNIPORTER_EXPORT_QUEUE_NAME', 'exports'),

        /*
         | Number of records fetched per database chunk for exporting.
         */
        'chunk_size' => (int) env('OMNIPORTER_EXPORT_CHUNK_SIZE', 500),

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | OmniPorter uses a cache store (Redis by default) to track per-batch
    | import state across chunked queue jobs. The key prefix avoids collisions
    | with other application cache entries.
    |
    */

    'cache' => [

        /*
         | The cache store to use for import batch state.
         | Must support atomic get/set operations (Redis recommended).
         | Use "array" for testing environments.
         */
        'store' => env('OMNIPORTER_CACHE_STORE', 'redis'),

        /*
         | Prefix applied to all OmniPorter cache keys.
         */
        'prefix' => env('OMNIPORTER_CACHE_PREFIX', 'omniporter'),

        /*
         | TTL (seconds) for import batch state entries.
         | Import batches older than this will be garbage-collected automatically.
         */
        'ttl' => (int) env('OMNIPORTER_CACHE_TTL', 3600),

    ],

    /*
    |--------------------------------------------------------------------------
    | Model Discovery
    |--------------------------------------------------------------------------
    |
    | OmniPorter scans glob patterns to auto-register models that use the
    | HasImport / HasExport traits. Override these paths if your application
    | stores domain models in non-standard locations.
    |
    */

    'discovery' => [
        'model_paths' => [
            'app/Models/*.php',
            'Features/**/Domain/**/Models/*.php',
        ],
    ],

];
