<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Tests\Unit\Handler;

use OCA\NcS3Api\Auth\AuthContext;
use OCA\NcS3Api\Exception\AccessDeniedException;
use OCA\NcS3Api\Handler\ListingHandler;
use OCA\NcS3Api\S3\S3Request;
use OCA\NcS3Api\Storage\ObjectService;
use OCA\NcS3Api\Xml\XmlWriter;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ListingHandlerTest extends TestCase {
	private ObjectService&MockObject $objectService;
	private XmlWriter $xmlWriter;
	private ListingHandler $handler;
	private AuthContext $auth;

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->xmlWriter = new XmlWriter();
		$this->handler = new ListingHandler($this->objectService, $this->xmlWriter);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->auth = AuthContext::authenticated($user, AuthContext::METHOD_SIGV4);
	}

	private function makeObjects(array $keys): array {
		return array_map(fn ($k) => [
			'key' => $k,
			'last_modified' => '2024-01-01T00:00:00.000Z',
			'etag' => '"etag"',
			'size' => 1,
			'storage_class' => 'STANDARD',
		], $keys);
	}

	private function request(
		string $method = 'GET',
		?string $bucket = 'my-bucket',
		array $query = [],
	): S3Request {
		return new S3Request($method, $bucket, null, $query, [], '', "/{$bucket}", 'localhost');
	}

	// -------------------------------------------------------------------------
	// listObjects (v1)
	// -------------------------------------------------------------------------

	public function testListObjectsReturnsAllObjects(): void {
		$this->objectService->method('listObjects')->willReturn(
			$this->makeObjects(['a.txt', 'b.txt', 'c.txt'])
		);

		$response = $this->handler->listObjects($this->request(), $this->auth);

		$this->assertSame(200, $response->statusCode);
		$xml = simplexml_load_string($response->xmlBody);
		$this->assertCount(3, $xml->Contents);
		$this->assertSame('a.txt', (string)$xml->Contents[0]->Key);
		$this->assertSame('false', (string)$xml->IsTruncated);
	}

	public function testListObjectsEmpty(): void {
		$this->objectService->method('listObjects')->willReturn([]);

		$response = $this->handler->listObjects($this->request(), $this->auth);

		$xml = simplexml_load_string($response->xmlBody);
		$this->assertCount(0, $xml->Contents ?? []);
		$this->assertSame('false', (string)$xml->IsTruncated);
	}

	public function testListObjectsTruncatedByMaxKeys(): void {
		$this->objectService->method('listObjects')->willReturn(
			$this->makeObjects(['a.txt', 'b.txt', 'c.txt'])
		);

		$response = $this->handler->listObjects(
			$this->request(query: ['max-keys' => '2']),
			$this->auth
		);

		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame('true', (string)$xml->IsTruncated);
		$this->assertCount(2, $xml->Contents);
		$this->assertSame('b.txt', (string)$xml->NextMarker);
	}

	public function testListObjectsMarkerResumesAfterKey(): void {
		$this->objectService->method('listObjects')->willReturn(
			$this->makeObjects(['a.txt', 'b.txt', 'c.txt'])
		);

		$response = $this->handler->listObjects(
			$this->request(query: ['marker' => 'a.txt']),
			$this->auth
		);

		$xml = simplexml_load_string($response->xmlBody);
		$this->assertCount(2, $xml->Contents);
		$this->assertSame('b.txt', (string)$xml->Contents[0]->Key);
	}

	public function testListObjectsDelimiterGroupsCommonPrefixes(): void {
		$this->objectService->method('listObjects')->willReturn(
			$this->makeObjects(['img/2024/photo.jpg', 'img/2025/photo.jpg', 'doc.pdf'])
		);

		$response = $this->handler->listObjects(
			$this->request(query: ['delimiter' => '/']),
			$this->auth
		);

		$xml = simplexml_load_string($response->xmlBody);
		// Both img files share the prefix 'img/' — only one CommonPrefix
		$this->assertCount(1, $xml->CommonPrefixes);
		$this->assertSame('img/', (string)$xml->CommonPrefixes[0]->Prefix);
		$this->assertCount(1, $xml->Contents); // doc.pdf
	}

	public function testListObjectsMaxKeysCapAt1000(): void {
		// max-keys > 1000 must be silently capped at 1000
		$objects = $this->makeObjects(array_map(fn ($i) => "file-{$i}.txt", range(1, 5)));
		$this->objectService->method('listObjects')->willReturn($objects);

		$response = $this->handler->listObjects(
			$this->request(query: ['max-keys' => '9999']),
			$this->auth
		);

		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame('1000', (string)$xml->MaxKeys);
	}

	public function testListObjectsRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->listObjects($this->request(), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// listObjectsV2
	// -------------------------------------------------------------------------

	public function testListObjectsV2ReturnsKeyCount(): void {
		$this->objectService->method('listObjects')->willReturn(
			$this->makeObjects(['x.txt', 'y.txt'])
		);

		$response = $this->handler->listObjectsV2($this->request(), $this->auth);

		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame('2', (string)$xml->KeyCount);
		$this->assertCount(2, $xml->Contents);
	}

	public function testListObjectsV2TruncatedWithContinuationToken(): void {
		$this->objectService->method('listObjects')->willReturn(
			$this->makeObjects(['a.txt', 'b.txt', 'c.txt'])
		);

		$response = $this->handler->listObjectsV2(
			$this->request(query: ['max-keys' => '2']),
			$this->auth
		);

		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame('true', (string)$xml->IsTruncated);
		// NextContinuationToken is base64 of last returned key
		$this->assertNotEmpty((string)$xml->NextContinuationToken);
		$this->assertSame('b.txt', base64_decode((string)$xml->NextContinuationToken));
	}

	public function testListObjectsV2ContinuationTokenResumes(): void {
		$this->objectService->method('listObjects')->willReturn(
			$this->makeObjects(['a.txt', 'b.txt', 'c.txt'])
		);

		$token = base64_encode('b.txt');
		$response = $this->handler->listObjectsV2(
			$this->request(query: ['continuation-token' => $token]),
			$this->auth
		);

		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame('1', (string)$xml->KeyCount);
		$this->assertSame('c.txt', (string)$xml->Contents->Key);
	}

	public function testListObjectsV2StartAfter(): void {
		$this->objectService->method('listObjects')->willReturn(
			$this->makeObjects(['a.txt', 'b.txt', 'c.txt'])
		);

		$response = $this->handler->listObjectsV2(
			$this->request(query: ['start-after' => 'a.txt']),
			$this->auth
		);

		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame('2', (string)$xml->KeyCount);
		$this->assertSame('b.txt', (string)$xml->Contents[0]->Key);
	}

	public function testListObjectsV2DelimiterProducesCommonPrefixes(): void {
		$this->objectService->method('listObjects')->willReturn(
			$this->makeObjects(['dir/a.txt', 'dir/b.txt', 'root.txt'])
		);

		$response = $this->handler->listObjectsV2(
			$this->request(query: ['delimiter' => '/']),
			$this->auth
		);

		$xml = simplexml_load_string($response->xmlBody);
		$this->assertCount(1, $xml->CommonPrefixes); // 'dir/'
		$this->assertCount(1, $xml->Contents);       // 'root.txt'
	}

	public function testListObjectsV2RequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->listObjectsV2($this->request(), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// Pagination ordering
	// -------------------------------------------------------------------------

	public function testObjectsAreSortedLexicographically(): void {
		// Objects returned in reverse order must be sorted
		$this->objectService->method('listObjects')->willReturn(
			$this->makeObjects(['z.txt', 'a.txt', 'm.txt'])
		);

		$response = $this->handler->listObjects($this->request(), $this->auth);

		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame('a.txt', (string)$xml->Contents[0]->Key);
		$this->assertSame('m.txt', (string)$xml->Contents[1]->Key);
		$this->assertSame('z.txt', (string)$xml->Contents[2]->Key);
	}
}
