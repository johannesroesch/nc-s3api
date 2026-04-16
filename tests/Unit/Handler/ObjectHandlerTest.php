<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Tests\Unit\Handler;

use OCA\NcS3Api\Auth\AuthContext;
use OCA\NcS3Api\Exception\AccessDeniedException;
use OCA\NcS3Api\Exception\S3Exception;
use OCA\NcS3Api\Handler\ObjectHandler;
use OCA\NcS3Api\S3\S3Request;
use OCA\NcS3Api\Storage\ObjectService;
use OCA\NcS3Api\Xml\XmlReader;
use OCA\NcS3Api\Xml\XmlWriter;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ObjectHandlerTest extends TestCase {
	private ObjectService&MockObject $objectService;
	private XmlWriter $xmlWriter;
	private XmlReader&MockObject $xmlReader;
	private ObjectHandler $handler;
	private AuthContext $auth;

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->xmlWriter = new XmlWriter();
		$this->xmlReader = $this->createMock(XmlReader::class);
		$this->handler = new ObjectHandler($this->objectService, $this->xmlWriter, $this->xmlReader);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getDisplayName')->willReturn('Alice');
		$this->auth = AuthContext::authenticated($user, AuthContext::METHOD_SIGV4);
	}

	private function request(
		string $method = 'GET',
		?string $bucket = 'my-bucket',
		?string $key = 'my-key.txt',
		array $query = [],
		array $headers = [],
		mixed $body = '',
	): S3Request {
		return new S3Request($method, $bucket, $key, $query, $headers, $body, "/{$bucket}/{$key}", 'localhost');
	}

	// -------------------------------------------------------------------------
	// getObject
	// -------------------------------------------------------------------------

	public function testGetObjectReturnsStream(): void {
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, 'hello world');
		rewind($stream);

		$meta = [
			'size' => 11,
			'content_type' => 'text/plain',
			'etag' => '"abc"',
			'last_modified' => '2024-01-01T00:00:00Z',
		];
		$this->objectService->method('getObjectMeta')->with('alice', 'my-bucket', 'my-key.txt')->willReturn($meta);
		$this->objectService->method('openReadStream')->willReturn($stream);

		$response = $this->handler->getObject($this->request(), $this->auth);

		$this->assertSame(200, $response->statusCode);
		$this->assertSame('11', $response->headers['Content-Length']);
		$this->assertSame('text/plain', $response->headers['Content-Type']);
		$this->assertSame('"abc"', $response->headers['ETag']);
		$this->assertSame('bytes', $response->headers['Accept-Ranges']);
		fclose($stream);
	}

	public function testGetObjectWithRangeReturns206(): void {
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, 'ello');
		rewind($stream);

		$meta = [
			'size' => 11,
			'content_type' => 'text/plain',
			'etag' => '"abc"',
			'last_modified' => '2024-01-01T00:00:00Z',
		];
		$this->objectService->method('getObjectMeta')->willReturn($meta);
		$this->objectService->method('openRangeStream')->willReturn([$stream, 4, 'bytes 1-4/11']);

		$response = $this->handler->getObject(
			$this->request(headers: ['range' => 'bytes=1-4']),
			$this->auth
		);

		$this->assertSame(206, $response->statusCode);
		$this->assertSame('bytes 1-4/11', $response->headers['Content-Range']);
		$this->assertSame('4', $response->headers['Content-Length']);
		fclose($stream);
	}

	public function testGetObjectRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->getObject($this->request(), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// headObject
	// -------------------------------------------------------------------------

	public function testHeadObjectReturns200WithHeaders(): void {
		$meta = [
			'size' => 42,
			'content_type' => 'application/octet-stream',
			'etag' => '"etag-1"',
			'last_modified' => '2024-01-01T00:00:00Z',
		];
		$this->objectService->method('getObjectMeta')->with('alice', 'my-bucket', 'my-key.txt')->willReturn($meta);

		$response = $this->handler->headObject($this->request('HEAD'), $this->auth);

		$this->assertSame(200, $response->statusCode);
		$this->assertSame('42', $response->headers['Content-Length']);
		$this->assertSame('"etag-1"', $response->headers['ETag']);
		$this->assertNull($response->xmlBody);
	}

	public function testHeadObjectRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->headObject($this->request('HEAD'), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// putObject
	// -------------------------------------------------------------------------

	public function testPutObjectReturns200WithETag(): void {
		$this->objectService->method('putObject')
			->with('alice', 'my-bucket', 'my-key.txt', '', 'application/octet-stream')
			->willReturn('"etag-new"');

		$response = $this->handler->putObject($this->request('PUT'), $this->auth);

		$this->assertSame(200, $response->statusCode);
		$this->assertSame('"etag-new"', $response->headers['ETag']);
	}

	public function testPutObjectPassesContentType(): void {
		$this->objectService->expects($this->once())->method('putObject')
			->with('alice', 'my-bucket', 'my-key.txt', '', 'text/csv')
			->willReturn('"etag"');

		$this->handler->putObject(
			$this->request('PUT', headers: ['content-type' => 'text/csv']),
			$this->auth
		);
	}

	public function testPutObjectDefaultsContentType(): void {
		$this->objectService->expects($this->once())->method('putObject')
			->with('alice', 'my-bucket', 'my-key.txt', '', 'application/octet-stream')
			->willReturn('"etag"');

		$this->handler->putObject($this->request('PUT'), $this->auth);
	}

	public function testPutObjectRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->putObject($this->request('PUT'), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// deleteObject
	// -------------------------------------------------------------------------

	public function testDeleteObjectReturns204(): void {
		$this->objectService->expects($this->once())->method('deleteObject')
			->with('alice', 'my-bucket', 'my-key.txt');

		$response = $this->handler->deleteObject($this->request('DELETE'), $this->auth);

		$this->assertSame(204, $response->statusCode);
	}

	public function testDeleteObjectRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->deleteObject($this->request('DELETE'), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// deleteObjects
	// -------------------------------------------------------------------------

	public function testDeleteObjectsReturnsXmlResult(): void {
		$this->xmlReader->method('deleteObjects')->willReturn([
			'objects' => [['key' => 'a.txt'], ['key' => 'b.txt']],
			'quiet' => false,
		]);
		$this->objectService->method('deleteObject');

		$response = $this->handler->deleteObjects(
			$this->request('POST', query: ['delete' => '']),
			$this->auth
		);

		$this->assertSame(200, $response->statusCode);
		$xml = simplexml_load_string($response->xmlBody);
		$this->assertCount(2, $xml->Deleted);
	}

	public function testDeleteObjectsQuietModeReturnsEmpty(): void {
		$this->xmlReader->method('deleteObjects')->willReturn([
			'objects' => [['key' => 'a.txt']],
			'quiet' => true,
		]);

		$response = $this->handler->deleteObjects(
			$this->request('POST', query: ['delete' => '']),
			$this->auth
		);

		$xml = simplexml_load_string($response->xmlBody);
		$this->assertCount(0, $xml->Deleted ?? []);
	}

	public function testDeleteObjectsMissingKeyTreatedAsSuccess(): void {
		$this->xmlReader->method('deleteObjects')->willReturn([
			'objects' => [['key' => 'missing.txt']],
			'quiet' => false,
		]);
		$this->objectService->method('deleteObject')
			->willThrowException(new \OCA\NcS3Api\Exception\NoSuchKeyException('my-bucket', 'missing.txt'));

		$response = $this->handler->deleteObjects($this->request('POST'), $this->auth);

		$xml = simplexml_load_string($response->xmlBody);
		// NoSuchKey is treated as deleted (S3 spec)
		$this->assertCount(1, $xml->Deleted);
	}

	public function testDeleteObjectsRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->deleteObjects($this->request('POST'), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// copyObject
	// -------------------------------------------------------------------------

	public function testCopyObjectReturnsXml(): void {
		$this->objectService->method('copyObject')->willReturn([
			'last_modified' => '2024-01-01T00:00:00.000Z',
			'etag' => '"etag-copied"',
		]);

		$response = $this->handler->copyObject(
			$this->request('PUT', headers: ['x-amz-copy-source' => '/src-bucket/src-key.txt']),
			$this->auth
		);

		$this->assertSame(200, $response->statusCode);
		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame('"etag-copied"', (string)$xml->ETag);
	}

	public function testCopyObjectMissingSourceThrows(): void {
		$this->expectException(S3Exception::class);
		$this->handler->copyObject($this->request('PUT'), $this->auth);
	}

	public function testCopyObjectInvalidSourceThrows(): void {
		$this->expectException(S3Exception::class);
		// Source without key part
		$this->handler->copyObject(
			$this->request('PUT', headers: ['x-amz-copy-source' => 'bucket-only']),
			$this->auth
		);
	}

	public function testCopyObjectRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->copyObject($this->request('PUT'), AuthContext::unauthenticated());
	}
}
