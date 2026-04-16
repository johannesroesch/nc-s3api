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
use OCA\NcS3Api\Storage\ObjectService;
use OCA\NcS3Api\Xml\XmlReader;
use OCA\NcS3Api\Xml\XmlWriter;
use OCP\AppFramework\Db\DoesNotExistException;

class VersioningHandler {
    private const META_KEY = 'versioning';

    public function __construct(
        private readonly BucketMetadataMapper $metaMapper,
        private readonly BucketService        $bucketService,
        private readonly ObjectService        $objectService,
        private readonly XmlWriter            $xmlWriter,
        private readonly XmlReader            $xmlReader,
    ) {}

    public function getBucketVersioning(S3Request $request, AuthContext $auth): S3Response {
        $auth->requireAuthenticated();
        $bucket = $request->bucket ?? '';
        $this->bucketService->headBucket($auth->userId, $bucket);

        $status = '';
        try {
            $meta   = $this->metaMapper->find($auth->userId, $bucket, self::META_KEY);
            $status = $meta->getValueDecoded()['status'] ?? '';
        } catch (DoesNotExistException) {
            // Never configured — return empty configuration
        }

        return S3Response::ok($this->xmlWriter->getBucketVersioning($status));
    }

    public function putBucketVersioning(S3Request $request, AuthContext $auth): S3Response {
        $auth->requireAuthenticated();
        $bucket = $request->bucket ?? '';
        $this->bucketService->headBucket($auth->userId, $bucket);

        $body   = is_resource($request->bodyStream) ? stream_get_contents($request->bodyStream) : (string)$request->bodyStream;
        $status = $this->xmlReader->versioningConfiguration($body);

        if (!in_array($status, ['Enabled', 'Suspended', ''])) {
            throw new \OCA\NcS3Api\Exception\S3Exception(
                \OCA\NcS3Api\S3\S3ErrorCodes::INVALID_ARGUMENT,
                'VersioningConfiguration Status must be Enabled or Suspended',
            );
        }

        $this->metaMapper->upsert($auth->userId, $bucket, self::META_KEY, ['status' => $status]);
        return S3Response::noContent();
    }

    public function listObjectVersions(S3Request $request, AuthContext $auth): S3Response {
        $auth->requireAuthenticated();
        $bucket = $request->bucket ?? '';
        $prefix = $request->getQuery('prefix');

        // Versioning uses Nextcloud's built-in file versioning.
        // For now: return current versions only (as if versioning is disabled).
        // Full implementation would iterate IVersionManager for each file.
        $objects = $this->objectService->listObjects($auth->userId, $bucket, $prefix);

        $versions = array_map(fn($obj) => [
            'key'           => $obj['key'],
            'version_id'    => 'null',  // "null" is the S3 null version ID
            'is_latest'     => true,
            'last_modified' => $obj['last_modified'],
            'etag'          => $obj['etag'],
            'size'          => $obj['size'],
        ], $objects);

        return S3Response::ok($this->xmlWriter->listObjectVersions($bucket, $versions, false));
    }
}
