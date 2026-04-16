<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Tests\Unit\Handler;

use OCA\NcS3Api\Auth\AuthContext;
use OCA\NcS3Api\Exception\AccessDeniedException;
use OCA\NcS3Api\Handler\BucketHandler;
use OCA\NcS3Api\S3\S3Request;
use OCA\NcS3Api\Storage\BucketService;
use OCA\NcS3Api\Xml\XmlWriter;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BucketHandlerTest extends TestCase {
	private BucketService&MockObject $bucketService;
	private XmlWriter $xmlWriter;
	private BucketHandler $handler;
	private AuthContext $auth;

	protected function setUp(): void {
		$this->bucketService = $this->createMock(BucketService::class);
		$this->xmlWriter = new XmlWriter();
		$this->handler = new BucketHandler($this->bucketService, $this->xmlWriter);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getDisplayName')->willReturn('Alice');
		$this->auth = AuthContext::authenticated($user, AuthContext::METHOD_SIGV4);
	}

	private function request(
		string $method = 'GET',
		?string $bucket = null,
		?string $key = null,
		array $query = [],
		array $headers = [],
	): S3Request {
		return new S3Request($method, $bucket, $key, $query, $headers, '', "/{$bucket}", 'localhost');
	}

	// -------------------------------------------------------------------------
	// listBuckets
	// -------------------------------------------------------------------------

	public function testListBucketsReturnsXml(): void {
		$this->bucketService->method('listBuckets')->with('alice')->willReturn([
			['name' => 'photos', 'creation_date' => '2024-01-01T00:00:00.000Z'],
			['name' => 'videos', 'creation_date' => '2024-02-01T00:00:00.000Z'],
		]);

		$response = $this->handler->listBuckets($this->request(), $this->auth);

		$this->assertSame(200, $response->statusCode);
		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame('photos', (string)$xml->Buckets->Bucket[0]->Name);
		$this->assertSame('videos', (string)$xml->Buckets->Bucket[1]->Name);
		$this->assertSame('alice', (string)$xml->Owner->ID);
		$this->assertSame('Alice', (string)$xml->Owner->DisplayName);
	}

	public function testListBucketsEmpty(): void {
		$this->bucketService->method('listBuckets')->willReturn([]);

		$response = $this->handler->listBuckets($this->request(), $this->auth);

		$xml = simplexml_load_string($response->xmlBody);
		$this->assertCount(0, $xml->Buckets->Bucket ?? []);
	}

	public function testListBucketsRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->listBuckets($this->request(), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// createBucket
	// -------------------------------------------------------------------------

	public function testCreateBucketReturns200WithLocation(): void {
		$this->bucketService->expects($this->once())->method('createBucket')->with('alice', 'my-bucket');

		$response = $this->handler->createBucket($this->request('PUT', 'my-bucket'), $this->auth);

		$this->assertSame(200, $response->statusCode);
		$this->assertSame('/my-bucket', $response->headers['Location']);
	}

	public function testCreateBucketRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->createBucket($this->request('PUT', 'x'), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// deleteBucket
	// -------------------------------------------------------------------------

	public function testDeleteBucketReturns204(): void {
		$this->bucketService->expects($this->once())->method('deleteBucket')->with('alice', 'my-bucket');

		$response = $this->handler->deleteBucket($this->request('DELETE', 'my-bucket'), $this->auth);

		$this->assertSame(204, $response->statusCode);
	}

	public function testDeleteBucketRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->deleteBucket($this->request('DELETE', 'x'), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// headBucket
	// -------------------------------------------------------------------------

	public function testHeadBucketReturns200(): void {
		$this->bucketService->expects($this->once())->method('headBucket')->with('alice', 'my-bucket');

		$response = $this->handler->headBucket($this->request('HEAD', 'my-bucket'), $this->auth);

		$this->assertSame(200, $response->statusCode);
		$this->assertNull($response->xmlBody);
	}

	public function testHeadBucketRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->headBucket($this->request('HEAD', 'x'), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// getBucketLocation
	// -------------------------------------------------------------------------

	public function testGetBucketLocationReturnsEmptyRegion(): void {
		$this->bucketService->method('headBucket')->willReturn(['name' => 'my-bucket', 'creation_date' => '2024-01-01T00:00:00.000Z']);

		$response = $this->handler->getBucketLocation($this->request('GET', 'my-bucket', null, ['location' => '']), $this->auth);

		$this->assertSame(200, $response->statusCode);
		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame('', (string)$xml);
	}

	public function testGetBucketLocationRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->getBucketLocation($this->request('GET', 'x'), AuthContext::unauthenticated());
	}
}
