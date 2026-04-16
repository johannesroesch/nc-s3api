<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * S3 API Gateway — routes
 *
 * All S3 requests are caught by a single controller method (dispatch).
 * The OperationResolver inside the controller determines which S3 operation
 * to execute based on HTTP method + URL path + query parameters.
 *
 * Endpoint base: /index.php/apps/nc_s3api/s3/
 *   - ListBuckets:  GET  /s3/
 *   - Bucket ops:   *    /s3/{bucket}
 *   - Object ops:   *    /s3/{bucket}/{key...}
 */

return [
    'routes' => [
        // -----------------------------------------------------------------------
        // Settings API (admin)
        // -----------------------------------------------------------------------
        [
            'name' => 'settings#adminListCredentials',
            'url'  => '/admin/credentials',
            'verb' => 'GET',
        ],
        [
            'name' => 'settings#adminCreateCredential',
            'url'  => '/admin/credentials',
            'verb' => 'POST',
        ],
        [
            'name'         => 'settings#adminDeleteCredential',
            'url'          => '/admin/credentials/{id}',
            'verb'         => 'DELETE',
            'requirements' => ['id' => '\d+'],
        ],
        // Settings API (user — authenticated, no admin required)
        [
            'name' => 'settings#userListCredentials',
            'url'  => '/user/credentials',
            'verb' => 'GET',
        ],
        [
            'name' => 'settings#userCreateCredential',
            'url'  => '/user/credentials',
            'verb' => 'POST',
        ],
        [
            'name'         => 'settings#userDeleteCredential',
            'url'          => '/user/credentials/{id}',
            'verb'         => 'DELETE',
            'requirements' => ['id' => '\d+'],
        ],

        // -----------------------------------------------------------------------
        // S3 API
        // -----------------------------------------------------------------------
        // ListBuckets — no path segment after /s3/
        [
            'name'  => 's3#dispatch',
            'url'   => '/s3/',
            'verb'  => 'GET',
        ],
        // Bucket-level operations (no object key) — e.g. /s3/mybucket
        [
            'name'         => 's3#dispatch',
            'url'          => '/s3/{bucket}',
            'verb'         => 'GET',
            'requirements' => ['bucket' => '[^/]+'],
        ],
        [
            'name'         => 's3#dispatch',
            'url'          => '/s3/{bucket}',
            'verb'         => 'PUT',
            'requirements' => ['bucket' => '[^/]+'],
        ],
        [
            'name'         => 's3#dispatch',
            'url'          => '/s3/{bucket}',
            'verb'         => 'DELETE',
            'requirements' => ['bucket' => '[^/]+'],
        ],
        [
            'name'         => 's3#dispatch',
            'url'          => '/s3/{bucket}',
            'verb'         => 'HEAD',
            'requirements' => ['bucket' => '[^/]+'],
        ],
        [
            'name'         => 's3#dispatch',
            'url'          => '/s3/{bucket}',
            'verb'         => 'POST',
            'requirements' => ['bucket' => '[^/]+'],
        ],
        // Object-level operations — key may contain slashes, e.g. /s3/mybucket/dir/file.txt
        [
            'name'         => 's3#dispatch',
            'url'          => '/s3/{bucket}/{key}',
            'verb'         => 'GET',
            'requirements' => ['bucket' => '[^/]+', 'key' => '.+'],
        ],
        [
            'name'         => 's3#dispatch',
            'url'          => '/s3/{bucket}/{key}',
            'verb'         => 'PUT',
            'requirements' => ['bucket' => '[^/]+', 'key' => '.+'],
        ],
        [
            'name'         => 's3#dispatch',
            'url'          => '/s3/{bucket}/{key}',
            'verb'         => 'DELETE',
            'requirements' => ['bucket' => '[^/]+', 'key' => '.+'],
        ],
        [
            'name'         => 's3#dispatch',
            'url'          => '/s3/{bucket}/{key}',
            'verb'         => 'HEAD',
            'requirements' => ['bucket' => '[^/]+', 'key' => '.+'],
        ],
        [
            'name'         => 's3#dispatch',
            'url'          => '/s3/{bucket}/{key}',
            'verb'         => 'POST',
            'requirements' => ['bucket' => '[^/]+', 'key' => '.+'],
        ],
    ],
];
