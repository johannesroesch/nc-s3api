<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Tests\Unit\Storage;

use OCA\NcS3Api\Storage\StorageMapper;
use PHPUnit\Framework\TestCase;

class StorageMapperTest extends TestCase {
	private StorageMapper $mapper;

	protected function setUp(): void {
		$this->mapper = new StorageMapper();
	}

	// ─── bucketPath ───────────────────────────────────────────────────────────

	public function testBucketPath(): void {
		$this->assertSame('s3/mybucket', $this->mapper->bucketPath('mybucket'));
	}

	public function testBucketPathWithHyphen(): void {
		$this->assertSame('s3/my-bucket', $this->mapper->bucketPath('my-bucket'));
	}

	// ─── objectPath ───────────────────────────────────────────────────────────

	public function testObjectPathSimple(): void {
		$this->assertSame('s3/mybucket/file.txt', $this->mapper->objectPath('mybucket', 'file.txt'));
	}

	public function testObjectPathWithSubdirectory(): void {
		$this->assertSame('s3/mybucket/dir/sub/file.txt', $this->mapper->objectPath('mybucket', 'dir/sub/file.txt'));
	}

	// ─── partPath ─────────────────────────────────────────────────────────────

	public function testPartPath(): void {
		$this->assertSame('s3/.uploads/upload-123/part-5', $this->mapper->partPath('upload-123', 5));
	}

	public function testPartPathPartNumberOne(): void {
		$this->assertSame('s3/.uploads/abc/part-1', $this->mapper->partPath('abc', 1));
	}

	// ─── uploadDir ────────────────────────────────────────────────────────────

	public function testUploadDir(): void {
		$this->assertSame('s3/.uploads/my-upload-id', $this->mapper->uploadDir('my-upload-id'));
	}

	// ─── nodeToKey ────────────────────────────────────────────────────────────

	public function testNodeToKeySimpleFile(): void {
		$this->assertSame('file.txt', $this->mapper->nodeToKey('s3/mybucket/file.txt'));
	}

	public function testNodeToKeyNestedFile(): void {
		$this->assertSame('dir/sub/file.txt', $this->mapper->nodeToKey('s3/mybucket/dir/sub/file.txt'));
	}

	public function testNodeToKeyBucketFolderReturnsNull(): void {
		// "s3/mybucket" has no slash after the bucket → it IS the bucket, not an object
		$this->assertNull($this->mapper->nodeToKey('s3/mybucket'));
	}

	public function testNodeToKeyWrongPrefixReturnsNull(): void {
		$this->assertNull($this->mapper->nodeToKey('files/mybucket/file.txt'));
	}

	public function testNodeToKeyRootReturnsNull(): void {
		$this->assertNull($this->mapper->nodeToKey('other/path'));
	}

	// ─── nodeToBucket ─────────────────────────────────────────────────────────

	public function testNodeToBucketFromObjectPath(): void {
		$this->assertSame('mybucket', $this->mapper->nodeToBucket('s3/mybucket/file.txt'));
	}

	public function testNodeToBucketFromBucketPath(): void {
		$this->assertSame('mybucket', $this->mapper->nodeToBucket('s3/mybucket'));
	}

	public function testNodeToBucketNestedPath(): void {
		$this->assertSame('mybucket', $this->mapper->nodeToBucket('s3/mybucket/a/b/c.txt'));
	}

	public function testNodeToBucketWrongPrefixReturnsNull(): void {
		$this->assertNull($this->mapper->nodeToBucket('files/bucket/key'));
	}

	// ─── constants ────────────────────────────────────────────────────────────

	public function testBaseDirectoryConstant(): void {
		$this->assertSame('s3', StorageMapper::BASE_DIR);
	}

	public function testUploadsDirectoryConstant(): void {
		$this->assertSame('s3/.uploads', StorageMapper::UPLOADS_DIR);
	}

	// ─── round-trip consistency ───────────────────────────────────────────────

	public function testObjectPathRoundTrip(): void {
		$bucket = 'my-bucket';
		$key = 'photos/holiday/img.jpg';
		$path = $this->mapper->objectPath($bucket, $key);

		$this->assertSame($bucket, $this->mapper->nodeToBucket($path));
		$this->assertSame($key, $this->mapper->nodeToKey($path));
	}
}
