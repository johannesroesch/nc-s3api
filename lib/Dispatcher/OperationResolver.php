<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Dispatcher;

use OCA\NcS3Api\S3\S3Request;

/**
 * Maps an incoming S3Request to a canonical operation name.
 *
 * S3 overloads HTTP methods heavily: the same PUT /bucket can mean
 * CreateBucket, PutBucketVersioning, PutBucketCors, etc. — distinguished
 * only by query parameters.  This class contains the full decision table.
 *
 * Operation names are string constants defined below and used as keys in
 * S3Dispatcher's handler map.
 */
final class OperationResolver {
	// -------------------------------------------------------------------------
	// Operation name constants
	// -------------------------------------------------------------------------

	// Service-level
	public const LIST_BUCKETS = 'ListBuckets';

	// Bucket-level
	public const CREATE_BUCKET = 'CreateBucket';
	public const DELETE_BUCKET = 'DeleteBucket';
	public const HEAD_BUCKET = 'HeadBucket';
	public const LIST_OBJECTS = 'ListObjects';       // v1
	public const LIST_OBJECTS_V2 = 'ListObjectsV2';
	public const LIST_OBJECT_VERSIONS = 'ListObjectVersions';
	public const LIST_MULTIPART_UPLOADS = 'ListMultipartUploads';
	public const GET_BUCKET_VERSIONING = 'GetBucketVersioning';
	public const PUT_BUCKET_VERSIONING = 'PutBucketVersioning';
	public const GET_BUCKET_TAGGING = 'GetBucketTagging';
	public const PUT_BUCKET_TAGGING = 'PutBucketTagging';
	public const DELETE_BUCKET_TAGGING = 'DeleteBucketTagging';
	public const GET_BUCKET_ACL = 'GetBucketAcl';
	public const PUT_BUCKET_ACL = 'PutBucketAcl';
	public const GET_BUCKET_CORS = 'GetBucketCors';
	public const PUT_BUCKET_CORS = 'PutBucketCors';
	public const DELETE_BUCKET_CORS = 'DeleteBucketCors';
	public const GET_BUCKET_ENCRYPTION = 'GetBucketEncryption';
	public const PUT_BUCKET_ENCRYPTION = 'PutBucketEncryption';
	public const DELETE_BUCKET_ENCRYPTION = 'DeleteBucketEncryption';
	public const GET_BUCKET_LOCATION = 'GetBucketLocation';

	// Object-level
	public const GET_OBJECT = 'GetObject';
	public const PUT_OBJECT = 'PutObject';
	public const DELETE_OBJECT = 'DeleteObject';
	public const HEAD_OBJECT = 'HeadObject';
	public const COPY_OBJECT = 'CopyObject';
	public const DELETE_OBJECTS = 'DeleteObjects';     // POST /?delete
	public const GET_OBJECT_TAGGING = 'GetObjectTagging';
	public const PUT_OBJECT_TAGGING = 'PutObjectTagging';
	public const DELETE_OBJECT_TAGGING = 'DeleteObjectTagging';
	public const GET_OBJECT_ACL = 'GetObjectAcl';
	public const PUT_OBJECT_ACL = 'PutObjectAcl';
	public const GET_OBJECT_ATTRIBUTES = 'GetObjectAttributes';
	public const RESTORE_OBJECT = 'RestoreObject';

	// Multipart upload
	public const INITIATE_MULTIPART_UPLOAD = 'InitiateMultipartUpload';
	public const UPLOAD_PART = 'UploadPart';
	public const COMPLETE_MULTIPART_UPLOAD = 'CompleteMultipartUpload';
	public const ABORT_MULTIPART_UPLOAD = 'AbortMultipartUpload';
	public const LIST_PARTS = 'ListParts';

	// -------------------------------------------------------------------------

	/**
	 * Resolve the S3 operation for the given request.
	 *
	 * @throws \OCA\NcS3Api\Exception\S3Exception on method-not-allowed
	 */
	public function resolve(S3Request $request): string {
		$method = $request->method;
		$bucket = $request->bucket;
		$key = $request->key;
		$q = $request->queryParams;

		// ----------------------------------------------------------------
		// Service level — no bucket
		// ----------------------------------------------------------------
		if ($bucket === null) {
			if ($method === 'GET') {
				return self::LIST_BUCKETS;
			}
			$this->methodNotAllowed($method, '/');
		}

		// ----------------------------------------------------------------
		// Bucket level — no object key
		// ----------------------------------------------------------------
		if ($key === null) {
			return match ($method) {
				'HEAD' => self::HEAD_BUCKET,
				'PUT' => $this->resolvePutBucket($q),
				'GET' => $this->resolveGetBucket($q),
				'DELETE' => $this->resolveDeleteBucket($q),
				'POST' => isset($q['delete']) ? self::DELETE_OBJECTS : $this->methodNotAllowed($method, "/{$bucket}"),
				default => $this->methodNotAllowed($method, "/{$bucket}"),
			};
		}

		// ----------------------------------------------------------------
		// Object level — bucket + key present
		// ----------------------------------------------------------------
		return match ($method) {
			'HEAD' => self::HEAD_OBJECT,
			'GET' => $this->resolveGetObject($request),
			'PUT' => $this->resolvePutObject($request),
			'DELETE' => $this->resolveDeleteObject($q),
			'POST' => $this->resolvePostObject($q),
			default => $this->methodNotAllowed($method, "/{$bucket}/{$key}"),
		};
	}

	// ----------------------------------------------------------------
	// Bucket-level resolvers
	// ----------------------------------------------------------------

	private function resolvePutBucket(array $q): string {
		if (isset($q['versioning'])) {
			return self::PUT_BUCKET_VERSIONING;
		}
		if (isset($q['tagging'])) {
			return self::PUT_BUCKET_TAGGING;
		}
		if (isset($q['acl'])) {
			return self::PUT_BUCKET_ACL;
		}
		if (isset($q['cors'])) {
			return self::PUT_BUCKET_CORS;
		}
		if (isset($q['encryption'])) {
			return self::PUT_BUCKET_ENCRYPTION;
		}
		return self::CREATE_BUCKET;
	}

	private function resolveGetBucket(array $q): string {
		if (isset($q['versioning'])) {
			return self::GET_BUCKET_VERSIONING;
		}
		if (isset($q['tagging'])) {
			return self::GET_BUCKET_TAGGING;
		}
		if (isset($q['acl'])) {
			return self::GET_BUCKET_ACL;
		}
		if (isset($q['cors'])) {
			return self::GET_BUCKET_CORS;
		}
		if (isset($q['encryption'])) {
			return self::GET_BUCKET_ENCRYPTION;
		}
		if (isset($q['location'])) {
			return self::GET_BUCKET_LOCATION;
		}
		if (isset($q['versions'])) {
			return self::LIST_OBJECT_VERSIONS;
		}
		if (isset($q['uploads'])) {
			return self::LIST_MULTIPART_UPLOADS;
		}
		// list-type=2 → ListObjectsV2 (also default for modern clients)
		if (($q['list-type'] ?? '') === '2') {
			return self::LIST_OBJECTS_V2;
		}
		return self::LIST_OBJECTS;
	}

	private function resolveDeleteBucket(array $q): string {
		if (isset($q['tagging'])) {
			return self::DELETE_BUCKET_TAGGING;
		}
		if (isset($q['cors'])) {
			return self::DELETE_BUCKET_CORS;
		}
		if (isset($q['encryption'])) {
			return self::DELETE_BUCKET_ENCRYPTION;
		}
		return self::DELETE_BUCKET;
	}

	// ----------------------------------------------------------------
	// Object-level resolvers
	// ----------------------------------------------------------------

	private function resolveGetObject(S3Request $request): string {
		$q = $request->queryParams;
		if (isset($q['tagging'])) {
			return self::GET_OBJECT_TAGGING;
		}
		if (isset($q['acl'])) {
			return self::GET_OBJECT_ACL;
		}
		if (isset($q['attributes'])) {
			return self::GET_OBJECT_ATTRIBUTES;
		}
		if (isset($q['uploadId'])) {
			return self::LIST_PARTS;
		}
		return self::GET_OBJECT;
	}

	private function resolvePutObject(S3Request $request): string {
		$q = $request->queryParams;
		// UploadPart requires both uploadId and partNumber
		if (isset($q['uploadId'], $q['partNumber'])) {
			return self::UPLOAD_PART;
		}
		if (isset($q['tagging'])) {
			return self::PUT_OBJECT_TAGGING;
		}
		if (isset($q['acl'])) {
			return self::PUT_OBJECT_ACL;
		}
		// CopyObject is indicated by the x-amz-copy-source header
		if ($request->getHeader('x-amz-copy-source') !== '') {
			return self::COPY_OBJECT;
		}
		return self::PUT_OBJECT;
	}

	private function resolveDeleteObject(array $q): string {
		if (isset($q['tagging'])) {
			return self::DELETE_OBJECT_TAGGING;
		}
		if (isset($q['uploadId'])) {
			return self::ABORT_MULTIPART_UPLOAD;
		}
		return self::DELETE_OBJECT;
	}

	private function resolvePostObject(array $q): string {
		if (isset($q['uploads'])) {
			return self::INITIATE_MULTIPART_UPLOAD;
		}
		if (isset($q['uploadId'])) {
			return self::COMPLETE_MULTIPART_UPLOAD;
		}
		if (isset($q['restore'])) {
			return self::RESTORE_OBJECT;
		}
		// ListParts uses GET, not POST — but handle gracefully
		throw new \OCA\NcS3Api\Exception\S3Exception(
			\OCA\NcS3Api\S3\S3ErrorCodes::INVALID_REQUEST,
			'Unknown POST operation on object'
		);
	}

	/**
	 * @return never
	 * @throws \OCA\NcS3Api\Exception\S3Exception
	 */
	private function methodNotAllowed(string $method, string $resource): never {
		throw new \OCA\NcS3Api\Exception\S3Exception(
			\OCA\NcS3Api\S3\S3ErrorCodes::METHOD_NOT_ALLOWED,
			"Method $method is not allowed for resource $resource",
			$resource,
		);
	}
}
