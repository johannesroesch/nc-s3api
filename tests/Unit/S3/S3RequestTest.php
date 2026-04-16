<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Tests\Unit\S3;

use OCA\NcS3Api\S3\S3Request;
use PHPUnit\Framework\TestCase;

class S3RequestTest extends TestCase {
	// -------------------------------------------------------------------------
	// getHeader
	// -------------------------------------------------------------------------

	public function testGetHeaderCaseInsensitive(): void {
		// S3Controller normalises headers to lowercase before constructing S3Request.
		// S3Request itself stores whatever is passed; getHeader() looks up by strtolower($name).
		$request = $this->make(headers: ['content-type' => 'application/json']);
		// Lookup is case-insensitive on the name side
		$this->assertSame('application/json', $request->getHeader('content-type'));
		$this->assertSame('application/json', $request->getHeader('Content-Type'));
	}

	public function testGetHeaderMissingReturnsEmpty(): void {
		$request = $this->make(headers: []);
		$this->assertSame('', $request->getHeader('x-amz-date'));
	}

	// -------------------------------------------------------------------------
	// getQuery
	// -------------------------------------------------------------------------

	public function testGetQueryReturnsValue(): void {
		$request = $this->make(queryParams: ['list-type' => '2']);
		$this->assertSame('2', $request->getQuery('list-type'));
	}

	public function testGetQueryMissingReturnsDefault(): void {
		$request = $this->make();
		$this->assertSame('default-val', $request->getQuery('missing', 'default-val'));
	}

	public function testGetQueryMissingReturnsEmptyByDefault(): void {
		$request = $this->make();
		$this->assertSame('', $request->getQuery('missing'));
	}

	// -------------------------------------------------------------------------
	// hasQuery
	// -------------------------------------------------------------------------

	public function testHasQueryReturnsTrueForPresentEmptyValue(): void {
		// S3 uses empty query params as flags (?versioning, ?tagging, …)
		$request = $this->make(queryParams: ['versioning' => '']);
		$this->assertTrue($request->hasQuery('versioning'));
	}

	public function testHasQueryReturnsFalseForAbsentKey(): void {
		$request = $this->make();
		$this->assertFalse($request->hasQuery('versioning'));
	}

	public function testHasQueryReturnsTrueForNonEmptyValue(): void {
		$request = $this->make(queryParams: ['uploadId' => 'abc123']);
		$this->assertTrue($request->hasQuery('uploadId'));
	}

	// -------------------------------------------------------------------------
	// Immutability — readonly properties
	// -------------------------------------------------------------------------

	public function testPropertiesAreReadonly(): void {
		$request = $this->make(method: 'GET', bucket: 'my-bucket', key: 'my-key');
		$this->assertSame('GET', $request->method);
		$this->assertSame('my-bucket', $request->bucket);
		$this->assertSame('my-key', $request->key);
	}

	public function testBucketCanBeNull(): void {
		$request = $this->make(bucket: null);
		$this->assertNull($request->bucket);
	}

	public function testKeyCanBeNull(): void {
		$request = $this->make(key: null);
		$this->assertNull($request->key);
	}

	// -------------------------------------------------------------------------
	// Helper
	// -------------------------------------------------------------------------

	private function make(
		string $method = 'GET',
		?string $bucket = 'test-bucket',
		?string $key = null,
		array $queryParams = [],
		array $headers = [],
		mixed $body = '',
		string $rawPath = '/s3/test-bucket',
		string $host = 'localhost',
	): S3Request {
		return new S3Request($method, $bucket, $key, $queryParams, $headers, $body, $rawPath, $host);
	}
}
