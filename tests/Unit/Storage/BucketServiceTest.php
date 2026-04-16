<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Tests\Unit\Storage;

use OCA\NcS3Api\Exception\BucketAlreadyExistsException;
use OCA\NcS3Api\Exception\BucketNotEmptyException;
use OCA\NcS3Api\Exception\NoSuchBucketException;
use OCA\NcS3Api\Exception\S3Exception;
use OCA\NcS3Api\Storage\BucketService;
use OCA\NcS3Api\Storage\StorageMapper;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BucketServiceTest extends TestCase {
	private IRootFolder&MockObject $rootFolder;
	private BucketService $service;

	// The real StorageMapper is used — it is pure path logic with no dependencies.
	private StorageMapper $mapper;

	protected function setUp(): void {
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->mapper = new StorageMapper();
		$this->service = new BucketService($this->rootFolder, $this->mapper);
	}

	// ─── helpers ──────────────────────────────────────────────────────────────

	private function mockFolder(string $name, int $mtime = 1700000000): Folder&MockObject {
		$f = $this->createMock(Folder::class);
		$f->method('getName')->willReturn($name);
		$f->method('getMtime')->willReturn($mtime);
		return $f;
	}

	/**
	 * Set up rootFolder->getUserFolder() to return a Folder mock
	 * that itself delegates to a "base dir" Folder mock.
	 * Returns the base dir mock for further configuration.
	 */
	private function setupUserFolder(string $userId): Folder&MockObject {
		$userFolder = $this->createMock(Folder::class);
		$this->rootFolder->method('getUserFolder')->with($userId)->willReturn($userFolder);
		return $userFolder;
	}

	// ─── listBuckets ─────────────────────────────────────────────────────────

	public function testListBucketsReturnsSortedBuckets(): void {
		$userFolder = $this->setupUserFolder('alice');

		$baseDir = $this->createMock(Folder::class);
		$bucketC = $this->mockFolder('charlie');
		$bucketA = $this->mockFolder('alpha');
		$bucketB = $this->mockFolder('bravo');

		// getUserFolder->get('s3') returns baseDir
		$userFolder->method('get')->with('s3')->willReturn($baseDir);
		$baseDir->method('getDirectoryListing')->willReturn([$bucketC, $bucketA, $bucketB]);

		$result = $this->service->listBuckets('alice');

		$this->assertCount(3, $result);
		$this->assertSame('alpha', $result[0]['name']);
		$this->assertSame('bravo', $result[1]['name']);
		$this->assertSame('charlie', $result[2]['name']);
	}

	public function testListBucketsSkipsUploadsDir(): void {
		$userFolder = $this->setupUserFolder('alice');
		$baseDir = $this->createMock(Folder::class);
		$uploads = $this->mockFolder('.uploads');
		$bucket = $this->mockFolder('real-bucket');

		$userFolder->method('get')->with('s3')->willReturn($baseDir);
		$baseDir->method('getDirectoryListing')->willReturn([$uploads, $bucket]);

		$result = $this->service->listBuckets('alice');

		$this->assertCount(1, $result);
		$this->assertSame('real-bucket', $result[0]['name']);
	}

	public function testListBucketsCreatesBaseDirIfMissing(): void {
		$userFolder = $this->setupUserFolder('alice');
		$baseDir = $this->createMock(Folder::class);

		// First get throws NotFoundException → newFolder called
		$userFolder->method('get')->with('s3')->willThrowException(new NotFoundException());
		$userFolder->expects($this->once())->method('newFolder')->with('s3')->willReturn($baseDir);
		$baseDir->method('getDirectoryListing')->willReturn([]);

		$result = $this->service->listBuckets('alice');
		$this->assertSame([], $result);
	}

	public function testListBucketsReturnsEmptyWhenNoBuckets(): void {
		$userFolder = $this->setupUserFolder('alice');
		$baseDir = $this->createMock(Folder::class);
		$userFolder->method('get')->with('s3')->willReturn($baseDir);
		$baseDir->method('getDirectoryListing')->willReturn([]);

		$this->assertSame([], $this->service->listBuckets('alice'));
	}

	// ─── createBucket ────────────────────────────────────────────────────────

	public function testCreateBucketSucceeds(): void {
		$userFolder = $this->setupUserFolder('alice');
		$baseDir = $this->createMock(Folder::class);
		$userFolder->method('get')->with('s3')->willReturn($baseDir);
		$baseDir->method('nodeExists')->with('my-bucket')->willReturn(false);
		$baseDir->expects($this->once())->method('newFolder')->with('my-bucket');

		$this->service->createBucket('alice', 'my-bucket');
	}

	public function testCreateBucketThrowsIfAlreadyExists(): void {
		$userFolder = $this->setupUserFolder('alice');
		$baseDir = $this->createMock(Folder::class);
		$userFolder->method('get')->with('s3')->willReturn($baseDir);
		$baseDir->method('nodeExists')->with('existing')->willReturn(true);

		$this->expectException(BucketAlreadyExistsException::class);
		$this->service->createBucket('alice', 'existing');
	}

	/** @dataProvider invalidBucketNames */
	public function testCreateBucketInvalidNameThrows(string $name): void {
		$this->expectException(S3Exception::class);
		// getUserFolder should never be called — validation happens first
		$this->rootFolder->expects($this->never())->method('getUserFolder');
		$this->service->createBucket('alice', $name);
	}

	public static function invalidBucketNames(): array {
		return [
			'too short'         => ['ab'],
			'uppercase'         => ['MyBucket'],
			'starts with dot'   => ['.bucket'],
			'ends with hyphen'  => ['bucket-'],
			'double dots'       => ['my..bucket'],
			'dot-hyphen'        => ['my.-bucket'],
			'hyphen-dot'        => ['my-.bucket'],
		];
	}

	public function testCreateBucketValidNameSucceeds(): void {
		$userFolder = $this->setupUserFolder('alice');
		$baseDir = $this->createMock(Folder::class);
		$userFolder->method('get')->with('s3')->willReturn($baseDir);
		$baseDir->method('nodeExists')->willReturn(false);
		$baseDir->method('newFolder')->willReturn($this->createMock(Folder::class));

		// Should not throw
		$this->service->createBucket('alice', 'valid-bucket-name');
		$this->addToAssertionCount(1);
	}

	// ─── deleteBucket ────────────────────────────────────────────────────────

	public function testDeleteBucketSucceeds(): void {
		$userFolder = $this->setupUserFolder('alice');
		$bucketFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->with('s3/my-bucket')->willReturn($bucketFolder);
		$bucketFolder->method('getDirectoryListing')->willReturn([]);
		$bucketFolder->expects($this->once())->method('delete');

		$this->service->deleteBucket('alice', 'my-bucket');
	}

	public function testDeleteBucketThrowsWhenNotEmpty(): void {
		$userFolder = $this->setupUserFolder('alice');
		$bucketFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->with('s3/my-bucket')->willReturn($bucketFolder);

		$child = $this->createMock(\OCP\Files\Node::class);
		$bucketFolder->method('getDirectoryListing')->willReturn([$child]);

		$this->expectException(BucketNotEmptyException::class);
		$this->service->deleteBucket('alice', 'my-bucket');
	}

	public function testDeleteBucketThrowsWhenNotFound(): void {
		$userFolder = $this->setupUserFolder('alice');
		$userFolder->method('get')->with('s3/ghost-bucket')->willThrowException(new NotFoundException());

		$this->expectException(NoSuchBucketException::class);
		$this->service->deleteBucket('alice', 'ghost-bucket');
	}

	// ─── headBucket ──────────────────────────────────────────────────────────

	public function testHeadBucketReturnsMetadata(): void {
		$userFolder = $this->setupUserFolder('alice');
		$bucketFolder = $this->mockFolder('test-bucket', 1700001234);
		$userFolder->method('get')->with('s3/test-bucket')->willReturn($bucketFolder);

		$result = $this->service->headBucket('alice', 'test-bucket');

		$this->assertSame('test-bucket', $result['name']);
		$this->assertSame(1700001234, $result['mtime']);
	}

	public function testHeadBucketThrowsWhenNotFound(): void {
		$userFolder = $this->setupUserFolder('alice');
		$userFolder->method('get')->willThrowException(new NotFoundException());

		$this->expectException(NoSuchBucketException::class);
		$this->service->headBucket('alice', 'no-bucket');
	}

	// ─── getBucketFolder ─────────────────────────────────────────────────────

	public function testGetBucketFolderReturnsFolder(): void {
		$userFolder = $this->setupUserFolder('alice');
		$bucketFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->with('s3/my-bucket')->willReturn($bucketFolder);

		$result = $this->service->getBucketFolder('alice', 'my-bucket');
		$this->assertSame($bucketFolder, $result);
	}

	public function testGetBucketFolderThrowsWhenPathIsNotFolder(): void {
		$userFolder = $this->setupUserFolder('alice');
		$file = $this->createMock(\OCP\Files\File::class);
		$userFolder->method('get')->with('s3/not-a-folder')->willReturn($file);

		$this->expectException(NoSuchBucketException::class);
		$this->service->getBucketFolder('alice', 'not-a-folder');
	}

	// ─── bucketExists ────────────────────────────────────────────────────────

	public function testBucketExistsTrueWhenFound(): void {
		$userFolder = $this->setupUserFolder('alice');
		$bucketFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->willReturn($bucketFolder);

		$this->assertTrue($this->service->bucketExists('alice', 'my-bucket'));
	}

	public function testBucketExistsFalseWhenNotFound(): void {
		$userFolder = $this->setupUserFolder('alice');
		$userFolder->method('get')->willThrowException(new NotFoundException());

		$this->assertFalse($this->service->bucketExists('alice', 'ghost'));
	}
}
