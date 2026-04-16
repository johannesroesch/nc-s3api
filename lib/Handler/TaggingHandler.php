<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Handler;

use OCA\NcS3Api\Auth\AuthContext;
use OCA\NcS3Api\Db\BucketTag;
use OCA\NcS3Api\Db\BucketTagMapper;
use OCA\NcS3Api\Db\ObjectTag;
use OCA\NcS3Api\Db\ObjectTagMapper;
use OCA\NcS3Api\Exception\S3Exception;
use OCA\NcS3Api\S3\S3ErrorCodes;
use OCA\NcS3Api\S3\S3Request;
use OCA\NcS3Api\S3\S3Response;
use OCA\NcS3Api\Storage\BucketService;
use OCA\NcS3Api\Storage\ObjectService;
use OCA\NcS3Api\Xml\XmlReader;
use OCA\NcS3Api\Xml\XmlWriter;

class TaggingHandler {
	private const MAX_OBJECT_TAGS = 10;
	private const MAX_BUCKET_TAGS = 50;

	public function __construct(
		private readonly ObjectTagMapper $objectTagMapper,
		private readonly BucketTagMapper $bucketTagMapper,
		private readonly BucketService $bucketService,
		private readonly ObjectService $objectService,
		private readonly XmlWriter $xmlWriter,
		private readonly XmlReader $xmlReader,
	) {
	}

	// -------------------------------------------------------------------------
	// Object Tagging
	// -------------------------------------------------------------------------

	public function getObjectTagging(S3Request $request, AuthContext $auth): S3Response {
		$auth->requireAuthenticated();
		$bucket = $request->bucket ?? '';
		$key = $request->key ?? '';

		$this->objectService->getObjectMeta($auth->userId, $bucket, $key); // assert exists

		$tags = $this->objectTagMapper->findByObject($auth->userId, $bucket, $key);
		$list = array_map(fn (ObjectTag $t) => ['key' => $t->getTagKey(), 'value' => $t->getTagValue()], $tags);

		return S3Response::ok($this->xmlWriter->tagging($list));
	}

	public function putObjectTagging(S3Request $request, AuthContext $auth): S3Response {
		$auth->requireAuthenticated();
		$bucket = $request->bucket ?? '';
		$key = $request->key ?? '';

		$this->objectService->getObjectMeta($auth->userId, $bucket, $key);

		$body = is_resource($request->bodyStream) ? stream_get_contents($request->bodyStream) : (string)$request->bodyStream;
		$tags = $this->xmlReader->tagging($body);

		if (count($tags) > self::MAX_OBJECT_TAGS) {
			throw new S3Exception(S3ErrorCodes::TOO_MANY_TAGS, 'Object cannot have more than ' . self::MAX_OBJECT_TAGS . ' tags');
		}

		$this->objectTagMapper->deleteByObject($auth->userId, $bucket, $key);
		foreach ($tags as $t) {
			$entity = new ObjectTag();
			$entity->setUserId($auth->userId);
			$entity->setBucket($bucket);
			$entity->setObjectKey($key);
			$entity->setTagKey($t['key']);
			$entity->setTagValue($t['value']);
			$this->objectTagMapper->insert($entity);
		}

		return S3Response::noContent();
	}

	public function deleteObjectTagging(S3Request $request, AuthContext $auth): S3Response {
		$auth->requireAuthenticated();
		$this->objectTagMapper->deleteByObject($auth->userId, $request->bucket ?? '', $request->key ?? '');
		return S3Response::noContent();
	}

	// -------------------------------------------------------------------------
	// Bucket Tagging
	// -------------------------------------------------------------------------

	public function getBucketTagging(S3Request $request, AuthContext $auth): S3Response {
		$auth->requireAuthenticated();
		$bucket = $request->bucket ?? '';
		$this->bucketService->headBucket($auth->userId, $bucket);

		$tags = $this->bucketTagMapper->findByBucket($auth->userId, $bucket);
		$list = array_map(fn (BucketTag $t) => ['key' => $t->getTagKey(), 'value' => $t->getTagValue()], $tags);

		return S3Response::ok($this->xmlWriter->tagging($list));
	}

	public function putBucketTagging(S3Request $request, AuthContext $auth): S3Response {
		$auth->requireAuthenticated();
		$bucket = $request->bucket ?? '';
		$this->bucketService->headBucket($auth->userId, $bucket);

		$body = is_resource($request->bodyStream) ? stream_get_contents($request->bodyStream) : (string)$request->bodyStream;
		$tags = $this->xmlReader->tagging($body);

		if (count($tags) > self::MAX_BUCKET_TAGS) {
			throw new S3Exception(S3ErrorCodes::TOO_MANY_TAGS, 'Bucket cannot have more than ' . self::MAX_BUCKET_TAGS . ' tags');
		}

		$this->bucketTagMapper->deleteByBucket($auth->userId, $bucket);
		foreach ($tags as $t) {
			$entity = new BucketTag();
			$entity->setUserId($auth->userId);
			$entity->setBucket($bucket);
			$entity->setTagKey($t['key']);
			$entity->setTagValue($t['value']);
			$this->bucketTagMapper->insert($entity);
		}

		return S3Response::noContent();
	}

	public function deleteBucketTagging(S3Request $request, AuthContext $auth): S3Response {
		$auth->requireAuthenticated();
		$this->bucketTagMapper->deleteByBucket($auth->userId, $request->bucket ?? '');
		return S3Response::noContent();
	}
}
