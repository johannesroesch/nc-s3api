<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Tests\Unit\Dispatcher;

use OCA\NcS3Api\Dispatcher\OperationResolver;
use OCA\NcS3Api\Exception\S3Exception;
use OCA\NcS3Api\S3\S3Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the complete S3 operation dispatch table.
 */
class OperationResolverTest extends TestCase {
	private OperationResolver $resolver;

	protected function setUp(): void {
		$this->resolver = new OperationResolver();
	}

	// -------------------------------------------------------------------------
	// Full dispatch table (DataProvider)
	// -------------------------------------------------------------------------

	#[DataProvider('operationProvider')]
	public function testResolve(
		string $method,
		?string $bucket,
		?string $key,
		array $queryParams,
		string $expected,
	): void {
		$request = $this->makeRequest($method, $bucket, $key, $queryParams);
		$this->assertSame($expected, $this->resolver->resolve($request));
	}

	public static function operationProvider(): array {
		return [
			// ── Service level ─────────────────────────────────────────────
			'ListBuckets' => ['GET',    null,       null, [],                                           OperationResolver::LIST_BUCKETS],

			// ── Bucket level ──────────────────────────────────────────────
			'CreateBucket' => ['PUT',    'mybucket', null, [],                                           OperationResolver::CREATE_BUCKET],
			'DeleteBucket' => ['DELETE', 'mybucket', null, [],                                           OperationResolver::DELETE_BUCKET],
			'HeadBucket' => ['HEAD',   'mybucket', null, [],                                           OperationResolver::HEAD_BUCKET],
			'ListObjects (v1)' => ['GET',    'mybucket', null, [],                                           OperationResolver::LIST_OBJECTS],
			'ListObjectsV2' => ['GET',    'mybucket', null, ['list-type' => '2'],                         OperationResolver::LIST_OBJECTS_V2],
			'ListObjectVersions' => ['GET',    'mybucket', null, ['versions' => ''],                           OperationResolver::LIST_OBJECT_VERSIONS],
			'ListMultipartUploads' => ['GET',    'mybucket', null, ['uploads' => ''],                            OperationResolver::LIST_MULTIPART_UPLOADS],
			'GetBucketVersioning' => ['GET',    'mybucket', null, ['versioning' => ''],                         OperationResolver::GET_BUCKET_VERSIONING],
			'PutBucketVersioning' => ['PUT',    'mybucket', null, ['versioning' => ''],                         OperationResolver::PUT_BUCKET_VERSIONING],
			'GetBucketTagging' => ['GET',    'mybucket', null, ['tagging' => ''],                            OperationResolver::GET_BUCKET_TAGGING],
			'PutBucketTagging' => ['PUT',    'mybucket', null, ['tagging' => ''],                            OperationResolver::PUT_BUCKET_TAGGING],
			'DeleteBucketTagging' => ['DELETE', 'mybucket', null, ['tagging' => ''],                            OperationResolver::DELETE_BUCKET_TAGGING],
			'GetBucketAcl' => ['GET',    'mybucket', null, ['acl' => ''],                                OperationResolver::GET_BUCKET_ACL],
			'PutBucketAcl' => ['PUT',    'mybucket', null, ['acl' => ''],                                OperationResolver::PUT_BUCKET_ACL],
			'GetBucketCors' => ['GET',    'mybucket', null, ['cors' => ''],                               OperationResolver::GET_BUCKET_CORS],
			'PutBucketCors' => ['PUT',    'mybucket', null, ['cors' => ''],                               OperationResolver::PUT_BUCKET_CORS],
			'DeleteBucketCors' => ['DELETE', 'mybucket', null, ['cors' => ''],                               OperationResolver::DELETE_BUCKET_CORS],
			'GetBucketEncryption' => ['GET',    'mybucket', null, ['encryption' => ''],                         OperationResolver::GET_BUCKET_ENCRYPTION],
			'PutBucketEncryption' => ['PUT',    'mybucket', null, ['encryption' => ''],                         OperationResolver::PUT_BUCKET_ENCRYPTION],
			'DeleteBucketEncryption' => ['DELETE', 'mybucket', null, ['encryption' => ''],                         OperationResolver::DELETE_BUCKET_ENCRYPTION],
			'GetBucketLocation' => ['GET',    'mybucket', null, ['location' => ''],                           OperationResolver::GET_BUCKET_LOCATION],
			'DeleteObjects' => ['POST',   'mybucket', null, ['delete' => ''],                             OperationResolver::DELETE_OBJECTS],

			// ── Object level ─────────────────────────────────────────────
			'GetObject' => ['GET',    'b', 'k',          [],                                          OperationResolver::GET_OBJECT],
			'HeadObject' => ['HEAD',   'b', 'k',          [],                                          OperationResolver::HEAD_OBJECT],
			'PutObject' => ['PUT',    'b', 'k',          [],                                          OperationResolver::PUT_OBJECT],
			'DeleteObject' => ['DELETE', 'b', 'k',          [],                                          OperationResolver::DELETE_OBJECT],
			'GetObjectTagging' => ['GET',    'b', 'k',          ['tagging' => ''],                           OperationResolver::GET_OBJECT_TAGGING],
			'PutObjectTagging' => ['PUT',    'b', 'k',          ['tagging' => ''],                           OperationResolver::PUT_OBJECT_TAGGING],
			'DeleteObjectTagging' => ['DELETE', 'b', 'k',          ['tagging' => ''],                           OperationResolver::DELETE_OBJECT_TAGGING],
			'GetObjectAcl' => ['GET',    'b', 'k',          ['acl' => ''],                               OperationResolver::GET_OBJECT_ACL],
			'PutObjectAcl' => ['PUT',    'b', 'k',          ['acl' => ''],                               OperationResolver::PUT_OBJECT_ACL],
			'GetObjectAttributes' => ['GET',    'b', 'k',          ['attributes' => ''],                        OperationResolver::GET_OBJECT_ATTRIBUTES],
			'InitiateMultipartUpload' => ['POST',   'b', 'k',          ['uploads' => ''],                           OperationResolver::INITIATE_MULTIPART_UPLOAD],
			'CompleteMultipartUpload' => ['POST',   'b', 'k',          ['uploadId' => 'x'],                         OperationResolver::COMPLETE_MULTIPART_UPLOAD],
			'AbortMultipartUpload' => ['DELETE', 'b', 'k',          ['uploadId' => 'x'],                         OperationResolver::ABORT_MULTIPART_UPLOAD],
			'UploadPart' => ['PUT',    'b', 'k',          ['uploadId' => 'x', 'partNumber' => '1'],    OperationResolver::UPLOAD_PART],
			'ListParts (GET+uploadId)' => ['GET',    'b', 'k',          ['uploadId' => 'x'],                         OperationResolver::LIST_PARTS],
			'RestoreObject' => ['POST',   'b', 'k',          ['restore' => ''],                           OperationResolver::RESTORE_OBJECT],
		];
	}

	// -------------------------------------------------------------------------
	// CopyObject — detected by header, not query param
	// -------------------------------------------------------------------------

	public function testCopyObjectResolution(): void {
		$request = new S3Request(
			method:      'PUT',
			bucket:      'dst-bucket',
			key:         'dst-key',
			queryParams: [],
			headers:     ['x-amz-copy-source' => '/src-bucket/src-key'],
			bodyStream:  '',
			rawPath:     '/s3/dst-bucket/dst-key',
			host:        'localhost',
		);
		$this->assertSame(OperationResolver::COPY_OBJECT, $this->resolver->resolve($request));
	}

	public function testCopyObjectNotTriggeredWithoutHeader(): void {
		// Without the copy-source header a PUT with key is a PutObject
		$request = $this->makeRequest('PUT', 'b', 'k', []);
		$this->assertSame(OperationResolver::PUT_OBJECT, $this->resolver->resolve($request));
	}

	// -------------------------------------------------------------------------
	// UploadPart vs PutObject disambiguation
	// -------------------------------------------------------------------------

	public function testUploadPartRequiresBothParams(): void {
		// uploadId alone → NOT UploadPart (no partNumber)
		$request = $this->makeRequest('PUT', 'b', 'k', ['uploadId' => 'x']);
		// Should fall through to PutObject (no copy header, no tagging, no acl)
		$this->assertSame(OperationResolver::PUT_OBJECT, $this->resolver->resolve($request));
	}

	public function testUploadPartRequiresUploadId(): void {
		// partNumber alone → NOT UploadPart
		$request = $this->makeRequest('PUT', 'b', 'k', ['partNumber' => '1']);
		$this->assertSame(OperationResolver::PUT_OBJECT, $this->resolver->resolve($request));
	}

	// -------------------------------------------------------------------------
	// ListObjectsV2 vs ListObjects disambiguation
	// -------------------------------------------------------------------------

	public function testListObjectsV2RequiresListTypeTwo(): void {
		// list-type=1 should fall back to ListObjects v1
		$request = $this->makeRequest('GET', 'b', null, ['list-type' => '1']);
		$this->assertSame(OperationResolver::LIST_OBJECTS, $this->resolver->resolve($request));
	}

	// -------------------------------------------------------------------------
	// Priority: query-param based operations take precedence over defaults
	// -------------------------------------------------------------------------

	public function testTaggingTakesPriorityOverListObjectsOnGet(): void {
		$request = $this->makeRequest('GET', 'b', null, ['tagging' => '']);
		$this->assertSame(OperationResolver::GET_BUCKET_TAGGING, $this->resolver->resolve($request));
	}

	public function testVersioningTakesPriorityOnPut(): void {
		$request = $this->makeRequest('PUT', 'b', null, ['versioning' => '']);
		$this->assertSame(OperationResolver::PUT_BUCKET_VERSIONING, $this->resolver->resolve($request));
	}

	// -------------------------------------------------------------------------
	// Object keys with slashes
	// -------------------------------------------------------------------------

	public function testObjectKeyWithSlashes(): void {
		$request = $this->makeRequest('GET', 'mybucket', 'dir/sub/file.txt', []);
		$this->assertSame(OperationResolver::GET_OBJECT, $this->resolver->resolve($request));
	}

	public function testObjectKeyRootLevel(): void {
		$request = $this->makeRequest('DELETE', 'mybucket', 'file.txt', []);
		$this->assertSame(OperationResolver::DELETE_OBJECT, $this->resolver->resolve($request));
	}

	// -------------------------------------------------------------------------
	// Error cases
	// -------------------------------------------------------------------------

	public function testMethodNotAllowedOnServiceLevelThrows(): void {
		$this->expectException(S3Exception::class);
		$this->resolver->resolve($this->makeRequest('PUT', null, null, []));
	}

	public function testMethodNotAllowedOnBucketLevelThrows(): void {
		$this->expectException(S3Exception::class);
		$this->resolver->resolve($this->makeRequest('PATCH', 'mybucket', null, []));
	}

	public function testMethodNotAllowedOnObjectLevelThrows(): void {
		$this->expectException(S3Exception::class);
		$this->resolver->resolve($this->makeRequest('PATCH', 'b', 'k', []));
	}

	public function testUnknownPostObjectThrows(): void {
		$this->expectException(S3Exception::class);
		// POST on object without ?uploads, ?uploadId, or ?restore
		$this->resolver->resolve($this->makeRequest('POST', 'b', 'k', []));
	}

	public function testUnknownPostBucketWithoutDeleteThrows(): void {
		$this->expectException(S3Exception::class);
		// POST on bucket without ?delete
		$this->resolver->resolve($this->makeRequest('POST', 'mybucket', null, []));
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function makeRequest(string $method, ?string $bucket, ?string $key, array $queryParams): S3Request {
		return new S3Request(
			method:      $method,
			bucket:      $bucket,
			key:         $key,
			queryParams: $queryParams,
			headers:     [],
			bodyStream:  '',
			rawPath:     '/s3/' . ($bucket ?? '') . ($key ? '/' . $key : ''),
			host:        'localhost',
		);
	}
}
