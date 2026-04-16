<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Tests\Unit\Auth;

use OCA\NcS3Api\Auth\PresignedUrlValidator;
use OCA\NcS3Api\Auth\SigV4Validator;
use OCA\NcS3Api\Db\PresignedUrlMapper;
use OCA\NcS3Api\Exception\AccessDeniedException;
use OCA\NcS3Api\Exception\SignatureDoesNotMatchException;
use OCA\NcS3Api\S3\S3Request;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PresignedUrlValidatorTest extends TestCase {
	private const ACCESS_KEY = 'AKIDEXAMPLE';
	private const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

	private PresignedUrlValidator $presignedValidator;
	private PresignedUrlMapper&MockObject $presignedUrlMapper;

	protected function setUp(): void {
		$this->presignedUrlMapper = $this->createMock(PresignedUrlMapper::class);
		// Use the real SigV4Validator so crypto is actually exercised
		$this->presignedValidator = new PresignedUrlValidator(
			new SigV4Validator(),
			$this->presignedUrlMapper,
		);
	}

	// ─── helpers ────────────────────────────────────────────────────────────────

	/**
	 * Build a properly signed presigned request (using generate() internally).
	 * The returned URL is then parsed back into an S3Request for validate().
	 */
	private function buildValidRequest(
		string $method = 'GET',
		string $bucket = 'my-bucket',
		string $key = 'my-object.txt',
		int $ttl = 3600,
	): S3Request {
		$host = 'nc.example.com';
		$basePath = '/index.php/apps/nc_s3api/s3';

		$url = $this->presignedValidator->generate(
			self::ACCESS_KEY,
			self::SECRET_KEY,
			'us-east-1',
			$bucket,
			$key,
			$method,
			$ttl,
			$host,
			$basePath,
		);

		// Parse URL back into an S3Request
		$parts = parse_url($url);
		parse_str($parts['query'] ?? '', $queryParams);
		$rawPath = $parts['path'] ?? '/';

		return new S3Request(
			method:      $method,
			bucket:      $bucket,
			key:         $key,
			queryParams: $queryParams,
			headers:     ['host' => $host],
			bodyStream:  '',
			rawPath:     $rawPath,
			host:        $host,
		);
	}

	// ─── validate — success ──────────────────────────────────────────────────────

	public function testValidateSucceedsWithCorrectSignature(): void {
		$request = $this->buildValidRequest('GET', 'my-bucket', 'file.txt', 300);
		// Must not throw
		$this->presignedValidator->validate($request, self::SECRET_KEY);
		$this->addToAssertionCount(1);
	}

	public function testValidatePutRequest(): void {
		$request = $this->buildValidRequest('PUT', 'uploads', 'data/file.bin', 900);
		$this->presignedValidator->validate($request, self::SECRET_KEY);
		$this->addToAssertionCount(1);
	}

	// ─── validate — error cases ──────────────────────────────────────────────────

	public function testValidateWrongSecretThrows(): void {
		$request = $this->buildValidRequest();
		$this->expectException(SignatureDoesNotMatchException::class);
		$this->presignedValidator->validate($request, 'completely-wrong-secret');
	}

	public function testValidateExpiredUrlThrows(): void {
		// Build a request then manually backdate X-Amz-Date and set X-Amz-Expires=1
		$host = 'nc.example.com';
		$oldDate = '20200101T000000Z'; // well in the past
		$queryParams = [
			'X-Amz-Algorithm'    => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'   => self::ACCESS_KEY . '/20200101/us-east-1/s3/aws4_request',
			'X-Amz-Date'         => $oldDate,
			'X-Amz-Expires'      => '1',        // 1 second TTL
			'X-Amz-SignedHeaders' => 'host',
			'X-Amz-Signature'    => 'irrelevant', // expiry checked before signature
		];
		$request = new S3Request('GET', 'b', 'k', $queryParams, ['host' => $host], '', '/s3/b/k', $host);

		$this->expectException(AccessDeniedException::class);
		$this->presignedValidator->validate($request, self::SECRET_KEY);
	}

	public function testValidateMalformedAlgorithmThrows(): void {
		$queryParams = [
			'X-Amz-Algorithm'    => 'HMAC-MD5',   // wrong
			'X-Amz-Credential'   => self::ACCESS_KEY . '/20240101/us-east-1/s3/aws4_request',
			'X-Amz-Date'         => '20240101T000000Z',
			'X-Amz-Expires'      => '3600',
			'X-Amz-SignedHeaders' => 'host',
			'X-Amz-Signature'    => 'sig',
		];
		$request = new S3Request('GET', 'b', 'k', $queryParams, ['host' => 'localhost'], '', '/s3/b/k', 'localhost');

		$this->expectException(SignatureDoesNotMatchException::class);
		$this->presignedValidator->validate($request, self::SECRET_KEY);
	}

	public function testValidateMalformedCredentialThrows(): void {
		$queryParams = [
			'X-Amz-Algorithm'    => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'   => 'AKID/20240101',  // too short (2 parts instead of 5)
			'X-Amz-Date'         => '20240101T000000Z',
			'X-Amz-Expires'      => '3600',
			'X-Amz-SignedHeaders' => 'host',
			'X-Amz-Signature'    => 'sig',
		];
		$request = new S3Request('GET', 'b', 'k', $queryParams, ['host' => 'localhost'], '', '/s3/b/k', 'localhost');

		$this->expectException(SignatureDoesNotMatchException::class);
		$this->presignedValidator->validate($request, self::SECRET_KEY);
	}

	public function testValidateMalformedDateThrows(): void {
		$queryParams = [
			'X-Amz-Algorithm'    => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'   => self::ACCESS_KEY . '/20240101/us-east-1/s3/aws4_request',
			'X-Amz-Date'         => 'not-a-date',
			'X-Amz-Expires'      => '3600',
			'X-Amz-SignedHeaders' => 'host',
			'X-Amz-Signature'    => 'sig',
		];
		$request = new S3Request('GET', 'b', 'k', $queryParams, ['host' => 'localhost'], '', '/s3/b/k', 'localhost');

		$this->expectException(SignatureDoesNotMatchException::class);
		$this->presignedValidator->validate($request, self::SECRET_KEY);
	}

	public function testValidateTamperedSignatureThrows(): void {
		$request = $this->buildValidRequest();
		// Replace signature in query params with garbage
		$queryParams = $request->queryParams;
		$queryParams['X-Amz-Signature'] = str_repeat('0', 64);
		$tampered = new S3Request(
			method:      $request->method,
			bucket:      $request->bucket,
			key:         $request->key,
			queryParams: $queryParams,
			headers:     $request->headers,
			bodyStream:  '',
			rawPath:     $request->rawPath,
			host:        $request->host,
		);

		$this->expectException(SignatureDoesNotMatchException::class);
		$this->presignedValidator->validate($tampered, self::SECRET_KEY);
	}

	// ─── generate ────────────────────────────────────────────────────────────────

	public function testGenerateProducesHttpsUrl(): void {
		$url = $this->presignedValidator->generate(
			self::ACCESS_KEY, self::SECRET_KEY, 'us-east-1',
			'my-bucket', 'object.txt', 'GET', 3600,
			'nc.example.com', '/index.php/apps/nc_s3api/s3',
		);

		$this->assertStringStartsWith('https://', $url);
	}

	public function testGenerateUrlContainsRequiredQueryParams(): void {
		$url = $this->presignedValidator->generate(
			self::ACCESS_KEY, self::SECRET_KEY, 'us-east-1',
			'my-bucket', 'object.txt', 'GET', 900,
			'nc.example.com', '/index.php/apps/nc_s3api/s3',
		);

		parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
		$this->assertSame('AWS4-HMAC-SHA256', $q['X-Amz-Algorithm']);
		$this->assertStringContainsString(self::ACCESS_KEY, $q['X-Amz-Credential']);
		$this->assertSame('900', $q['X-Amz-Expires']);
		$this->assertNotEmpty($q['X-Amz-Signature']);
	}

	public function testGenerateAndValidateRoundtrip(): void {
		// generate → parse URL → validate: must succeed
		$request = $this->buildValidRequest('GET', 'photos', 'holiday.jpg', 600);
		$this->presignedValidator->validate($request, self::SECRET_KEY);
		$this->addToAssertionCount(1);
	}

	public function testGenerateDifferentMethodsProduceDifferentSignatures(): void {
		$args = [self::ACCESS_KEY, self::SECRET_KEY, 'us-east-1', 'b', 'k', '', 3600, 'h', '/p'];
		$args[5] = 'GET';
		$urlGet = $this->presignedValidator->generate(...$args);
		$args[5] = 'PUT';
		$urlPut = $this->presignedValidator->generate(...$args);

		parse_str((string)parse_url($urlGet, PHP_URL_QUERY), $qGet);
		parse_str((string)parse_url($urlPut, PHP_URL_QUERY), $qPut);
		$this->assertNotSame($qGet['X-Amz-Signature'], $qPut['X-Amz-Signature']);
	}
}
