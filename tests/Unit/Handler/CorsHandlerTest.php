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
use OCA\NcS3Api\Handler\CorsHandler;
use OCA\NcS3Api\S3\S3Request;
use OCA\NcS3Api\Storage\BucketService;
use OCA\NcS3Api\Xml\XmlReader;
use OCA\NcS3Api\Xml\XmlWriter;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CorsHandlerTest extends TestCase {
	private BucketMetadataMapper&MockObject $metaMapper;
	private BucketService&MockObject $bucketService;
	private XmlWriter $xmlWriter;
	private XmlReader&MockObject $xmlReader;
	private CorsHandler $handler;
	private AuthContext $auth;

	protected function setUp(): void {
		$this->metaMapper = $this->createMock(BucketMetadataMapper::class);
		$this->bucketService = $this->createMock(BucketService::class);
		$this->xmlWriter = new XmlWriter();
		$this->xmlReader = $this->createMock(XmlReader::class);
		$this->handler = new CorsHandler(
			$this->metaMapper,
			$this->bucketService,
			$this->xmlWriter,
			$this->xmlReader,
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->auth = AuthContext::authenticated($user, AuthContext::METHOD_SIGV4);
	}

	private function request(string $method = 'GET', ?string $bucket = 'my-bucket'): S3Request {
		return new S3Request($method, $bucket, null, [], [], '', "/{$bucket}", 'localhost');
	}

	private function metaWithRules(array $rules): BucketMetadata {
		$meta = new BucketMetadata();
		$meta->setMetaValue(json_encode($rules));
		return $meta;
	}

	// -------------------------------------------------------------------------
	// getBucketCors
	// -------------------------------------------------------------------------

	public function testGetBucketCorsReturnsRules(): void {
		$rules = [[
			'allowed_origins' => ['https://app.example.com'],
			'allowed_methods' => ['GET', 'PUT'],
			'allowed_headers' => ['Authorization'],
			'max_age_seconds' => 3600,
		]];
		$this->metaMapper->method('find')->willReturn($this->metaWithRules($rules));

		$response = $this->handler->getBucketCors($this->request(), $this->auth);

		$this->assertSame(200, $response->statusCode);
		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame('https://app.example.com', (string)$xml->CORSRule->AllowedOrigin);
		$this->assertSame('GET', (string)$xml->CORSRule->AllowedMethod[0]);
		$this->assertSame('3600', (string)$xml->CORSRule->MaxAgeSeconds);
	}

	public function testGetBucketCorsNotConfiguredThrows(): void {
		$this->metaMapper->method('find')->willThrowException(new DoesNotExistException(''));

		$this->expectException(S3Exception::class);
		$this->handler->getBucketCors($this->request(), $this->auth);
	}

	public function testGetBucketCorsRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->getBucketCors($this->request(), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// putBucketCors
	// -------------------------------------------------------------------------

	public function testPutBucketCorsStoresRules(): void {
		$rules = [['allowed_origins' => ['*'], 'allowed_methods' => ['GET']]];
		$this->xmlReader->method('corsConfiguration')->willReturn($rules);
		$this->metaMapper->expects($this->once())->method('upsert')
			->with('alice', 'my-bucket', 'cors', $rules);

		$response = $this->handler->putBucketCors($this->request('PUT'), $this->auth);

		$this->assertSame(204, $response->statusCode);
	}

	public function testPutBucketCorsRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->putBucketCors($this->request('PUT'), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// deleteBucketCors
	// -------------------------------------------------------------------------

	public function testDeleteBucketCorsReturns204(): void {
		$this->metaMapper->expects($this->once())->method('deleteByKey')
			->with('alice', 'my-bucket', 'cors');

		$response = $this->handler->deleteBucketCors($this->request('DELETE'), $this->auth);

		$this->assertSame(204, $response->statusCode);
	}

	public function testDeleteBucketCorsRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->deleteBucketCors($this->request('DELETE'), AuthContext::unauthenticated());
	}
}
