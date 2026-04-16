<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Tests\Unit\Handler;

use OCA\NcS3Api\Auth\AuthContext;
use OCA\NcS3Api\Db\BucketTag;
use OCA\NcS3Api\Db\BucketTagMapper;
use OCA\NcS3Api\Db\ObjectTag;
use OCA\NcS3Api\Db\ObjectTagMapper;
use OCA\NcS3Api\Exception\AccessDeniedException;
use OCA\NcS3Api\Exception\S3Exception;
use OCA\NcS3Api\Handler\TaggingHandler;
use OCA\NcS3Api\S3\S3Request;
use OCA\NcS3Api\Storage\BucketService;
use OCA\NcS3Api\Storage\ObjectService;
use OCA\NcS3Api\Xml\XmlReader;
use OCA\NcS3Api\Xml\XmlWriter;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TaggingHandlerTest extends TestCase {
	private ObjectTagMapper&MockObject $objectTagMapper;
	private BucketTagMapper&MockObject $bucketTagMapper;
	private BucketService&MockObject $bucketService;
	private ObjectService&MockObject $objectService;
	private XmlWriter $xmlWriter;
	private XmlReader&MockObject $xmlReader;
	private TaggingHandler $handler;
	private AuthContext $auth;

	protected function setUp(): void {
		$this->objectTagMapper = $this->createMock(ObjectTagMapper::class);
		$this->bucketTagMapper = $this->createMock(BucketTagMapper::class);
		$this->bucketService = $this->createMock(BucketService::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->xmlWriter = new XmlWriter();
		$this->xmlReader = $this->createMock(XmlReader::class);
		$this->handler = new TaggingHandler(
			$this->objectTagMapper,
			$this->bucketTagMapper,
			$this->bucketService,
			$this->objectService,
			$this->xmlWriter,
			$this->xmlReader,
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->auth = AuthContext::authenticated($user, AuthContext::METHOD_SIGV4);
	}

	private function request(
		string $method = 'GET',
		?string $bucket = 'my-bucket',
		?string $key = null,
		mixed $body = '',
	): S3Request {
		$path = $key ? "/{$bucket}/{$key}" : "/{$bucket}";
		return new S3Request($method, $bucket, $key, [], [], $body, $path, 'localhost');
	}

	private function makeObjectTag(string $key, string $value): ObjectTag {
		$tag = new ObjectTag();
		$tag->setTagKey($key);
		$tag->setTagValue($value);
		return $tag;
	}

	private function makeBucketTag(string $key, string $value): BucketTag {
		$tag = new BucketTag();
		$tag->setTagKey($key);
		$tag->setTagValue($value);
		return $tag;
	}

	// -------------------------------------------------------------------------
	// getObjectTagging
	// -------------------------------------------------------------------------

	public function testGetObjectTaggingReturnsTags(): void {
		$this->objectService->method('getObjectMeta')->willReturn(['size' => 1, 'content_type' => '', 'etag' => '', 'last_modified' => '']);
		$this->objectTagMapper->method('findByObject')->willReturn([
			$this->makeObjectTag('env', 'prod'),
			$this->makeObjectTag('team', 'ops'),
		]);

		$response = $this->handler->getObjectTagging($this->request(key: 'file.txt'), $this->auth);

		$this->assertSame(200, $response->statusCode);
		$xml = simplexml_load_string($response->xmlBody);
		$tags = $xml->TagSet->Tag;
		$this->assertCount(2, $tags);
		$this->assertSame('env', (string)$tags[0]->Key);
		$this->assertSame('prod', (string)$tags[0]->Value);
	}

	public function testGetObjectTaggingReturnsEmptyTagSet(): void {
		$this->objectService->method('getObjectMeta')->willReturn(['size' => 1, 'content_type' => '', 'etag' => '', 'last_modified' => '']);
		$this->objectTagMapper->method('findByObject')->willReturn([]);

		$response = $this->handler->getObjectTagging($this->request(key: 'file.txt'), $this->auth);

		$xml = simplexml_load_string($response->xmlBody);
		$this->assertCount(0, $xml->TagSet->Tag ?? []);
	}

	public function testGetObjectTaggingRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->getObjectTagging($this->request(key: 'file.txt'), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// putObjectTagging
	// -------------------------------------------------------------------------

	public function testPutObjectTaggingStoresTags(): void {
		$this->objectService->method('getObjectMeta')->willReturn(['size' => 1, 'content_type' => '', 'etag' => '', 'last_modified' => '']);
		$this->xmlReader->method('tagging')->willReturn([
			['key' => 'project', 'value' => 'alpha'],
		]);
		$this->objectTagMapper->expects($this->once())->method('deleteByObject')
			->with('alice', 'my-bucket', 'file.txt');
		$this->objectTagMapper->expects($this->once())->method('insert');

		$response = $this->handler->putObjectTagging($this->request('PUT', key: 'file.txt'), $this->auth);

		$this->assertSame(204, $response->statusCode);
	}

	public function testPutObjectTaggingTooManyTagsThrows(): void {
		$this->objectService->method('getObjectMeta')->willReturn(['size' => 1, 'content_type' => '', 'etag' => '', 'last_modified' => '']);
		// 11 tags — one over the limit of 10
		$tags = array_map(fn ($i) => ['key' => "k{$i}", 'value' => "v{$i}"], range(1, 11));
		$this->xmlReader->method('tagging')->willReturn($tags);

		$this->expectException(S3Exception::class);
		$this->handler->putObjectTagging($this->request('PUT', key: 'file.txt'), $this->auth);
	}

	public function testPutObjectTaggingRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->putObjectTagging($this->request('PUT', key: 'file.txt'), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// deleteObjectTagging
	// -------------------------------------------------------------------------

	public function testDeleteObjectTaggingReturns204(): void {
		$this->objectTagMapper->expects($this->once())->method('deleteByObject')
			->with('alice', 'my-bucket', 'file.txt');

		$response = $this->handler->deleteObjectTagging($this->request('DELETE', key: 'file.txt'), $this->auth);

		$this->assertSame(204, $response->statusCode);
	}

	public function testDeleteObjectTaggingRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->deleteObjectTagging($this->request('DELETE', key: 'file.txt'), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// getBucketTagging
	// -------------------------------------------------------------------------

	public function testGetBucketTaggingReturnsTags(): void {
		$this->bucketTagMapper->method('findByBucket')->willReturn([
			$this->makeBucketTag('cost-center', '42'),
		]);

		$response = $this->handler->getBucketTagging($this->request(), $this->auth);

		$this->assertSame(200, $response->statusCode);
		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame('cost-center', (string)$xml->TagSet->Tag->Key);
		$this->assertSame('42', (string)$xml->TagSet->Tag->Value);
	}

	public function testGetBucketTaggingRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->getBucketTagging($this->request(), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// putBucketTagging
	// -------------------------------------------------------------------------

	public function testPutBucketTaggingStoresTags(): void {
		$this->xmlReader->method('tagging')->willReturn([
			['key' => 'env', 'value' => 'dev'],
		]);
		$this->bucketTagMapper->expects($this->once())->method('deleteByBucket');
		$this->bucketTagMapper->expects($this->once())->method('insert');

		$response = $this->handler->putBucketTagging($this->request('PUT'), $this->auth);

		$this->assertSame(204, $response->statusCode);
	}

	public function testPutBucketTaggingTooManyTagsThrows(): void {
		// 51 tags — one over the limit of 50
		$tags = array_map(fn ($i) => ['key' => "k{$i}", 'value' => "v{$i}"], range(1, 51));
		$this->xmlReader->method('tagging')->willReturn($tags);

		$this->expectException(S3Exception::class);
		$this->handler->putBucketTagging($this->request('PUT'), $this->auth);
	}

	public function testPutBucketTaggingRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->putBucketTagging($this->request('PUT'), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// deleteBucketTagging
	// -------------------------------------------------------------------------

	public function testDeleteBucketTaggingReturns204(): void {
		$this->bucketTagMapper->expects($this->once())->method('deleteByBucket')
			->with('alice', 'my-bucket');

		$response = $this->handler->deleteBucketTagging($this->request('DELETE'), $this->auth);

		$this->assertSame(204, $response->statusCode);
	}

	public function testDeleteBucketTaggingRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->deleteBucketTagging($this->request('DELETE'), AuthContext::unauthenticated());
	}
}
