<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Media Disk
    |--------------------------------------------------------------------------
    |
    | Filesystem disk used to store uploaded media. Defaults to the local
    | "public" disk so the library works out of the box; set MEDIA_DISK=s3
    | (and fill in the AWS_* credentials) to serve from a public S3 bucket.
    |
    */

    'disk' => env('MEDIA_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | CDN Base URL
    |--------------------------------------------------------------------------
    |
    | Public base URL served in front of the media disk (e.g. a CloudFront
    | distribution over the S3 bucket). When set, media URLs are built from it
    | instead of the raw disk URL. Leave blank to serve straight from the disk.
    |
    */

    'cdn_url' => env('MEDIA_CDN_URL'),

    /*
    |--------------------------------------------------------------------------
    | Upload Limits
    |--------------------------------------------------------------------------
    */

    // Maximum upload size, in kilobytes.
    'max_size' => (int) env('MEDIA_MAX_SIZE_KB', 10240), // 10 MB

    // Allowed image extensions.
    'accept' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'],

];
