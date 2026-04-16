<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Tests\Unit\Handler;

use OCA\NcS3Api\Auth\AuthContext;
use OCA\NcS3Api\Db\BucketMetadataMapper;
use OCA\NcS3Api\Exception\AccessDeniedException;
use OCA\NcS3Api\Handler\AclHandler;
use OCA\NcS3Api\S3\S3Request;
use OCA\NcS3Api\Storage\BucketService;
use OCA\NcS3Api\Storage\ObjectService;
use OCA\NcS3Api\Xml\XmlWriter;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AclHandlerTest extends TestCase {
	private BucketMetadataMapper&MockObject $metaMapper;
	private BucketService&MockObject $bucketService;
	private ObjectService&MockObject $objectService;
	private XmlWriter $xmlWriter;
	private AclHandler $handler;
	private AuthContext $auth;

	protected function setUp(): void {
		$this->metaMapper = $this->createMock(BucketMetadataMapper::class);
		$this->bucketService = $this->createMock(BucketService::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->xmlWriter = new XmlWriter();
		$this->handler = new AclHandler(
			$this->metaMapper,
			$this->bucketService,
			$this->objectService,
			$this->xmlWriter,
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getDisplayName')->willReturn('Alice');
		$this->auth = AuthContext::authenticated($user, AuthContext::METHOD_SIGV4);
	}

	private function request(
		string $method = 'GET',
		?string $bucket = 'my-bucket',
		?string $key = null,
		array $headers = [],
	): S3Request {
		$path = $key ? "/{$bucket}/{$key}" : "/{$bucket}";
		return new S3Request($method, $bucket, $key, [], $headers, '', $path, 'localhost');
	}

	// -------------------------------------------------------------------------
	// getBucketAcl
	// -------------------------------------------------------------------------

	public function testGetBucketAclReturnsOwnerWithFullControl(): void {
		$response = $this->handler->getBucketAcl($this->request(), $this->auth);

		$this->assertSame(200, $response->statusCode);
		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame('alice', (string)$xml->Owner->ID);
		$this->assertSame('Alice', (string)$xml->Owner->DisplayName);
		$this->assertSame('FULL_CONTROL', (string)$xml->AccessControlList->Grant->Permission);
	}

	public function testGetBucketAclRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->getBucketAcl($this->request(), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// putBucketAcl
	// -------------------------------------------------------------------------

	public function testPutBucketAclStoresCannedAcl(): void {
		$this->metaMapper->expects($this->once())->method('upsert')
			->with('alice', 'my-bucket', 'acl', ['canned' => 'public-read']);

		$response = $this->handler->putBucketAcl(
			$this->request('PUT', headers: ['x-amz-acl' => 'public-read']),
			$this->auth
		);

		$this->assertSame(204, $response->statusCode);
	}

	public function testPutBucketAclDefaultsToPrivate(): void {
		$this->metaMapper->expects($this->once())->method('upsert')
			->with('alice', 'my-bucket', 'acl', ['canned' => 'private']);

		$this->handler->putBucketAcl($this->request('PUT'), $this->auth);
	}

	public function testPutBucketAclRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->putBucketAcl($this->request('PUT'), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// getObjectAcl
	// -------------------------------------------------------------------------

	public function testGetObjectAclReturnsOwnerWithFullControl(): void {
		$this->objectService->method('getObjectMeta')->willReturn([
			'size' => 10,
			'content_type' => 'text/plain',
			'etag' => '"abc"',
			'last_modified' => '2024-01-01T00:00:00Z',
		]);

		$response = $this->handler->getObjectAcl(
			$this->request('GET', key: 'my-key.txt'),
			$this->auth
		);

		$this->assertSame(200, $response->statusCode);
		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame('alice', (string)$xml->Owner->ID);
		$this->assertSame('FULL_CONTROL', (string)$xml->AccessControlList->Grant->Permission);
	}

	public function testGetObjectAclRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->getObjectAcl(
			$this->request('GET', key: 'my-key.txt'),
			AuthContext::unauthenticated()
		);
	}

	// -------------------------------------------------------------------------
	// putObjectAcl
	// -------------------------------------------------------------------------

	public function testPutObjectAclReturns204(): void {
		$response = $this->handler->putObjectAcl(
			$this->request('PUT', key: 'my-key.txt'),
			$this->auth
		);

		$this->assertSame(204, $response->statusCode);
	}

	public function testPutObjectAclRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->putObjectAcl(
			$this->request('PUT', key: 'my-key.txt'),
			AuthContext::unauthenticated()
		);
	}
}
