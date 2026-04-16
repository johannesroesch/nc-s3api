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
use OCA\NcS3Api\Handler\EncryptionHandler;
use OCA\NcS3Api\S3\S3Request;
use OCA\NcS3Api\Storage\BucketService;
use OCA\NcS3Api\Xml\XmlWriter;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class EncryptionHandlerTest extends TestCase {
	private BucketMetadataMapper&MockObject $metaMapper;
	private BucketService&MockObject $bucketService;
	private XmlWriter $xmlWriter;
	private EncryptionHandler $handler;
	private AuthContext $auth;

	protected function setUp(): void {
		$this->metaMapper = $this->createMock(BucketMetadataMapper::class);
		$this->bucketService = $this->createMock(BucketService::class);
		$this->xmlWriter = new XmlWriter();
		$this->handler = new EncryptionHandler(
			$this->metaMapper,
			$this->bucketService,
			$this->xmlWriter,
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->auth = AuthContext::authenticated($user, AuthContext::METHOD_SIGV4);
	}

	private function request(string $method = 'GET', ?string $bucket = 'my-bucket'): S3Request {
		return new S3Request($method, $bucket, null, [], [], '', "/{$bucket}", 'localhost');
	}

	// -------------------------------------------------------------------------
	// getBucketEncryption
	// -------------------------------------------------------------------------

	public function testGetBucketEncryptionReturnsAES256(): void {
		$meta = new BucketMetadata();
		$meta->setMetaValue(json_encode(['algorithm' => 'AES256']));
		$this->metaMapper->method('find')->willReturn($meta);

		$response = $this->handler->getBucketEncryption($this->request(), $this->auth);

		$this->assertSame(200, $response->statusCode);
		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame('AES256', (string)$xml->Rule->ApplyServerSideEncryptionByDefault->SSEAlgorithm);
	}

	public function testGetBucketEncryptionNotConfiguredThrows(): void {
		$this->metaMapper->method('find')->willThrowException(new DoesNotExistException(''));

		$this->expectException(S3Exception::class);
		$this->handler->getBucketEncryption($this->request(), $this->auth);
	}

	public function testGetBucketEncryptionRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->getBucketEncryption($this->request(), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// putBucketEncryption
	// -------------------------------------------------------------------------

	public function testPutBucketEncryptionStoresAES256(): void {
		$this->metaMapper->expects($this->once())->method('upsert')
			->with('alice', 'my-bucket', 'encryption', ['algorithm' => 'AES256']);

		$response = $this->handler->putBucketEncryption($this->request('PUT'), $this->auth);

		$this->assertSame(204, $response->statusCode);
	}

	public function testPutBucketEncryptionRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->putBucketEncryption($this->request('PUT'), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// deleteBucketEncryption
	// -------------------------------------------------------------------------

	public function testDeleteBucketEncryptionReturns204(): void {
		$this->metaMapper->expects($this->once())->method('deleteByKey')
			->with('alice', 'my-bucket', 'encryption');

		$response = $this->handler->deleteBucketEncryption($this->request('DELETE'), $this->auth);

		$this->assertSame(204, $response->statusCode);
	}

	public function testDeleteBucketEncryptionRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->deleteBucketEncryption($this->request('DELETE'), AuthContext::unauthenticated());
	}
}
