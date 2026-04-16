<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Auth;

use OCA\NcS3Api\Exception\SignatureDoesNotMatchException;
use OCA\NcS3Api\S3\S3Request;

/**
 * Validates AWS Signature Version 4 signatures.
 *
 * Algorithm summary (https://docs.aws.amazon.com/general/latest/gr/sigv4-create-canonical-request.html):
 *
 * 1. Build Canonical Request:
 *      HTTPMethod\n
 *      CanonicalURI\n
 *      CanonicalQueryString\n
 *      CanonicalHeaders\n
 *      SignedHeaders\n
 *      HexEncode(Hash(body))
 *
 * 2. Build String-to-Sign:
 *      "AWS4-HMAC-SHA256\n"
 *      <ISO8601 timestamp>\n
 *      <date>/<region>/<service>/aws4_request\n
 *      HexEncode(Hash(CanonicalRequest))
 *
 * 3. Derive Signing Key:
 *      kDate    = HMAC-SHA256("AWS4" + secretKey, date)
 *      kRegion  = HMAC-SHA256(kDate, region)
 *      kService = HMAC-SHA256(kRegion, service)
 *      kSigning = HMAC-SHA256(kService, "aws4_request")
 *
 * 4. Compute Signature = HexEncode(HMAC-SHA256(kSigning, StringToSign))
 *
 * 5. Compare with provided signature (timing-safe).
 */
class SigV4Validator {
	/**
	 * Verify the signature for a standard (Authorization-header) request.
	 *
	 * @param string $secretKey The raw secret key to sign with
	 * @throws SignatureDoesNotMatchException
	 */
	public function validateHeader(
		S3Request $request,
		SigV4Parser $parsed,
		string $secretKey,
	): void {
		$timestamp = $request->getHeader('x-amz-date');
		if ($timestamp === '') {
			$timestamp = $request->getHeader('date');
		}
		if ($timestamp === '') {
			throw new SignatureDoesNotMatchException('Missing x-amz-date header');
		}

		$bodyHash = $this->bodyHash($request);
		$canonicalReq = $this->canonicalRequest($request, $parsed->signedHeaders, $bodyHash);
		$credentialScope = "{$parsed->date}/{$parsed->region}/{$parsed->service}/aws4_request";
		$stringToSign = "AWS4-HMAC-SHA256\n{$timestamp}\n{$credentialScope}\n" . hash('sha256', $canonicalReq);
		$signingKey = $this->signingKey($secretKey, $parsed->date, $parsed->region, $parsed->service);
		$expected = bin2hex(hash_hmac('sha256', $stringToSign, $signingKey, true));

		if (!hash_equals($expected, strtolower($parsed->signature))) {
			throw new SignatureDoesNotMatchException('The request signature we calculated does not match the signature you provided.');
		}
	}

	/**
	 * Verify the signature for a presigned-URL request.
	 *
	 * For presigned URLs the body hash is always "UNSIGNED-PAYLOAD" and the
	 * X-Amz-Signature query parameter replaces the Authorization header.
	 *
	 * @throws SignatureDoesNotMatchException
	 */
	public function validatePresigned(
		S3Request $request,
		SigV4Parser $parsed,
		string $secretKey,
		string $expiresAt,   // Unix timestamp string from X-Amz-Date + X-Amz-Expires
	): void {
		$now = time();
		if ($now > (int)$expiresAt) {
			throw new \OCA\NcS3Api\Exception\S3Exception(
				\OCA\NcS3Api\S3\S3ErrorCodes::REQUEST_EXPIRED,
				'Presigned URL has expired',
			);
		}

		$timestamp = $request->getQuery('X-Amz-Date');
		$canonicalReq = $this->canonicalRequestPresigned($request, $parsed->signedHeaders);
		$credScope = "{$parsed->date}/{$parsed->region}/{$parsed->service}/aws4_request";
		$stringToSign = "AWS4-HMAC-SHA256\n{$timestamp}\n{$credScope}\n" . hash('sha256', $canonicalReq);
		$signingKey = $this->signingKey($secretKey, $parsed->date, $parsed->region, $parsed->service);
		$expected = bin2hex(hash_hmac('sha256', $stringToSign, $signingKey, true));

		if (!hash_equals($expected, strtolower($parsed->signature))) {
			throw new SignatureDoesNotMatchException('Presigned URL signature mismatch.');
		}
	}

	// -------------------------------------------------------------------------
	// Canonical Request helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the canonical request string for a standard signed request.
	 *
	 * @param list<string> $signedHeaderNames Lower-cased header names that were signed
	 */
	public function canonicalRequest(S3Request $request, array $signedHeaderNames, string $bodyHash): string {
		$uri = $this->canonicalUri($request->rawPath);
		$queryString = $this->canonicalQueryString($request->queryParams);
		$headers = $this->canonicalHeaders($request->headers, $signedHeaderNames);
		$signedHeadersStr = implode(';', $signedHeaderNames);

		return implode("\n", [
			$request->method,
			$uri,
			$queryString,
			$headers,
			'',              // trailing newline from headers block
			$signedHeadersStr,
			$bodyHash,
		]);
	}

	/**
	 * Build the canonical request string for a presigned URL request.
	 * Body hash is always UNSIGNED-PAYLOAD.
	 */
	public function canonicalRequestPresigned(S3Request $request, array $signedHeaderNames): string {
		// For presigned URLs the signature query params are excluded from canonical query string
		$filteredQuery = array_filter(
			$request->queryParams,
			fn ($k) => $k !== 'X-Amz-Signature',
			ARRAY_FILTER_USE_KEY,
		);
		$uri = $this->canonicalUri($request->rawPath);
		$queryString = $this->canonicalQueryString($filteredQuery);
		$headers = $this->canonicalHeaders($request->headers, $signedHeaderNames);
		$signedHeadersStr = implode(';', $signedHeaderNames);

		return implode("\n", [
			$request->method,
			$uri,
			$queryString,
			$headers,
			'',
			$signedHeadersStr,
			'UNSIGNED-PAYLOAD',
		]);
	}

	// -------------------------------------------------------------------------
	// Individual canonical-request components
	// -------------------------------------------------------------------------

	public function canonicalUri(string $rawPath): string {
		// Strip the app prefix (/index.php/apps/nc_s3api/s3/...) — keep only the /s3/... part
		// then URI-encode each path segment (but not the slashes)
		$path = $rawPath;

		// Normalise: encode each segment individually
		$segments = explode('/', $path);
		$encoded = array_map(fn ($s) => rawurlencode(rawurldecode($s)), $segments);
		$result = implode('/', $encoded);

		return $result !== '' ? $result : '/';
	}

	/** @param array<string,string> $params */
	public function canonicalQueryString(array $params): string {
		if (empty($params)) {
			return '';
		}
		$encoded = [];
		foreach ($params as $k => $v) {
			$encoded[rawurlencode($k)] = rawurlencode($v);
		}
		ksort($encoded);
		$parts = [];
		foreach ($encoded as $k => $v) {
			$parts[] = "$k=$v";
		}
		return implode('&', $parts);
	}

	/**
	 * @param array<string,string> $headers All request headers (lower-cased keys)
	 * @param list<string> $signedNames Names to include
	 */
	public function canonicalHeaders(array $headers, array $signedNames): string {
		$lines = [];
		foreach ($signedNames as $name) {
			$value = $headers[strtolower($name)] ?? '';
			// Trim leading/trailing whitespace, collapse internal whitespace
			$value = preg_replace('/\s+/', ' ', trim($value));
			$lines[] = strtolower($name) . ':' . $value;
		}
		return implode("\n", $lines) . "\n";
	}

	// -------------------------------------------------------------------------
	// Body hash
	// -------------------------------------------------------------------------

	public function bodyHash(S3Request $request): string {
		$provided = strtolower($request->getHeader('x-amz-content-sha256'));

		// Streaming upload — signed as STREAMING-AWS4-HMAC-SHA256-PAYLOAD
		// We accept the header value as-is (body chunks are not re-validated here)
		if (str_starts_with($provided, 'streaming-') || $provided === 'unsigned-payload') {
			return $provided;
		}

		// If the hash was provided and is a valid hex SHA-256, trust it
		if (preg_match('/^[0-9a-f]{64}$/', $provided)) {
			return $provided;
		}

		// Compute hash from body stream (only for small bodies where it's safe to buffer)
		if (is_resource($request->bodyStream)) {
			$pos = ftell($request->bodyStream);
			$body = stream_get_contents($request->bodyStream);
			fseek($request->bodyStream, $pos);  // rewind for later use
			return hash('sha256', $body ?: '');
		}

		if (is_string($request->bodyStream)) {
			return hash('sha256', $request->bodyStream);
		}

		return hash('sha256', '');
	}

	// -------------------------------------------------------------------------
	// Signing key derivation
	// -------------------------------------------------------------------------

	public function signingKey(string $secretKey, string $date, string $region, string $service): string {
		$kDate = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
		$kRegion = hash_hmac('sha256', $region, $kDate, true);
		$kService = hash_hmac('sha256', $service, $kRegion, true);
		$kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
		return $kSigning;
	}
}
