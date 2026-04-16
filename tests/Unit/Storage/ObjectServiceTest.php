<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Tests\Unit\Storage;

use OCA\NcS3Api\Exception\AccessDeniedException;
use OCA\NcS3Api\Exception\NoSuchBucketException;
use OCA\NcS3Api\Exception\NoSuchKeyException;
use OCA\NcS3Api\Exception\S3Exception;
use OCA\NcS3Api\Storage\BucketService;
use OCA\NcS3Api\Storage\ObjectService;
use OCA\NcS3Api\Storage\StorageMapper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ObjectServiceTest extends TestCase {
	private IRootFolder&MockObject $rootFolder;
	private BucketService&MockObject $bucketService;
	private StorageMapper $mapper;
	private ObjectService $service;

	protected function setUp(): void {
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->bucketService = $this->createMock(BucketService::class);
		$this->mapper = new StorageMapper();
		$this->service = new ObjectService($this->rootFolder, $this->bucketService, $this->mapper);
	}

	// ─── helpers ──────────────────────────────────────────────────────────────

	/** Create a File mock with configurable properties. */
	private function mockFile(
		string $name = 'file.txt',
		int $size = 100,
		int $mtime = 1700000000,
		string $mime = 'text/plain',
		string $md5 = 'abc123',
		string $path = '/alice/s3/bucket/file.txt',
	): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getSize')->willReturn($size);
		$file->method('getMtime')->willReturn($mtime);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('hash')->with('md5')->willReturn($md5);
		$file->method('getPath')->willReturn($path);
		return $file;
	}

	/**
	 * Set up rootFolder->getUserFolder() → userFolder.
	 * userFolder->get(path) → node.
	 */
	private function setupUserFolderGet(string $userId, string $path, mixed $returnValue): Folder&MockObject {
		$userFolder = $this->createMock(Folder::class);
		$this->rootFolder->method('getUserFolder')->with($userId)->willReturn($userFolder);
		$userFolder->method('get')->with($path)->willReturn($returnValue);
		return $userFolder;
	}

	// ─── getObjectMeta ────────────────────────────────────────────────────────

	public function testGetObjectMetaReturnsCorrectFields(): void {
		$file = $this->mockFile('file.txt', 512, 1700000000, 'text/plain', 'deadbeef');
		$bucketFolder = $this->createMock(Folder::class);
		$this->bucketService->method('getBucketFolder')->willReturn($bucketFolder);
		$this->setupUserFolderGet('alice', 's3/bucket/file.txt', $file);

		$meta = $this->service->getObjectMeta('alice', 'bucket', 'file.txt');

		$this->assertSame($file, $meta['file']);
		$this->assertSame('"deadbeef"', $meta['etag']);
		$this->assertSame('text/plain', $meta['content_type']);
		$this->assertSame(512, $meta['size']);
		$this->assertStringContainsString('2023-', $meta['last_modified']); // ISO 8601
	}

	public function testGetObjectMetaThrowsWhenKeyIsFolder(): void {
		$folder = $this->createMock(Folder::class);
		$bucketFolder = $this->createMock(Folder::class);
		$this->bucketService->method('getBucketFolder')->willReturn($bucketFolder);
		$this->setupUserFolderGet('alice', 's3/bucket/subdir', $folder);

		$this->expectException(NoSuchKeyException::class);
		$this->service->getObjectMeta('alice', 'bucket', 'subdir');
	}

	public function testGetObjectMetaThrowsWhenBucketMissing(): void {
		$this->bucketService->method('getBucketFolder')
			->willThrowException(new NoSuchBucketException('bucket'));

		$this->expectException(NoSuchBucketException::class);
		$this->service->getObjectMeta('alice', 'bucket', 'file.txt');
	}

	public function testGetObjectMetaThrowsWhenFileMissing(): void {
		$bucketFolder = $this->createMock(Folder::class);
		$this->bucketService->method('getBucketFolder')->willReturn($bucketFolder);
		$userFolder = $this->createMock(Folder::class);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);
		$userFolder->method('get')->willThrowException(new NotFoundException());

		$this->expectException(NoSuchKeyException::class);
		$this->service->getObjectMeta('alice', 'bucket', 'missing.txt');
	}

	// ─── objectExists ─────────────────────────────────────────────────────────

	public function testObjectExistsTrueWhenFileFound(): void {
		$file = $this->mockFile();
		$bucketFolder = $this->createMock(Folder::class);
		$this->bucketService->method('getBucketFolder')->willReturn($bucketFolder);
		$this->setupUserFolderGet('alice', 's3/bucket/file.txt', $file);

		$this->assertTrue($this->service->objectExists('alice', 'bucket', 'file.txt'));
	}

	public function testObjectExistsFalseWhenMissing(): void {
		$bucketFolder = $this->createMock(Folder::class);
		$this->bucketService->method('getBucketFolder')->willReturn($bucketFolder);
		$userFolder = $this->createMock(Folder::class);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);
		$userFolder->method('get')->willThrowException(new NotFoundException());

		$this->assertFalse($this->service->objectExists('alice', 'bucket', 'ghost.txt'));
	}

	public function testObjectExistsFalseWhenBucketMissing(): void {
		$this->bucketService->method('getBucketFolder')
			->willThrowException(new NoSuchBucketException('bucket'));

		$this->assertFalse($this->service->objectExists('alice', 'bucket', 'file.txt'));
	}

	// ─── openReadStream ───────────────────────────────────────────────────────

	public function testOpenReadStreamReturnsResource(): void {
		$handle = fopen('php://memory', 'r');
		$file = $this->mockFile();
		$file->method('fopen')->with('r')->willReturn($handle);

		$bucketFolder = $this->createMock(Folder::class);
		$this->bucketService->method('getBucketFolder')->willReturn($bucketFolder);
		$this->setupUserFolderGet('alice', 's3/bucket/file.txt', $file);

		$result = $this->service->openReadStream('alice', 'bucket', 'file.txt');
		$this->assertIsResource($result);
		fclose($result);
	}

	public function testOpenReadStreamThrowsWhenFopenFails(): void {
		$file = $this->mockFile();
		$file->method('fopen')->willReturn(false);

		$bucketFolder = $this->createMock(Folder::class);
		$this->bucketService->method('getBucketFolder')->willReturn($bucketFolder);
		$this->setupUserFolderGet('alice', 's3/bucket/file.txt', $file);

		$this->expectException(S3Exception::class);
		$this->service->openReadStream('alice', 'bucket', 'file.txt');
	}

	// ─── openRangeStream ──────────────────────────────────────────────────────

	public function testOpenRangeStreamReturnsCorrectSlice(): void {
		$content = str_repeat('X', 200);
		$handle = fopen('php://memory', 'r+b');
		fwrite($handle, $content);
		rewind($handle);

		$file = $this->mockFile(size: 200);
		$file->method('fopen')->willReturn($handle);

		$bucketFolder = $this->createMock(Folder::class);
		$this->bucketService->method('getBucketFolder')->willReturn($bucketFolder);
		$this->setupUserFolderGet('alice', 's3/bucket/file.txt', $file);

		[$stream, $length, $contentRange] = $this->service->openRangeStream('alice', 'bucket', 'file.txt', 'bytes=10-49');

		$this->assertSame(40, $length);
		$this->assertSame('bytes 10-49/200', $contentRange);
		$this->assertSame(40, strlen(stream_get_contents($stream)));
		fclose($stream);
	}

	public function testOpenRangeStreamSuffixRange(): void {
		$content = 'ABCDEFGHIJ'; // 10 bytes
		$handle = fopen('php://memory', 'r+b');
		fwrite($handle, $content);
		rewind($handle);

		$file = $this->mockFile(size: 10);
		$file->method('fopen')->willReturn($handle);

		$bucketFolder = $this->createMock(Folder::class);
		$this->bucketService->method('getBucketFolder')->willReturn($bucketFolder);
		$this->setupUserFolderGet('alice', 's3/bucket/file.txt', $file);

		[$stream, $length, $contentRange] = $this->service->openRangeStream('alice', 'bucket', 'file.txt', 'bytes=-5');

		$this->assertSame(5, $length);
		$this->assertSame('bytes 5-9/10', $contentRange);
		fclose($stream);
	}

	public function testOpenRangeStreamInvalidRangeThrows(): void {
		$file = $this->mockFile(size: 100);
		$bucketFolder = $this->createMock(Folder::class);
		$this->bucketService->method('getBucketFolder')->willReturn($bucketFolder);
		$this->setupUserFolderGet('alice', 's3/bucket/file.txt', $file);

		$this->expectException(S3Exception::class);
		$this->service->openRangeStream('alice', 'bucket', 'file.txt', 'bytes=invalid');
	}

	public function testOpenRangeStreamOutOfBoundsThrows(): void {
		$file = $this->mockFile(size: 50);
		$bucketFolder = $this->createMock(Folder::class);
		$this->bucketService->method('getBucketFolder')->willReturn($bucketFolder);
		$this->setupUserFolderGet('alice', 's3/bucket/file.txt', $file);

		$this->expectException(S3Exception::class);
		$this->service->openRangeStream('alice', 'bucket', 'file.txt', 'bytes=0-100');
	}

	// ─── putObject ────────────────────────────────────────────────────────────

	public function testPutObjectCreatesNewFile(): void {
		$bucketFolder = $this->createMock(Folder::class);
		$this->bucketService->method('getBucketFolder')->willReturn($bucketFolder);

		$parentFolder = $this->createMock(Folder::class);
		$newFile = $this->mockFile('file.txt');
		$handle = fopen('php://memory', 'r+b');
		$newFile->method('fopen')->willReturn($handle);
		$newFile->method('hash')->willReturn('abc');

		$userFolder = $this->createMock(Folder::class);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		// ensureParentDir: walks parts of path; for 's3/bucket/file.txt' dirname='s3/bucket'
		// We stub get('s3') → a folder, then get('bucket') inside it
		$s3Dir = $this->createMock(Folder::class);
		$bucketDir = $this->createMock(Folder::class);
		$userFolder->method('get')->willReturnMap([
			['s3', $s3Dir],
			['s3/bucket', $bucketDir],
		]);
		$userFolder->method('nodeExists')->willReturn(true);
		$s3Dir->method('nodeExists')->willReturn(true);
		$s3Dir->method('get')->with('bucket')->willReturn($bucketDir);
		$bucketDir->method('nodeExists')->with('file.txt')->willReturn(false);
		$bucketDir->method('newFile')->with('file.txt')->willReturn($newFile);
		$bucketDir->method('getPath')->willReturn('/alice/s3/bucket');

		$etag = $this->service->putObject('alice', 'bucket', 'file.txt', 'hello world');
		$this->assertStringContainsString('"', $etag);
		fclose($handle);
	}

	public function testPutObjectOverwritesExistingFile(): void {
		$bucketFolder = $this->createMock(Folder::class);
		$this->bucketService->method('getBucketFolder')->willReturn($bucketFolder);

		$existingFile = $this->mockFile('file.txt');
		$handle = fopen('php://memory', 'r+b');
		$existingFile->method('fopen')->willReturn($handle);

		$userFolder = $this->createMock(Folder::class);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$s3Dir = $this->createMock(Folder::class);
		$bucketDir = $this->createMock(Folder::class);
		$userFolder->method('get')->willReturnMap([
			['s3', $s3Dir],
			['s3/bucket', $bucketDir],
		]);
		$s3Dir->method('nodeExists')->willReturn(true);
		$s3Dir->method('get')->willReturn($bucketDir);
		$bucketDir->method('nodeExists')->with('file.txt')->willReturn(true);
		$bucketDir->method('get')->with('file.txt')->willReturn($existingFile);
		$bucketDir->method('getPath')->willReturn('/alice/s3/bucket');

		$this->service->putObject('alice', 'bucket', 'file.txt', 'updated content');
		// No exception = success
		$this->addToAssertionCount(1);
		fclose($handle);
	}

	public function testPutObjectThrowsWhenBucketMissing(): void {
		$this->bucketService->method('getBucketFolder')
			->willThrowException(new NoSuchBucketException('ghost'));

		$this->expectException(NoSuchBucketException::class);
		$this->service->putObject('alice', 'ghost', 'file.txt', '');
	}

	public function testPutObjectThrowsAccessDeniedOnNotPermitted(): void {
		$bucketFolder = $this->createMock(Folder::class);
		$this->bucketService->method('getBucketFolder')->willReturn($bucketFolder);

		$userFolder = $this->createMock(Folder::class);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$s3Dir = $this->createMock(Folder::class);
		$bucketDir = $this->createMock(Folder::class);
		$userFolder->method('get')->willReturnMap([
			['s3', $s3Dir],
			['s3/bucket', $bucketDir],
		]);
		$s3Dir->method('nodeExists')->willReturn(true);
		$s3Dir->method('get')->willReturn($bucketDir);
		$bucketDir->method('nodeExists')->with('file.txt')->willReturn(false);
		$bucketDir->method('newFile')->willThrowException(new NotPermittedException());
		$bucketDir->method('getPath')->willReturn('/alice/s3/bucket');

		$this->expectException(AccessDeniedException::class);
		$this->service->putObject('alice', 'bucket', 'file.txt', 'data');
	}

	// ─── deleteObject ─────────────────────────────────────────────────────────

	public function testDeleteObjectSucceeds(): void {
		$file = $this->mockFile();
		$file->expects($this->once())->method('delete');
		$bucketFolder = $this->createMock(Folder::class);
		$this->bucketService->method('getBucketFolder')->willReturn($bucketFolder);
		$this->setupUserFolderGet('alice', 's3/bucket/file.txt', $file);

		$this->service->deleteObject('alice', 'bucket', 'file.txt');
	}

	public function testDeleteObjectThrowsAccessDeniedOnNotPermitted(): void {
		$file = $this->mockFile();
		$file->method('delete')->willThrowException(new NotPermittedException());
		$bucketFolder = $this->createMock(Folder::class);
		$this->bucketService->method('getBucketFolder')->willReturn($bucketFolder);
		$this->setupUserFolderGet('alice', 's3/bucket/file.txt', $file);

		$this->expectException(AccessDeniedException::class);
		$this->service->deleteObject('alice', 'bucket', 'file.txt');
	}

	public function testDeleteObjectThrowsWhenKeyMissing(): void {
		$bucketFolder = $this->createMock(Folder::class);
		$this->bucketService->method('getBucketFolder')->willReturn($bucketFolder);
		$userFolder = $this->createMock(Folder::class);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);
		$userFolder->method('get')->willThrowException(new NotFoundException());

		$this->expectException(NoSuchKeyException::class);
		$this->service->deleteObject('alice', 'bucket', 'missing.txt');
	}

	// ─── listObjects ──────────────────────────────────────────────────────────

	public function testListObjectsReturnsAllFiles(): void {
		$file1 = $this->mockFile('a.txt', 10, 1700000001);
		$file2 = $this->mockFile('b.txt', 20, 1700000002);
		$bucketFolder = $this->createMock(Folder::class);
		$bucketFolder->method('getDirectoryListing')->willReturn([$file1, $file2]);
		$this->bucketService->method('getBucketFolder')->willReturn($bucketFolder);

		$results = $this->service->listObjects('alice', 'bucket');

		$this->assertCount(2, $results);
		$this->assertSame('a.txt', $results[0]['key']);
		$this->assertSame('b.txt', $results[1]['key']);
		$this->assertSame(10, $results[0]['size']);
		$this->assertSame('STANDARD', $results[0]['storage_class']);
	}

	public function testListObjectsWithPrefix(): void {
		$file1 = $this->mockFile('photos/img1.jpg', 100, 1700000001);
		$file2 = $this->mockFile('docs/readme.txt', 200, 1700000002);
		$folder = $this->createMock(Folder::class);
		$folder->method('getName')->willReturn('photos');

		// bucket listing returns a subfolder + a file at root
		$bucketFolder = $this->createMock(Folder::class);
		$photosFolder = $this->createMock(Folder::class);
		$photosFolder->method('getName')->willReturn('photos');
		$photosFolder->method('getDirectoryListing')->willReturn([$file1]);
		$docsFolder = $this->createMock(Folder::class);
		$docsFolder->method('getName')->willReturn('docs');
		$docsFolder->method('getDirectoryListing')->willReturn([$file2]);

		$bucketFolder->method('getDirectoryListing')->willReturn([$photosFolder, $docsFolder]);
		$this->bucketService->method('getBucketFolder')->willReturn($bucketFolder);

		$results = $this->service->listObjects('alice', 'bucket', 'photos/');

		// Only photos/img1.jpg matches the prefix
		$this->assertCount(1, $results);
		$this->assertSame('photos/img1.jpg', $results[0]['key']);
	}

	public function testListObjectsEmptyBucket(): void {
		$bucketFolder = $this->createMock(Folder::class);
		$bucketFolder->method('getDirectoryListing')->willReturn([]);
		$this->bucketService->method('getBucketFolder')->willReturn($bucketFolder);

		$this->assertSame([], $this->service->listObjects('alice', 'bucket'));
	}

	public function testListObjectsThrowsWhenBucketMissing(): void {
		$this->bucketService->method('getBucketFolder')
			->willThrowException(new NoSuchBucketException('ghost'));

		$this->expectException(NoSuchBucketException::class);
		$this->service->listObjects('alice', 'ghost');
	}
}
