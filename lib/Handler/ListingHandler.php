<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Handler;

use OCA\NcS3Api\Auth\AuthContext;
use OCA\NcS3Api\S3\S3Request;
use OCA\NcS3Api\S3\S3Response;
use OCA\NcS3Api\Storage\ObjectService;
use OCA\NcS3Api\Xml\XmlWriter;

/**
 * Handles ListObjects (v1) and ListObjectsV2.
 *
 * Both versions share the same core logic — pagination and prefix/delimiter
 * filtering — but use different parameter and response formats.
 */
class ListingHandler {
    private const DEFAULT_MAX_KEYS = 1000;

    public function __construct(
        private readonly ObjectService $objectService,
        private readonly XmlWriter     $xmlWriter,
    ) {}

    // -------------------------------------------------------------------------
    // ListObjectsV1
    // -------------------------------------------------------------------------

    public function listObjects(S3Request $request, AuthContext $auth): S3Response {
        $auth->requireAuthenticated();
        $bucket    = $request->bucket ?? '';
        $prefix    = $request->getQuery('prefix');
        $delimiter = $request->getQuery('delimiter');
        $marker    = $request->getQuery('marker');
        $maxKeys   = min((int)($request->queryParams['max-keys'] ?? self::DEFAULT_MAX_KEYS), self::DEFAULT_MAX_KEYS);

        $all = $this->objectService->listObjects($auth->userId, $bucket, $prefix);
        [$objects, $commonPrefixes, $isTruncated, $nextMarker] = $this->paginate(
            $all, $delimiter, $marker, $maxKeys, isV2: false,
        );

        $xml = $this->xmlWriter->listObjects(
            ctx: [
                'name'         => $bucket,
                'prefix'       => $prefix,
                'delimiter'    => $delimiter,
                'max_keys'     => $maxKeys,
                'is_truncated' => $isTruncated,
                'marker'       => $marker,
                'next_marker'  => $nextMarker ?? '',
            ],
            objects: $objects,
            commonPrefixes: $commonPrefixes,
        );
        return S3Response::ok($xml);
    }

    // -------------------------------------------------------------------------
    // ListObjectsV2
    // -------------------------------------------------------------------------

    public function listObjectsV2(S3Request $request, AuthContext $auth): S3Response {
        $auth->requireAuthenticated();
        $bucket            = $request->bucket ?? '';
        $prefix            = $request->getQuery('prefix');
        $delimiter         = $request->getQuery('delimiter');
        $continuationToken = $request->getQuery('continuation-token');
        $startAfter        = $request->getQuery('start-after');
        $maxKeys           = min((int)($request->queryParams['max-keys'] ?? self::DEFAULT_MAX_KEYS), self::DEFAULT_MAX_KEYS);

        // continuation-token takes precedence over start-after
        $resumeAfter = $continuationToken !== '' ? base64_decode($continuationToken) : $startAfter;

        $all = $this->objectService->listObjects($auth->userId, $bucket, $prefix);
        [$objects, $commonPrefixes, $isTruncated, $nextMarker] = $this->paginate(
            $all, $delimiter, $resumeAfter, $maxKeys, isV2: true,
        );

        $nextToken = $nextMarker !== null ? base64_encode($nextMarker) : null;

        $xml = $this->xmlWriter->listObjectsV2(
            ctx: [
                'name'                   => $bucket,
                'prefix'                 => $prefix,
                'delimiter'              => $delimiter,
                'max_keys'               => $maxKeys,
                'is_truncated'           => $isTruncated,
                'key_count'              => count($objects),
                'continuation_token'     => $continuationToken,
                'next_continuation_token' => $nextToken,
                'start_after'            => $startAfter,
            ],
            objects: $objects,
            commonPrefixes: $commonPrefixes,
        );
        return S3Response::ok($xml);
    }

    // -------------------------------------------------------------------------
    // Core pagination logic
    // -------------------------------------------------------------------------

    /**
     * Apply delimiter grouping, marker/continuation filtering, and max-keys pagination.
     *
     * @param list<array> $all         All objects (already filtered by prefix via ObjectService)
     * @param string      $delimiter   S3 delimiter (often '/')
     * @param string      $resumeAfter Key to resume after (marker for v1, continuation-token decoded for v2)
     * @param int         $maxKeys
     * @param bool        $isV2
     * @return array{list<array>, list<string>, bool, string|null}
     *         [objects, commonPrefixes, isTruncated, nextMarker]
     */
    private function paginate(
        array  $all,
        string $delimiter,
        string $resumeAfter,
        int    $maxKeys,
        bool   $isV2,
    ): array {
        // Sort lexicographically (S3 guarantees this)
        usort($all, fn($a, $b) => strcmp($a['key'], $b['key']));

        $objects       = [];
        $commonPrefixes = [];
        $seenPrefixes  = [];
        $count         = 0;
        $isTruncated   = false;
        $lastKey       = null;

        foreach ($all as $obj) {
            $key = $obj['key'];

            // Skip keys up to and including the resume marker
            if ($resumeAfter !== '' && $key <= $resumeAfter) {
                continue;
            }

            if ($count >= $maxKeys) {
                $isTruncated = true;
                break;
            }

            // Delimiter grouping
            if ($delimiter !== '') {
                $afterPrefix = $obj['key']; // already filtered by prefix
                $pos = strpos($afterPrefix, $delimiter);
                if ($pos !== false) {
                    $cp = substr($afterPrefix, 0, $pos + strlen($delimiter));
                    if (!isset($seenPrefixes[$cp])) {
                        $seenPrefixes[$cp] = true;
                        $commonPrefixes[]  = $cp;
                        $count++;
                        $lastKey = $cp;
                    }
                    continue;
                }
            }

            $objects[] = $obj;
            $lastKey   = $key;
            $count++;
        }

        sort($commonPrefixes);
        $nextMarker = $isTruncated ? $lastKey : null;

        return [$objects, $commonPrefixes, $isTruncated, $nextMarker];
    }
}
