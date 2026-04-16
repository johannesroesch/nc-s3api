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
use OCA\NcS3Api\Xml\XmlReader;
use OCA\NcS3Api\Xml\XmlWriter;
use OCP\AppFramework\Db\DoesNotExistException;

class CorsHandler {
	private const META_KEY = 'cors';

	public function __construct(
		private readonly BucketMetadataMapper $metaMapper,
		private readonly BucketService $bucketService,
		private readonly XmlWriter $xmlWriter,
		private readonly XmlReader $xmlReader,
	) {
	}

	public function getBucketCors(S3Request $request, AuthContext $auth): S3Response {
		$auth->requireAuthenticated();
		$bucket = $request->bucket ?? '';
		$this->bucketService->headBucket($auth->userId, $bucket);

		try {
			$meta = $this->metaMapper->find($auth->userId, $bucket, self::META_KEY);
			$rules = $meta->getValueDecoded() ?? [];
		} catch (DoesNotExistException) {
			throw new \OCA\NcS3Api\Exception\S3Exception(
				\OCA\NcS3Api\S3\S3ErrorCodes::NO_SUCH_BUCKET,
				'The CORS configuration does not exist',
			);
		}

		return S3Response::ok($this->xmlWriter->corsConfiguration($rules));
	}

	public function putBucketCors(S3Request $request, AuthContext $auth): S3Response {
		$auth->requireAuthenticated();
		$bucket = $request->bucket ?? '';
		$this->bucketService->headBucket($auth->userId, $bucket);

		$body = is_resource($request->bodyStream) ? stream_get_contents($request->bodyStream) : (string)$request->bodyStream;
		$rules = $this->xmlReader->corsConfiguration($body);

		$this->metaMapper->upsert($auth->userId, $bucket, self::META_KEY, $rules);
		return S3Response::noContent();
	}

	public function deleteBucketCors(S3Request $request, AuthContext $auth): S3Response {
		$auth->requireAuthenticated();
		$bucket = $request->bucket ?? '';
		$this->bucketService->headBucket($auth->userId, $bucket);
		$this->metaMapper->deleteByKey($auth->userId, $bucket, self::META_KEY);
		return S3Response::noContent();
	}
}
