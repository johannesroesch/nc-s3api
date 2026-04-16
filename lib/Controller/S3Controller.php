<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Controller;

use OCA\NcS3Api\Auth\AuthService;
use OCA\NcS3Api\Dispatcher\S3Dispatcher;
use OCA\NcS3Api\S3\S3Request;
use OCA\NcS3Api\S3\S3Response;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;

/**
 * Single entry point for all S3 API requests.
 *
 * This controller intentionally bypasses Nextcloud's session-based
 * authentication (@PublicPage) and performs its own AWS Signature V4
 * validation via AuthService.
 */
class S3Controller extends Controller {
	public function __construct(
		IRequest $request,
		private readonly AuthService $authService,
		private readonly S3Dispatcher $dispatcher,
	) {
		parent::__construct('nc_s3api', $request);
	}

	/**
	 * Handle all S3 requests.
	 *
	 * Route parameters:
	 *   $bucket — bucket name (null for ListBuckets)
	 *   $key    — object key, may contain slashes (null for bucket-level ops)
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function dispatch(string $bucket = '', string $key = ''): Response {
		$s3Request = $this->buildS3Request(
			$bucket !== '' ? $bucket : null,
			$key !== '' ? $key    : null,
		);

		$authContext = null;
		try {
			$authContext = $this->authService->authenticate($s3Request);
		} catch (\Throwable $e) {
			// Auth failures are handled in the dispatcher's error path
			// by passing a null context and letting handlers throw AccessDenied.
			// For hard auth errors (signature mismatch) we catch immediately.
			return $this->toHttpResponse(
				$this->dispatcher->dispatch($s3Request, \OCA\NcS3Api\Auth\AuthContext::unauthenticated()),
			);
		}

		$s3Response = $this->dispatcher->dispatch($s3Request, $authContext);
		return $this->toHttpResponse($s3Response);
	}

	// -------------------------------------------------------------------------

	private function buildS3Request(?string $bucket, ?string $key): S3Request {
		$method = strtoupper($this->request->getMethod());
		$headers = $this->normaliseHeaders();

		// Collect query parameters
		$queryParams = [];
		$rawQuery = $_SERVER['QUERY_STRING'] ?? '';
		if ($rawQuery !== '') {
			parse_str($rawQuery, $queryParams);
		}

		// Body: open a PHP input stream for streaming reads
		$bodyStream = fopen('php://input', 'rb');

		$host = $this->request->getHeader('Host') ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
		$rawPath = $this->request->getPathInfo() ?? '/';

		return new S3Request(
			method:      $method,
			bucket:      $bucket,
			key:         $key,
			queryParams: $queryParams,
			headers:     $headers,
			bodyStream:  $bodyStream,
			rawPath:     $rawPath,
			host:        $host,
		);
	}

	/** @return array<string,string> All request headers, keys lower-cased */
	private function normaliseHeaders(): array {
		$headers = [];
		foreach (getallheaders() as $name => $value) {
			$headers[strtolower($name)] = $value;
		}
		return $headers;
	}

	private function toHttpResponse(S3Response $s3): Response {
		if ($s3->streamBody !== null) {
			$response = new StreamResponse($s3->streamBody);
		} elseif ($s3->xmlBody !== null) {
			$response = new DataDisplayResponse($s3->xmlBody, $s3->statusCode, [
				'Content-Type' => 'application/xml',
			]);
		} else {
			$response = new Response();
			$response->setStatus($s3->statusCode);
		}

		// Common S3 headers
		$requestId = $s3->headers['x-amz-request-id'] ?? bin2hex(random_bytes(8));
		$response->addHeader('x-amz-request-id', $requestId);
		$response->addHeader('x-amz-id-2', 'nc_s3api');
		$response->addHeader('Server', 'nc_s3api');

		// Merge extra headers from the S3Response
		foreach ($s3->headers as $name => $value) {
			$response->addHeader($name, $value);
		}

		if ($s3->statusCode !== 200) {
			$response->setStatus($s3->statusCode);
		}

		return $response;
	}
}
