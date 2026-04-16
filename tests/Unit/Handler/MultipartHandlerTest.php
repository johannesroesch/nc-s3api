<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Tests\Unit\Handler;

use OCA\NcS3Api\Auth\AuthContext;
use OCA\NcS3Api\Db\MultipartPart;
use OCA\NcS3Api\Db\MultipartPartMapper;
use OCA\NcS3Api\Db\MultipartUpload;
use OCA\NcS3Api\Db\MultipartUploadMapper;
use OCA\NcS3Api\Exception\AccessDeniedException;
use OCA\NcS3Api\Exception\NoSuchUploadException;
use OCA\NcS3Api\Exception\S3Exception;
use OCA\NcS3Api\Handler\MultipartHandler;
use OCA\NcS3Api\S3\S3Request;
use OCA\NcS3Api\Storage\BucketService;
use OCA\NcS3Api\Storage\ObjectService;
use OCA\NcS3Api\Storage\StorageMapper;
use OCA\NcS3Api\Xml\XmlReader;
use OCA\NcS3Api\Xml\XmlWriter;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MultipartHandlerTest extends TestCase {
	private MultipartUploadMapper&MockObject $uploadMapper;
	private MultipartPartMapper&MockObject $partMapper;
	private BucketService&MockObject $bucketService;
	private ObjectService&MockObject $objectService;
	private StorageMapper&MockObject $storageMapper;
	private IRootFolder&MockObject $rootFolder;
	private XmlWriter $xmlWriter;
	private XmlReader&MockObject $xmlReader;
	private MultipartHandler $handler;
	private AuthContext $auth;

	protected function setUp(): void {
		$this->uploadMapper = $this->createMock(MultipartUploadMapper::class);
		$this->partMapper = $this->createMock(MultipartPartMapper::class);
		$this->bucketService = $this->createMock(BucketService::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->storageMapper = $this->createMock(StorageMapper::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->xmlWriter = new XmlWriter();
		$this->xmlReader = $this->createMock(XmlReader::class);
		$this->handler = new MultipartHandler(
			$this->uploadMapper,
			$this->partMapper,
			$this->bucketService,
			$this->objectService,
			$this->storageMapper,
			$this->rootFolder,
			$this->xmlWriter,
			$this->xmlReader,
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->auth = AuthContext::authenticated($user, AuthContext::METHOD_SIGV4);
	}

	private function request(
		string $method = 'POST',
		?string $bucket = 'my-bucket',
		?string $key = 'big.bin',
		array $query = [],
		array $headers = [],
		mixed $body = '',
	): S3Request {
		return new S3Request($method, $bucket, $key, $query, $headers, $body, "/{$bucket}/{$key}", 'localhost');
	}

	private function makeUpload(string $uploadId, string $userId = 'alice'): MultipartUpload {
		$upload = new MultipartUpload();
		$upload->setUploadId($uploadId);
		$upload->setBucket('my-bucket');
		$upload->setObjectKey('big.bin');
		$upload->setUserId($userId);
		$upload->setCreatedAt(time());
		return $upload;
	}

	private function makePart(int $number, string $etag, int $size = 5 * 1024 * 1024): MultipartPart {
		$part = new MultipartPart();
		$part->setUploadId('upload-1');
		$part->setPartNumber($number);
		$part->setEtag($etag);
		$part->setSize($size);
		$part->setTmpPath(".s3_uploads/upload-1/part-{$number}");
		$part->setCreatedAt(time());
		return $part;
	}

	// -------------------------------------------------------------------------
	// initiateMultipartUpload
	// -------------------------------------------------------------------------

	public function testInitiateMultipartUploadReturnsXmlWithUploadId(): void {
		$this->uploadMapper->expects($this->once())->method('insert');

		$response = $this->handler->initiateMultipartUpload($this->request(), $this->auth);

		$this->assertSame(200, $response->statusCode);
		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame('my-bucket', (string)$xml->Bucket);
		$this->assertSame('big.bin', (string)$xml->Key);
		$this->assertNotEmpty((string)$xml->UploadId);
	}

	public function testInitiateMultipartUploadSetsContentType(): void {
		$capturedEntity = null;
		$this->uploadMapper->expects($this->once())->method('insert')
			->willReturnCallback(function ($entity) use (&$capturedEntity) {
				$capturedEntity = $entity;
			});

		$this->handler->initiateMultipartUpload(
			$this->request(headers: ['content-type' => 'video/mp4']),
			$this->auth
		);

		$this->assertSame('video/mp4', $capturedEntity->getContentType());
	}

	public function testInitiateMultipartUploadRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->initiateMultipartUpload($this->request(), AuthContext::unauthenticated());
	}

	// -------------------------------------------------------------------------
	// uploadPart
	// -------------------------------------------------------------------------

	public function testUploadPartInvalidPartNumberThrows(): void {
		$this->uploadMapper->method('findByUploadId')->willReturn($this->makeUpload('uid-1'));

		$this->expectException(S3Exception::class);
		$this->handler->uploadPart(
			$this->request(query: ['uploadId' => 'uid-1', 'partNumber' => '0']),
			$this->auth
		);
	}

	public function testUploadPartTooHighPartNumberThrows(): void {
		$this->uploadMapper->method('findByUploadId')->willReturn($this->makeUpload('uid-1'));

		$this->expectException(S3Exception::class);
		$this->handler->uploadPart(
			$this->request(query: ['uploadId' => 'uid-1', 'partNumber' => '10001']),
			$this->auth
		);
	}

	public function testUploadPartUnknownUploadIdThrows(): void {
		$this->uploadMapper->method('findByUploadId')
			->willThrowException(new DoesNotExistException(''));

		$this->expectException(NoSuchUploadException::class);
		$this->handler->uploadPart(
			$this->request(query: ['uploadId' => 'missing', 'partNumber' => '1']),
			$this->auth
		);
	}

	public function testUploadPartWrongUserThrows(): void {
		$this->uploadMapper->method('findByUploadId')
			->willReturn($this->makeUpload('uid-1', 'bob')); // belongs to 'bob', not 'alice'

		$this->expectException(NoSuchUploadException::class);
		$this->handler->uploadPart(
			$this->request(query: ['uploadId' => 'uid-1', 'partNumber' => '1']),
			$this->auth
		);
	}

	public function testUploadPartRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->uploadPart(
			$this->request(query: ['uploadId' => 'x', 'partNumber' => '1']),
			AuthContext::unauthenticated()
		);
	}

	// -------------------------------------------------------------------------
	// abortMultipartUpload
	// -------------------------------------------------------------------------

	public function testAbortMultipartUploadReturns204(): void {
		$this->uploadMapper->method('findByUploadId')->willReturn($this->makeUpload('uid-1'));
		$this->storageMapper->method('uploadDir')->willReturn('.s3_uploads/uid-1');

		$userFolder = $this->createMock(Folder::class);
		$dir = $this->createMock(Folder::class);
		$dir->method('delete');
		$userFolder->method('get')->willReturn($dir);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$this->partMapper->expects($this->once())->method('deleteByUploadId')->with('uid-1');
		$this->uploadMapper->expects($this->once())->method('deleteByUploadId')->with('uid-1');

		$response = $this->handler->abortMultipartUpload(
			$this->request('DELETE', query: ['uploadId' => 'uid-1']),
			$this->auth
		);

		$this->assertSame(204, $response->statusCode);
	}

	public function testAbortMultipartUploadUnknownIdThrows(): void {
		$this->uploadMapper->method('findByUploadId')
			->willThrowException(new DoesNotExistException(''));

		$this->expectException(NoSuchUploadException::class);
		$this->handler->abortMultipartUpload(
			$this->request('DELETE', query: ['uploadId' => 'missing']),
			$this->auth
		);
	}

	public function testAbortMultipartUploadRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->abortMultipartUpload(
			$this->request('DELETE', query: ['uploadId' => 'x']),
			AuthContext::unauthenticated()
		);
	}

	// -------------------------------------------------------------------------
	// listMultipartUploads
	// -------------------------------------------------------------------------

	public function testListMultipartUploadsReturnsXml(): void {
		$upload = $this->makeUpload('uid-1');
		$this->uploadMapper->method('findByUserAndBucket')->willReturn([$upload]);

		$response = $this->handler->listMultipartUploads(
			$this->request('GET', key: null),
			$this->auth
		);

		$this->assertSame(200, $response->statusCode);
		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame('my-bucket', (string)$xml->Bucket);
		$this->assertSame('big.bin', (string)$xml->Upload->Key);
		$this->assertSame('uid-1', (string)$xml->Upload->UploadId);
	}

	public function testListMultipartUploadsEmpty(): void {
		$this->uploadMapper->method('findByUserAndBucket')->willReturn([]);

		$response = $this->handler->listMultipartUploads(
			$this->request('GET', key: null),
			$this->auth
		);

		$xml = simplexml_load_string($response->xmlBody);
		$this->assertCount(0, $xml->Upload ?? []);
	}

	public function testListMultipartUploadsRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->listMultipartUploads(
			$this->request('GET', key: null),
			AuthContext::unauthenticated()
		);
	}

	// -------------------------------------------------------------------------
	// listParts
	// -------------------------------------------------------------------------

	public function testListPartsReturnsPartsXml(): void {
		$this->uploadMapper->method('findByUploadId')->willReturn($this->makeUpload('uid-1'));
		$this->partMapper->method('findByUploadId')->willReturn([
			$this->makePart(1, '"etag-1"'),
			$this->makePart(2, '"etag-2"'),
		]);

		$response = $this->handler->listParts(
			$this->request(query: ['uploadId' => 'uid-1']),
			$this->auth
		);

		$this->assertSame(200, $response->statusCode);
		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame('false', (string)$xml->IsTruncated);
		$this->assertCount(2, $xml->Part);
		$this->assertSame('1', (string)$xml->Part[0]->PartNumber);
		$this->assertSame('"etag-2"', (string)$xml->Part[1]->ETag);
	}

	public function testListPartsPartMarkerFiltersEarlierParts(): void {
		$this->uploadMapper->method('findByUploadId')->willReturn($this->makeUpload('uid-1'));
		$this->partMapper->method('findByUploadId')->willReturn([
			$this->makePart(1, '"etag-1"'),
			$this->makePart(2, '"etag-2"'),
			$this->makePart(3, '"etag-3"'),
		]);

		$response = $this->handler->listParts(
			$this->request(query: ['uploadId' => 'uid-1', 'part-number-marker' => '1']),
			$this->auth
		);

		$xml = simplexml_load_string($response->xmlBody);
		$this->assertCount(2, $xml->Part);
		$this->assertSame('2', (string)$xml->Part[0]->PartNumber);
	}

	public function testListPartsTruncatedWhenExceedsMaxParts(): void {
		$this->uploadMapper->method('findByUploadId')->willReturn($this->makeUpload('uid-1'));
		$parts = array_map(fn ($i) => $this->makePart($i, "\"etag-{$i}\""), range(1, 5));
		$this->partMapper->method('findByUploadId')->willReturn($parts);

		$response = $this->handler->listParts(
			$this->request(query: ['uploadId' => 'uid-1', 'max-parts' => '3']),
			$this->auth
		);

		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame('true', (string)$xml->IsTruncated);
		$this->assertCount(3, $xml->Part);
	}

	public function testListPartsRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->listParts(
			$this->request(query: ['uploadId' => 'x']),
			AuthContext::unauthenticated()
		);
	}

	// -------------------------------------------------------------------------
	// completeMultipartUpload — error cases
	// -------------------------------------------------------------------------

	public function testCompleteMultipartUploadOutOfOrderPartsThrows(): void {
		$this->uploadMapper->method('findByUploadId')->willReturn($this->makeUpload('uid-1'));
		$this->xmlReader->method('completeMultipartUpload')->willReturn([
			['part_number' => 2, 'etag' => '"etag-2"'],
			['part_number' => 1, 'etag' => '"etag-1"'], // out of order
		]);

		$this->expectException(S3Exception::class);
		$this->handler->completeMultipartUpload(
			$this->request('POST', query: ['uploadId' => 'uid-1']),
			$this->auth
		);
	}

	public function testCompleteMultipartUploadMissingPartThrows(): void {
		$this->uploadMapper->method('findByUploadId')->willReturn($this->makeUpload('uid-1'));
		$this->xmlReader->method('completeMultipartUpload')->willReturn([
			['part_number' => 1, 'etag' => '"etag-1"'],
		]);
		$this->partMapper->method('findByUploadId')->willReturn([]); // no stored parts

		$this->expectException(S3Exception::class);
		$this->handler->completeMultipartUpload(
			$this->request('POST', query: ['uploadId' => 'uid-1']),
			$this->auth
		);
	}

	public function testCompleteMultipartUploadEtagMismatchThrows(): void {
		$this->uploadMapper->method('findByUploadId')->willReturn($this->makeUpload('uid-1'));
		$this->xmlReader->method('completeMultipartUpload')->willReturn([
			['part_number' => 1, 'etag' => '"wrong-etag"'],
		]);
		$this->partMapper->method('findByUploadId')->willReturn([
			$this->makePart(1, '"correct-etag"'),
		]);

		$this->expectException(S3Exception::class);
		$this->handler->completeMultipartUpload(
			$this->request('POST', query: ['uploadId' => 'uid-1']),
			$this->auth
		);
	}

	public function testCompleteMultipartUploadRequiresAuth(): void {
		$this->expectException(AccessDeniedException::class);
		$this->handler->completeMultipartUpload(
			$this->request('POST', query: ['uploadId' => 'x']),
			AuthContext::unauthenticated()
		);
	}
}
