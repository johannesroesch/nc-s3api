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
use OCA\NcS3Api\Xml\XmlWriter;

/**
 * ACL handler.
 *
 * S3 ACLs map to Nextcloud's sharing model:
 *  - FULL_CONTROL → owner (always the authenticated user)
 *  - READ         → public share links / group shares
 *
 * Writing ACLs is accepted (for compatibility) but the implementation
 * only tracks the canned ACL name in bucket_metadata.
 * The actual Nextcloud sharing is not modified — this would require
 * knowledge of NC user/group names which S3 clients don't provide.
 */
class AclHandler {
	private const META_KEY = 'acl';

	public function __construct(
		private readonly BucketMetadataMapper $metaMapper,
		private readonly BucketService $bucketService,
		private readonly ObjectService $objectService,
		private readonly XmlWriter $xmlWriter,
	) {
	}

	// -------------------------------------------------------------------------
	// Bucket ACL
	// -------------------------------------------------------------------------

	public function getBucketAcl(S3Request $request, AuthContext $auth): S3Response {
		$auth->requireAuthenticated();
		$bucket = $request->bucket ?? '';
		$this->bucketService->headBucket($auth->userId, $bucket);

		$owner = ['id' => $auth->userId, 'display_name' => $auth->user?->getDisplayName() ?? $auth->userId];
		$grants = [
			[
				'grantee' => array_merge($owner, ['type' => 'CanonicalUser']),
				'permission' => 'FULL_CONTROL',
			],
		];

		return S3Response::ok($this->xmlWriter->acl($owner, $grants));
	}

	public function putBucketAcl(S3Request $request, AuthContext $auth): S3Response {
		$auth->requireAuthenticated();
		$bucket = $request->bucket ?? '';
		$this->bucketService->headBucket($auth->userId, $bucket);

		// Accept canned ACL from header
		$cannedAcl = $request->getHeader('x-amz-acl') ?: 'private';
		$this->metaMapper->upsert($auth->userId, $bucket, self::META_KEY, ['canned' => $cannedAcl]);

		// Note: full ACL body parsing / NC share integration not implemented
		// See docs/unsupported-features.md

		return S3Response::noContent();
	}

	// -------------------------------------------------------------------------
	// Object ACL
	// -------------------------------------------------------------------------

	public function getObjectAcl(S3Request $request, AuthContext $auth): S3Response {
		$auth->requireAuthenticated();
		$bucket = $request->bucket ?? '';
		$key = $request->key ?? '';
		$this->objectService->getObjectMeta($auth->userId, $bucket, $key);

		$owner = ['id' => $auth->userId, 'display_name' => $auth->user?->getDisplayName() ?? $auth->userId];
		$grants = [
			[
				'grantee' => array_merge($owner, ['type' => 'CanonicalUser']),
				'permission' => 'FULL_CONTROL',
			],
		];

		return S3Response::ok($this->xmlWriter->acl($owner, $grants));
	}

	public function putObjectAcl(S3Request $request, AuthContext $auth): S3Response {
		$auth->requireAuthenticated();
		// Accepted but no-op (canned ACL stored as object metadata if needed)
		return S3Response::noContent();
	}
}
