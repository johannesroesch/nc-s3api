<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Auth;

use OCA\NcS3Api\Db\PresignedUrlMapper;
use OCA\NcS3Api\Exception\AccessDeniedException;
use OCA\NcS3Api\Exception\SignatureDoesNotMatchException;
use OCA\NcS3Api\S3\S3Request;

/**
 * Validates presigned URL signatures.
 *
 * Presigned URLs carry all auth information in query parameters:
 *   X-Amz-Algorithm, X-Amz-Credential, X-Amz-Date, X-Amz-Expires,
 *   X-Amz-SignedHeaders, X-Amz-Signature
 *
 * The body hash is always UNSIGNED-PAYLOAD for presigned requests.
 */
class PresignedUrlValidator {
	public function __construct(
		private SigV4Validator $sigV4Validator,
		private PresignedUrlMapper $presignedUrlMapper,
	) {
	}

	/**
	 * Validate a presigned request.
	 *
	 * @throws AccessDeniedException if the URL is expired or already used
	 * @throws SignatureDoesNotMatchException if the signature is invalid
	 */
	public function validate(S3Request $request, string $secretKey): void {
		$params = $request->queryParams;

		$algorithm = $params['X-Amz-Algorithm'] ?? '';
		$credential = $params['X-Amz-Credential'] ?? '';
		$date = $params['X-Amz-Date'] ?? '';
		$expires = (int)($params['X-Amz-Expires'] ?? 0);
		$sigHeaders = $params['X-Amz-SignedHeaders'] ?? '';
		$signature = $params['X-Amz-Signature'] ?? '';

		if ($algorithm !== 'AWS4-HMAC-SHA256') {
			throw new SignatureDoesNotMatchException('Unsupported algorithm');
		}

		// Parse credential: AKID/YYYYMMDD/region/service/aws4_request
		$parts = explode('/', $credential);
		if (count($parts) !== 5) {
			throw new SignatureDoesNotMatchException('Malformed X-Amz-Credential');
		}
		[, $dateShort, $region, $service] = $parts;

		// Check expiry
		$requestTime = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $date, new \DateTimeZone('UTC'));
		if ($requestTime === false) {
			throw new SignatureDoesNotMatchException('Malformed X-Amz-Date');
		}
		$expiresAt = $requestTime->getTimestamp() + $expires;
		if (time() > $expiresAt) {
			throw new AccessDeniedException('Presigned URL has expired');
		}

		// Build signed headers list
		$signedHeaderNames = array_filter(array_map('trim', explode(';', $sigHeaders)));

		// For presigned URLs the body hash is always UNSIGNED-PAYLOAD
		$bodyHash = 'UNSIGNED-PAYLOAD';

		// Rebuild query params WITHOUT X-Amz-Signature for canonical request
		$queryForCanonical = $params;
		unset($queryForCanonical['X-Amz-Signature']);

		// Rebuild request with modified query params for canonical request construction
		$canonicalRequest = new S3Request(
			method:      $request->method,
			bucket:      $request->bucket,
			key:         $request->key,
			queryParams: $queryForCanonical,
			headers:     $request->headers,
			bodyStream:  '',
			rawPath:     $request->rawPath,
			host:        $request->host,
		);

		$canonicalReq = $this->sigV4Validator->canonicalRequest($canonicalRequest, $signedHeaderNames, $bodyHash);
		$credentialScope = "{$dateShort}/{$region}/{$service}/aws4_request";
		$stringToSign = "AWS4-HMAC-SHA256\n{$date}\n{$credentialScope}\n" . hash('sha256', $canonicalReq);
		$signingKey = $this->sigV4Validator->signingKey($secretKey, $dateShort, $region, $service);
		$expected = bin2hex(hash_hmac('sha256', $stringToSign, $signingKey, true));

		if (!hash_equals($expected, strtolower($signature))) {
			throw new SignatureDoesNotMatchException('Presigned URL signature mismatch');
		}
	}

	/**
	 * Generate a presigned URL query string for a given bucket/key/method.
	 *
	 * This is a helper used by the settings/API layer to hand out presigned URLs
	 * to Nextcloud users who want to share time-limited S3 access.
	 *
	 * @param string $accessKey S3 access key (stored credential)
	 * @param string $secretKey S3 secret key
	 * @param string $region Region (arbitrary, e.g. "us-east-1")
	 * @param string $bucket Bucket name
	 * @param string $key Object key
	 * @param string $method HTTP method (GET, PUT, DELETE)
	 * @param int $ttl Time-to-live in seconds (default 3600)
	 * @param string $host Nextcloud host (e.g. cloud.example.com)
	 * @param string $basePath Base path (e.g. /index.php/apps/nc_s3api/s3)
	 * @return string Full presigned URL
	 */
	public function generate(
		string $accessKey,
		string $secretKey,
		string $region,
		string $bucket,
		string $key,
		string $method,
		int $ttl,
		string $host,
		string $basePath,
	): string {
		$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$date = $now->format('Ymd\THis\Z');
		$dateShort = $now->format('Ymd');
		$service = 's3';

		$credentialScope = "{$dateShort}/{$region}/{$service}/aws4_request";
		$credential = "{$accessKey}/{$credentialScope}";

		$objectPath = '/' . ltrim($basePath, '/') . '/' . rawurlencode($bucket) . '/' . $key;

		$queryParams = [
			'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential' => $credential,
			'X-Amz-Date' => $date,
			'X-Amz-Expires' => (string)$ttl,
			'X-Amz-SignedHeaders' => 'host',
		];

		$headers = ['host' => $host];

		$dummyRequest = new S3Request(
			method:      $method,
			bucket:      $bucket,
			key:         $key,
			queryParams: $queryParams,
			headers:     $headers,
			bodyStream:  '',
			rawPath:     $objectPath,
			host:        $host,
		);

		$canonicalReq = $this->sigV4Validator->canonicalRequest($dummyRequest, ['host'], 'UNSIGNED-PAYLOAD');
		$stringToSign = "AWS4-HMAC-SHA256\n{$date}\n{$credentialScope}\n" . hash('sha256', $canonicalReq);
		$signingKey = $this->sigV4Validator->signingKey($secretKey, $dateShort, $region, $service);
		$signature = bin2hex(hash_hmac('sha256', $stringToSign, $signingKey, true));

		$queryParams['X-Amz-Signature'] = $signature;

		$scheme = 'https';
		return "{$scheme}://{$host}{$objectPath}?" . http_build_query($queryParams);
	}
}
