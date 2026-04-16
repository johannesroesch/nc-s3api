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
use OCA\NcS3Api\Storage\BucketService;
use OCA\NcS3Api\Xml\XmlWriter;

class BucketHandler {
    public function __construct(
        private readonly BucketService $bucketService,
        private readonly XmlWriter     $xmlWriter,
    ) {}

    public function listBuckets(S3Request $request, AuthContext $auth): S3Response {
        $auth->requireAuthenticated();
        $buckets = $this->bucketService->listBuckets($auth->userId);

        $xml = $this->xmlWriter->listBuckets(
            owner:   ['id' => $auth->userId, 'display_name' => $auth->user?->getDisplayName() ?? $auth->userId],
            buckets: $buckets,
        );
        return S3Response::ok($xml);
    }

    public function createBucket(S3Request $request, AuthContext $auth): S3Response {
        $auth->requireAuthenticated();
        $bucket = $request->bucket ?? '';

        $this->bucketService->createBucket($auth->userId, $bucket);

        return new S3Response(
            statusCode: 200,
            headers: ['Location' => "/{$bucket}"],
        );
    }

    public function deleteBucket(S3Request $request, AuthContext $auth): S3Response {
        $auth->requireAuthenticated();
        $this->bucketService->deleteBucket($auth->userId, $request->bucket ?? '');
        return S3Response::noContent();
    }

    public function headBucket(S3Request $request, AuthContext $auth): S3Response {
        $auth->requireAuthenticated();
        $this->bucketService->headBucket($auth->userId, $request->bucket ?? '');
        return new S3Response(statusCode: 200);
    }

    public function getBucketLocation(S3Request $request, AuthContext $auth): S3Response {
        $auth->requireAuthenticated();
        $this->bucketService->headBucket($auth->userId, $request->bucket ?? '');
        // Nextcloud has no concept of regions — return empty string (same as us-east-1)
        $xml = $this->xmlWriter->getBucketLocation('');
        return S3Response::ok($xml);
    }
}
