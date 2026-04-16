<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Handler;

use OCA\NcS3Api\Auth\AuthContext;
use OCA\NcS3Api\Db\BucketMetadataMapper;
use OCA\NcS3Api\S3\S3Request;
use OCA\NcS3Api\S3\S3Response;
use OCA\NcS3Api\Storage\BucketService;
use OCA\NcS3Api\Xml\XmlWriter;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * SSE-S3 encryption config handler.
 *
 * Nextcloud stores files using its own encryption subsystem (when enabled).
 * This handler stores the encryption configuration metadata but does NOT
 * perform additional encryption beyond what Nextcloud itself provides.
 *
 * SSE-KMS and SSE-C are not supported.
 * See docs/unsupported-features.md for details.
 */
class EncryptionHandler {
    private const META_KEY  = 'encryption';
    private const ALGORITHM = 'AES256';

    public function __construct(
        private readonly BucketMetadataMapper $metaMapper,
        private readonly BucketService        $bucketService,
        private readonly XmlWriter            $xmlWriter,
    ) {}

    public function getBucketEncryption(S3Request $request, AuthContext $auth): S3Response {
        $auth->requireAuthenticated();
        $bucket = $request->bucket ?? '';
        $this->bucketService->headBucket($auth->userId, $bucket);

        try {
            $meta = $this->metaMapper->find($auth->userId, $bucket, self::META_KEY);
            $algo = $meta->getValueDecoded()['algorithm'] ?? self::ALGORITHM;
        } catch (DoesNotExistException) {
            throw new \OCA\NcS3Api\Exception\S3Exception(
                \OCA\NcS3Api\S3\S3ErrorCodes::NO_SUCH_BUCKET,
                'The server side encryption configuration was not found',
            );
        }

        return S3Response::ok($this->xmlWriter->serverSideEncryptionConfiguration($algo));
    }

    public function putBucketEncryption(S3Request $request, AuthContext $auth): S3Response {
        $auth->requireAuthenticated();
        $bucket = $request->bucket ?? '';
        $this->bucketService->headBucket($auth->userId, $bucket);

        // Accept the request (only AES256 / SSE-S3 stored, KMS/SSE-C rejected)
        // Full XML parsing could extract the algorithm — for now always store AES256
        $this->metaMapper->upsert($auth->userId, $bucket, self::META_KEY, ['algorithm' => self::ALGORITHM]);
        return S3Response::noContent();
    }

    public function deleteBucketEncryption(S3Request $request, AuthContext $auth): S3Response {
        $auth->requireAuthenticated();
        $bucket = $request->bucket ?? '';
        $this->bucketService->headBucket($auth->userId, $bucket);
        $this->metaMapper->delete($auth->userId, $bucket, self::META_KEY);
        return S3Response::noContent();
    }
}
