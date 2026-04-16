<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Tests\Unit\Handler;

use OCA\NcS3Api\Auth\AuthContext;
use OCA\NcS3Api\Db\BucketMetadata;
use OCA\NcS3Api\Db\BucketMetadataMapper;
use OCA\NcS3Api\Exception\AccessDeniedException;
use OCA\NcS3Api\Exception\S3Exception;
use OCA\NcS3Api\Handler\VersioningHandler;
use OCA\NcS3Api\S3\S3Request;
use OCA\NcS3Api\Storage\BucketService;
use OCA\NcS3Api\Storage\ObjectService;
use OCA\NcS3Api\Xml\XmlReader;
use OCA\NcS3Api\Xml\XmlWriter;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class VersioningHandlerTest extends TestCase {
	private BucketMetadataMapper&MockObject $metaMapper;
	private BucketService&MockObject $bucketService;
	private ObjectService&MockObject $objectService;
	private XmlWriter $xmlWriter;
	private XmlReader&MockObject $xmlReader;
	private VersioningHandler $handler;
	private AuthContext $auth;

	protected function setUp(): void {
		$this->metaMapper = $this->createMock(BucketMetadataMapper::class);
		$this->bucketService = $this->createMock(BucketService::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->xmlWriter = new XmlWriter();
		$this->xmlReader = $this->createMock(XmlReader::class);
		$this->handler = new VersioningHandler(
			$this->metaMapper,
			$this->bucketService,
			$this->objectService,
			$this->xmlWriter,
			$this->xmlReader,
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->auth = AuthContext::authenticated($user, AuthContext::METHOD_SIGV4);
	}

	private function request(string $method = 'GET', ?string $bucket = 'my-bucket', array $query = []): S3Request {
		return new S3Request($method, $bucket, null, $query, [], '', "/{$bucket}", 'localhost');
	}

	private function metaWithStatus(string $status): BucketMetadata {
		$meta = new BucketMetadata();
		$meta->setMetaValue(json_encode(['status' => $status]));
		return $meta;
	}

	// -------------------------------------------------------------------------
	// getBucketVersioning
	// -------------------------------------------------------------------------

	public function testGetBucketVersioningEnabled(): void {
		$this->metaMapper->method('find')->willReturn($this->metaWithStatus('Enabled'));

		$response = $this->handler->getBucketVersioning($this->request(), $this->auth);

		$this->assertSame(200, $response->statusCode);
		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame('Enabled', (string)$xml->Status);
	}

	public function testGetBucketVersioningSuspended(): void {
		$this->metaMapper->method('find')->willReturn($this->metaWithStatus('Suspended'));

		$response = $this->handler->getBucketVersioning($this->request(), $this->auth);

		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame('Suspended', (string)$xml->Status);
	}

	public function testGetBucketVersioningNeverConfiguredReturnsEmpty(): void {
		$this->metaMapper->method('find')->willThrowException(new DoesNotExistException(''));

		$response = $this->handler->getBucketVersioning($this->request(), $this->auth);

		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame('', (string)$xml->Status);
	}

	public function testGetBucketVersioningRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->getBucketVersioning($this->request(), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// putBucketVersioning
	// -------------------------------------------------------------------------

	public function testPutBucketVersioningEnabled(): void {
		$this->xmlReader->method('versioningConfiguration')->willReturn('Enabled');
		$this->metaMapper->expects($this->once())->method('upsert')
			->with('alice', 'my-bucket', 'versioning', ['status' => 'Enabled']);

		$response = $this->handler->putBucketVersioning($this->request('PUT'), $this->auth);

		$this->assertSame(204, $response->statusCode);
	}

	public function testPutBucketVersioningSuspended(): void {
		$this->xmlReader->method('versioningConfiguration')->willReturn('Suspended');
		$this->metaMapper->expects($this->once())->method('upsert');

		$response = $this->handler->putBucketVersioning($this->request('PUT'), $this->auth);
		$this->assertSame(204, $response->statusCode);
	}

	public function testPutBucketVersioningInvalidStatusThrows(): void {
		$this->xmlReader->method('versioningConfiguration')->willReturn('Invalid');

		$this->expectException(S3Exception::class);
		$this->handler->putBucketVersioning($this->request('PUT'), $this->auth);
	}

	public function testPutBucketVersioningRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->putBucketVersioning($this->request('PUT'), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// listObjectVersions
	// -------------------------------------------------------------------------

	public function testListObjectVersionsReturnsCurrentVersions(): void {
		$this->objectService->method('listObjects')->willReturn([
			[
				'key' => 'file.txt',
				'last_modified' => '2024-01-01T00:00:00.000Z',
				'etag' => '"abc"',
				'size' => 10,
			],
		]);

		$response = $this->handler->listObjectVersions($this->request(), $this->auth);

		$this->assertSame(200, $response->statusCode);
		$xml = simplexml_load_string($response->xmlBody);
		$this->assertNotEmpty((string)$xml->Version->Key ?? $xml->Version);
	}

	public function testListObjectVersionsRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->listObjectVersions($this->request(), AuthContext::unauthenticated());
	}
}
