<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Auth;

use OCA\NcS3Api\Db\CredentialMapper;
use OCA\NcS3Api\Exception\AccessDeniedException;
use OCA\NcS3Api\Exception\S3Exception;
use OCA\NcS3Api\Exception\SignatureDoesNotMatchException;
use OCA\NcS3Api\S3\S3ErrorCodes;
use OCA\NcS3Api\S3\S3Request;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;

/**
 * Authenticates an incoming S3Request and returns an AuthContext.
 *
 * Two authentication paths are supported:
 *
 * A) Standard SigV4 (Authorization header)
 * B) Presigned URL (query parameters X-Amz-*)
 *
 * Credential resolution order:
 *   1. Mode B: access key found in s3api_credentials with explicit nc_user_id mapping
 *   2. Mode A: access key = NC username, secret looked up from s3api_credentials
 *              by (nc_user_id = accessKey). User must have created an S3 secret via
 *              the personal settings UI.
 */
class AuthService {
	public function __construct(
		private readonly SigV4Validator $validator,
		private readonly IUserManager $userManager,
		private readonly CredentialMapper $credentialMapper,
	) {
	}

	/** @throws S3Exception on auth failure */
	public function authenticate(S3Request $request): AuthContext {
		if (isset($request->queryParams['X-Amz-Signature'])) {
			return $this->authenticatePresigned($request);
		}

		$authHeader = $request->getHeader('authorization');
		if ($authHeader !== '') {
			return $this->authenticateHeader($request, $authHeader);
		}

		throw new AccessDeniedException('No authentication credentials provided');
	}

	// -------------------------------------------------------------------------

	private function authenticateHeader(S3Request $request, string $authHeader): AuthContext {
		$parsed = SigV4Parser::fromHeader($authHeader);
		return $this->resolveAndValidate($request, $parsed, isPresigned: false, expiresAt: null);
	}

	private function authenticatePresigned(S3Request $request): AuthContext {
		$q = $request->queryParams;
		$parsed = SigV4Parser::fromQueryParams($q);

		$amzDate = $q['X-Amz-Date'] ?? '';
		$expires = (int)($q['X-Amz-Expires'] ?? 0);
		if ($amzDate === '' || $expires <= 0) {
			throw new S3Exception(S3ErrorCodes::INVALID_SECURITY, 'Invalid presigned URL parameters');
		}
		$createdAt = \DateTime::createFromFormat('Ymd\THis\Z', $amzDate, new \DateTimeZone('UTC'));
		if ($createdAt === false) {
			throw new S3Exception(S3ErrorCodes::INVALID_SECURITY, 'Cannot parse X-Amz-Date');
		}
		$expiresAt = (string)($createdAt->getTimestamp() + $expires);

		return $this->resolveAndValidate($request, $parsed, isPresigned: true, expiresAt: $expiresAt);
	}

	private function resolveAndValidate(
		S3Request $request,
		SigV4Parser $parsed,
		bool $isPresigned,
		?string $expiresAt,
	): AuthContext {
		// ── Mode B: admin-managed key-pair (access key ≠ NC username) ─────────
		try {
			$cred = $this->credentialMapper->findByAccessKey($parsed->accessKey);
			$ncUser = $this->userManager->get($cred->getNcUserId());
			if ($ncUser === null) {
				throw new AccessDeniedException("Mapped Nextcloud user '{$cred->getNcUserId()}' not found");
			}
			$this->validateSignature($request, $parsed, $cred->getSecretKey(), $isPresigned, $expiresAt);
			return AuthContext::authenticated(
				$ncUser,
				$isPresigned ? AuthContext::METHOD_PRESIGNED : AuthContext::METHOD_SIGV4,
			);
		} catch (DoesNotExistException) {
			// Not in credentials table → try Mode A
		}

		// ── Mode A: access key = NC username, secret from user's credentials ──
		$user = $this->userManager->get($parsed->accessKey);
		if ($user === null) {
			throw new AccessDeniedException("Unknown access key: {$parsed->accessKey}");
		}

		// Find the user's own credential row (nc_user_id = accessKey, no separate mapping)
		$userCreds = $this->credentialMapper->findByUser($parsed->accessKey);
		if (empty($userCreds)) {
			throw new SignatureDoesNotMatchException(
				"No S3 secret found for user '{$parsed->accessKey}'. "
				. 'Please create one in the S3 Gateway personal settings.'
			);
		}

		// Try each stored secret (a user may have multiple key-pairs)
		foreach ($userCreds as $cred) {
			try {
				$this->validateSignature($request, $parsed, $cred->getSecretKey(), $isPresigned, $expiresAt);
				return AuthContext::authenticated(
					$user,
					$isPresigned ? AuthContext::METHOD_PRESIGNED : AuthContext::METHOD_SIGV4,
				);
			} catch (SignatureDoesNotMatchException) {
				// Try next credential
			}
		}

		throw new SignatureDoesNotMatchException("Signature does not match any stored secret for '{$parsed->accessKey}'");
	}

	/** @throws SignatureDoesNotMatchException|S3Exception */
	private function validateSignature(
		S3Request $request,
		SigV4Parser $parsed,
		string $secretKey,
		bool $isPresigned,
		?string $expiresAt,
	): void {
		if ($isPresigned) {
			$this->validator->validatePresigned($request, $parsed, $secretKey, $expiresAt ?? '0');
		} else {
			$this->validator->validateHeader($request, $parsed, $secretKey);
		}
	}
}
