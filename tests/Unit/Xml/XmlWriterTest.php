<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Tests\Unit\Xml;

use OCA\NcS3Api\Xml\XmlWriter;
use PHPUnit\Framework\TestCase;

class XmlWriterTest extends TestCase {
	private XmlWriter $w;

	protected function setUp(): void {
		$this->w = new XmlWriter();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function xml(string $raw): \SimpleXMLElement {
		$el = simplexml_load_string($raw);
		$this->assertNotFalse($el, 'Response is not valid XML');
		return $el;
	}

	private function ns(\SimpleXMLElement $root): \SimpleXMLElement {
		$root->registerXPathNamespace('s3', 'http://s3.amazonaws.com/doc/2006-03-01/');
		return $root;
	}

	// -------------------------------------------------------------------------
	// listBuckets
	// -------------------------------------------------------------------------

	public function testListBucketsContainsBucketNames(): void {
		$out = $this->w->listBuckets(
			['id' => 'alice', 'display_name' => 'Alice'],
			[
				['name' => 'photos',   'creation_date' => '2024-01-01T00:00:00.000Z'],
				['name' => 'backups',  'creation_date' => '2024-02-01T00:00:00.000Z'],
			]
		);

		$xml = $this->xml($out);
		$names = $xml->Buckets->Bucket;
		$this->assertCount(2, $names);
		$this->assertSame('photos', (string)$names[0]->Name);
		$this->assertSame('backups', (string)$names[1]->Name);
	}

	public function testListBucketsOwner(): void {
		$out = $this->w->listBuckets(
			['id' => 'bob', 'display_name' => 'Bob'],
			[]
		);
		$xml = $this->xml($out);
		$this->assertSame('bob', (string)$xml->Owner->ID);
		$this->assertSame('Bob', (string)$xml->Owner->DisplayName);
	}

	public function testListBucketsEmpty(): void {
		$out = $this->w->listBuckets(['id' => 'x', 'display_name' => 'X'], []);
		$xml = $this->xml($out);
		$this->assertCount(0, $xml->Buckets->Bucket ?? []);
	}

	// -------------------------------------------------------------------------
	// listObjects
	// -------------------------------------------------------------------------

	public function testListObjectsBasicFields(): void {
		$ctx = [
			'name' => 'my-bucket',
			'prefix' => '',
			'delimiter' => '',
			'max_keys' => 1000,
			'is_truncated' => false,
			'marker' => '',
			'next_marker' => '',
		];
		$objects = [[
			'key' => 'hello.txt',
			'last_modified' => '2024-01-01T00:00:00.000Z',
			'etag' => '"abc123"',
			'size' => 42,
			'storage_class' => 'STANDARD',
		]];

		$out = $this->w->listObjects($ctx, $objects, []);
		$xml = $this->xml($out);

		$this->assertSame('my-bucket', (string)$xml->Name);
		$this->assertSame('false', (string)$xml->IsTruncated);
		$this->assertSame('hello.txt', (string)$xml->Contents->Key);
		$this->assertSame('42', (string)$xml->Contents->Size);
		$this->assertSame('"abc123"', (string)$xml->Contents->ETag);
	}

	public function testListObjectsTruncatedWithNextMarker(): void {
		$ctx = [
			'name' => 'b', 'prefix' => '', 'delimiter' => '',
			'max_keys' => 1, 'is_truncated' => true,
			'marker' => '', 'next_marker' => 'file-b.txt',
		];
		$out = $this->w->listObjects($ctx, [
			['key' => 'file-a.txt', 'last_modified' => '2024-01-01T00:00:00.000Z',
				'etag' => '"a"', 'size' => 1, 'storage_class' => 'STANDARD'],
		], []);

		$xml = $this->xml($out);
		$this->assertSame('true', (string)$xml->IsTruncated);
		$this->assertSame('file-b.txt', (string)$xml->NextMarker);
	}

	public function testListObjectsCommonPrefixes(): void {
		$ctx = [
			'name' => 'b', 'prefix' => 'img/', 'delimiter' => '/',
			'max_keys' => 1000, 'is_truncated' => false,
			'marker' => '', 'next_marker' => '',
		];
		$out = $this->w->listObjects($ctx, [], ['img/2024/', 'img/2025/']);
		$xml = $this->xml($out);

		$prefixes = $xml->CommonPrefixes;
		$this->assertCount(2, $prefixes);
		$this->assertSame('img/2024/', (string)$prefixes[0]->Prefix);
	}

	// -------------------------------------------------------------------------
	// listObjectsV2
	// -------------------------------------------------------------------------

	public function testListObjectsV2KeyCount(): void {
		$ctx = [
			'name' => 'b', 'prefix' => '', 'delimiter' => '',
			'max_keys' => 1000, 'is_truncated' => false, 'key_count' => 2,
		];
		$objects = [
			['key' => 'a.txt', 'last_modified' => '2024-01-01T00:00:00.000Z', 'etag' => '"a"', 'size' => 1, 'storage_class' => 'STANDARD'],
			['key' => 'b.txt', 'last_modified' => '2024-01-01T00:00:00.000Z', 'etag' => '"b"', 'size' => 2, 'storage_class' => 'STANDARD'],
		];
		$out = $this->w->listObjectsV2($ctx, $objects, []);
		$xml = $this->xml($out);

		$this->assertSame('2', (string)$xml->KeyCount);
		$this->assertCount(2, $xml->Contents);
	}

	public function testListObjectsV2ContinuationTokens(): void {
		$ctx = [
			'name' => 'b', 'prefix' => '', 'delimiter' => '',
			'max_keys' => 1, 'is_truncated' => true, 'key_count' => 1,
			'continuation_token' => 'token-A',
			'next_continuation_token' => 'token-B',
		];
		$out = $this->w->listObjectsV2($ctx, [
			['key' => 'a.txt', 'last_modified' => '2024-01-01T00:00:00.000Z', 'etag' => '"a"', 'size' => 1, 'storage_class' => 'STANDARD'],
		], []);
		$xml = $this->xml($out);

		$this->assertSame('token-A', (string)$xml->ContinuationToken);
		$this->assertSame('token-B', (string)$xml->NextContinuationToken);
	}

	// -------------------------------------------------------------------------
	// Multipart
	// -------------------------------------------------------------------------

	public function testInitiateMultipartUploadResult(): void {
		$out = $this->w->initiateMultipartUploadResult('my-bucket', 'big.bin', 'upload-abc');
		$xml = $this->xml($out);

		$this->assertSame('my-bucket', (string)$xml->Bucket);
		$this->assertSame('big.bin', (string)$xml->Key);
		$this->assertSame('upload-abc', (string)$xml->UploadId);
	}

	public function testCompleteMultipartUploadResult(): void {
		$out = $this->w->completeMultipartUploadResult(
			'https://nc.example.com/s3/my-bucket/big.bin',
			'my-bucket', 'big.bin', '"etag-3"'
		);
		$xml = $this->xml($out);

		$this->assertSame('my-bucket', (string)$xml->Bucket);
		$this->assertSame('"etag-3"', (string)$xml->ETag);
	}

	public function testListParts(): void {
		$parts = [
			['part_number' => 1, 'last_modified' => '2024-01-01T00:00:00.000Z', 'etag' => '"p1"', 'size' => 5242880],
			['part_number' => 2, 'last_modified' => '2024-01-01T00:01:00.000Z', 'etag' => '"p2"', 'size' => 1048576],
		];
		$out = $this->w->listParts('b', 'k', 'uid', $parts, false);
		$xml = $this->xml($out);

		$this->assertSame('false', (string)$xml->IsTruncated);
		$this->assertCount(2, $xml->Part);
		$this->assertSame('1', (string)$xml->Part[0]->PartNumber);
		$this->assertSame('"p2"', (string)$xml->Part[1]->ETag);
	}

	public function testListMultipartUploads(): void {
		$uploads = [
			['key' => 'a.bin', 'upload_id' => 'uid1', 'initiated' => '2024-01-01T00:00:00.000Z'],
		];
		$out = $this->w->listMultipartUploads('b', $uploads, false);
		$xml = $this->xml($out);

		$this->assertSame('b', (string)$xml->Bucket);
		$this->assertSame('a.bin', (string)$xml->Upload->Key);
		$this->assertSame('uid1', (string)$xml->Upload->UploadId);
	}

	// -------------------------------------------------------------------------
	// Versioning
	// -------------------------------------------------------------------------

	public function testGetBucketVersioningEnabled(): void {
		$out = $this->w->getBucketVersioning('Enabled');
		$xml = $this->xml($out);
		$this->assertSame('Enabled', (string)$xml->Status);
	}

	public function testGetBucketVersioningEmpty(): void {
		$out = $this->w->getBucketVersioning('');
		$xml = $this->xml($out);
		// No <Status> element when versioning was never configured
		$this->assertSame('', (string)$xml->Status);
	}

	// -------------------------------------------------------------------------
	// Tagging
	// -------------------------------------------------------------------------

	public function testTagging(): void {
		$out = $this->w->tagging([
			['key' => 'env', 'value' => 'prod'],
			['key' => 'team', 'value' => 'ops'],
		]);
		$xml = $this->xml($out);

		$tags = $xml->TagSet->Tag;
		$this->assertCount(2, $tags);
		$this->assertSame('env', (string)$tags[0]->Key);
		$this->assertSame('prod', (string)$tags[0]->Value);
	}

	public function testTaggingEmpty(): void {
		$out = $this->w->tagging([]);
		$xml = $this->xml($out);
		$this->assertCount(0, $xml->TagSet->Tag ?? []);
	}

	// -------------------------------------------------------------------------
	// Error
	// -------------------------------------------------------------------------

	public function testError(): void {
		$out = $this->w->error('NoSuchBucket', 'The bucket does not exist', '/my-bucket', 'req-1');
		$xml = $this->xml($out);

		$this->assertSame('NoSuchBucket', (string)$xml->Code);
		$this->assertSame('The bucket does not exist', (string)$xml->Message);
		$this->assertSame('/my-bucket', (string)$xml->Resource);
		$this->assertSame('req-1', (string)$xml->RequestId);
	}

	// -------------------------------------------------------------------------
	// DeleteObjects result
	// -------------------------------------------------------------------------

	public function testDeleteObjectsResult(): void {
		$deleted = [['key' => 'a.txt'], ['key' => 'b.txt', 'version_id' => 'v1']];
		$errors = [['key' => 'c.txt', 'code' => 'AccessDenied', 'message' => 'Forbidden']];

		$out = $this->w->deleteObjectsResult($deleted, $errors);
		$xml = $this->xml($out);

		$this->assertCount(2, $xml->Deleted);
		$this->assertSame('b.txt', (string)$xml->Deleted[1]->Key);
		$this->assertSame('v1', (string)$xml->Deleted[1]->VersionId);
		$this->assertSame('AccessDenied', (string)$xml->Error->Code);
	}

	// -------------------------------------------------------------------------
	// CORS
	// -------------------------------------------------------------------------

	public function testCorsConfiguration(): void {
		$rules = [[
			'allowed_origins' => ['https://app.example.com'],
			'allowed_methods' => ['GET', 'PUT'],
			'allowed_headers' => ['Authorization'],
			'max_age_seconds' => 3600,
		]];
		$out = $this->w->corsConfiguration($rules);
		$xml = $this->xml($out);

		$rule = $xml->CORSRule;
		$this->assertSame('https://app.example.com', (string)$rule->AllowedOrigin);
		$this->assertSame('GET', (string)$rule->AllowedMethod[0]);
		$this->assertSame('PUT', (string)$rule->AllowedMethod[1]);
		$this->assertSame('3600', (string)$rule->MaxAgeSeconds);
	}

	// -------------------------------------------------------------------------
	// Encryption
	// -------------------------------------------------------------------------

	public function testServerSideEncryptionConfiguration(): void {
		$out = $this->w->serverSideEncryptionConfiguration('AES256');
		$xml = $this->xml($out);

		$this->assertSame('AES256', (string)$xml->Rule->ApplyServerSideEncryptionByDefault->SSEAlgorithm);
	}

	// -------------------------------------------------------------------------
	// Location
	// -------------------------------------------------------------------------

	public function testGetBucketLocation(): void {
		$out = $this->w->getBucketLocation('us-east-1');
		$xml = $this->xml($out);
		$this->assertSame('us-east-1', (string)$xml);
	}

	public function testListObjectsContentsLastModifiedAndStorageClass(): void {
		$ctx = [
			'name' => 'b', 'prefix' => '', 'delimiter' => '',
			'max_keys' => 1000, 'is_truncated' => false, 'marker' => '', 'next_marker' => '',
		];
		$out = $this->w->listObjects($ctx, [[
			'key' => 'f.txt',
			'last_modified' => '2024-06-01T12:00:00.000Z',
			'etag' => '"e"',
			'size' => 99,
			'storage_class' => 'STANDARD',
		]], []);
		$xml = $this->xml($out);
		$this->assertSame('2024-06-01T12:00:00.000Z', (string)$xml->Contents->LastModified);
		$this->assertSame('STANDARD', (string)$xml->Contents->StorageClass);
	}

	public function testListObjectsStorageClassFallback(): void {
		// When storage_class key is missing, should default to STANDARD
		$ctx = [
			'name' => 'b', 'prefix' => '', 'delimiter' => '',
			'max_keys' => 1000, 'is_truncated' => false, 'marker' => '', 'next_marker' => '',
		];
		$out = $this->w->listObjects($ctx, [[
			'key' => 'f.txt',
			'last_modified' => '2024-01-01T00:00:00.000Z',
			'etag' => '"e"',
			'size' => 1,
		]], []);
		$xml = $this->xml($out);
		$this->assertSame('STANDARD', (string)$xml->Contents->StorageClass);
	}

	public function testOutputIsCompact(): void {
		// setIndent(false) means output has no extra whitespace between tags
		$out = $this->w->error('Test', 'msg', '/', 'r');
		$this->assertStringNotContainsString("\n  <", $out);
	}



	public function testOutputIsUtf8Xml(): void {
		$out = $this->w->error('Test', 'Test', '/', 'r');
		$this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"', $out);
	}

	public function testSpecialCharsAreEscaped(): void {
		$out = $this->w->error('Test', 'Bucket <name> & "value"', '/', 'r');
		$this->assertStringContainsString('&lt;name&gt;', $out);
		$this->assertStringContainsString('&amp;', $out);
	}
}
