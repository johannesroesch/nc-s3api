<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Tests\Unit\Auth;

use OCA\NcS3Api\Auth\AuthContext;
use OCA\NcS3Api\Auth\AuthService;
use OCA\NcS3Api\Auth\SigV4Parser;
use OCA\NcS3Api\Auth\SigV4Validator;
use OCA\NcS3Api\Db\Credential;
use OCA\NcS3Api\Db\CredentialMapper;
use OCA\NcS3Api\Exception\AccessDeniedException;
use OCA\NcS3Api\Exception\SignatureDoesNotMatchException;
use OCA\NcS3Api\S3\S3Request;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AuthServiceTest extends TestCase {
	private const ACCESS_KEY  = 'AKIDEXAMPLE';
	private const SECRET_KEY  = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

	private SigV4Validator&MockObject  $validator;
	private IUserManager&MockObject    $userManager;
	private CredentialMapper&MockObject $credentialMapper;
	private AuthService $service;

	protected function setUp(): void {
		$this->validator        = $this->createMock(SigV4Validator::class);
		$this->userManager      = $this->createMock(IUserManager::class);
		$this->credentialMapper = $this->createMock(CredentialMapper::class);

		$this->service = new AuthService(
			$this->validator,
			$this->userManager,
			$this->credentialMapper,
		);
	}

	// ─── helpers ────────────────────────────────────────────────────────────────

	private function mockUser(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	private function makeCredential(string $accessKey, string $secretKey, string $userId): Credential {
		$c = new Credential();
		$c->setAccessKey($accessKey);
		$c->setSecretKey($secretKey);
		$c->setUserId($userId);
		$c->setCreatedAt(time());
		return $c;
	}

	/** Build a minimal S3Request with an Authorization header. */
	private function reqWithHeader(string $accessKey = self::ACCESS_KEY): S3Request {
		$header = 'AWS4-HMAC-SHA256 Credential=' . $accessKey
			. '/20240101/us-east-1/s3/aws4_request,'
			. 'SignedHeaders=host,'
			. 'Signature=deadsig';
		return new S3Request('GET', 'b', 'k', [], ['authorization' => $header, 'x-amz-date' => '20240101T000000Z'], '', '/b/k', 'localhost');
	}

	/** Build a minimal S3Request with presigned query params. */
	private function reqPresigned(string $accessKey = self::ACCESS_KEY): S3Request {
		return new S3Request('GET', 'b', 'k', [
			'X-Amz-Algorithm'    => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'   => $accessKey . '/20240101/us-east-1/s3/aws4_request',
			'X-Amz-Date'         => '20240101T000000Z',
			'X-Amz-Expires'      => '3600',
			'X-Amz-SignedHeaders' => 'host',
			'X-Amz-Signature'    => 'deadsig',
		], ['host' => 'localhost'], '', '/b/k', 'localhost');
	}

	// ─── No credentials provided ────────────────────────────────────────────────

	public function testNoAuthHeaderThrows(): void {
		$this->expectException(AccessDeniedException::class);
		$this->service->authenticate(
			new S3Request('GET', 'b', 'k', [], [], '', '/b/k', 'localhost')
		);
	}

	// ─── Mode B (admin credential) ──────────────────────────────────────────────

	public function testModeBSuccessReturnsAuthContext(): void {
		$cred = $this->makeCredential(self::ACCESS_KEY, self::SECRET_KEY, 'alice');
		$this->credentialMapper->method('findByAccessKey')->willReturn($cred);
		$this->userManager->method('get')->with('alice')->willReturn($this->mockUser('alice'));
		$this->validator->method('validateHeader'); // no exception → success

		$ctx = $this->service->authenticate($this->reqWithHeader());

		$this->assertTrue($ctx->authenticated);
		$this->assertSame('alice', $ctx->userId);
		$this->assertSame(AuthContext::METHOD_SIGV4, $ctx->authMethod);
	}

	public function testModeBMappedUserNotFoundThrows(): void {
		$cred = $this->makeCredential(self::ACCESS_KEY, self::SECRET_KEY, 'ghost');
		$this->credentialMapper->method('findByAccessKey')->willReturn($cred);
		$this->userManager->method('get')->with('ghost')->willReturn(null);

		$this->expectException(AccessDeniedException::class);
		$this->service->authenticate($this->reqWithHeader());
	}

	public function testModeBSignatureMismatchFallsToModeA(): void {
		// Mode B credential found but signature is wrong → validator throws
		$cred = $this->makeCredential(self::ACCESS_KEY, self::SECRET_KEY, 'alice');
		$this->credentialMapper->method('findByAccessKey')->willReturn($cred);
		$this->userManager->method('get')->with('alice')->willReturn($this->mockUser('alice'));
		$this->validator->method('validateHeader')
			->willThrowException(new SignatureDoesNotMatchException('bad sig'));

		// Mode B throws SignatureDoesNotMatchException — not caught, propagates
		$this->expectException(SignatureDoesNotMatchException::class);
		$this->service->authenticate($this->reqWithHeader());
	}

	// ─── Mode A (username = access key) ─────────────────────────────────────────

	public function testModeASuccessReturnsAuthContext(): void {
		// No Mode B credential
		$this->credentialMapper->method('findByAccessKey')
			->willThrowException(new DoesNotExistException(''));
		// User exists with matching name
		$this->userManager->method('get')->with(self::ACCESS_KEY)->willReturn($this->mockUser(self::ACCESS_KEY));
		// User has one stored secret
		$cred = $this->makeCredential(self::ACCESS_KEY, self::SECRET_KEY, self::ACCESS_KEY);
		$this->credentialMapper->method('findByUser')->with(self::ACCESS_KEY)->willReturn([$cred]);
		$this->validator->method('validateHeader'); // success

		$ctx = $this->service->authenticate($this->reqWithHeader());

		$this->assertTrue($ctx->authenticated);
		$this->assertSame(self::ACCESS_KEY, $ctx->userId);
	}

	public function testModeAUnknownAccessKeyThrows(): void {
		$this->credentialMapper->method('findByAccessKey')
			->willThrowException(new DoesNotExistException(''));
		$this->userManager->method('get')->willReturn(null); // user not found

		$this->expectException(AccessDeniedException::class);
		$this->service->authenticate($this->reqWithHeader());
	}

	public function testModeANoStoredSecretThrows(): void {
		$this->credentialMapper->method('findByAccessKey')
			->willThrowException(new DoesNotExistException(''));
		$this->userManager->method('get')->with(self::ACCESS_KEY)->willReturn($this->mockUser(self::ACCESS_KEY));
		$this->credentialMapper->method('findByUser')->willReturn([]); // no secrets

		$this->expectException(SignatureDoesNotMatchException::class);
		$this->service->authenticate($this->reqWithHeader());
	}

	public function testModeATriesAllSecretsBeforeFailing(): void {
		$this->credentialMapper->method('findByAccessKey')
			->willThrowException(new DoesNotExistException(''));
		$this->userManager->method('get')->willReturn($this->mockUser(self::ACCESS_KEY));

		$cred1 = $this->makeCredential(self::ACCESS_KEY, 'wrong1', self::ACCESS_KEY);
		$cred2 = $this->makeCredential(self::ACCESS_KEY, 'wrong2', self::ACCESS_KEY);
		$this->credentialMapper->method('findByUser')->willReturn([$cred1, $cred2]);

		// Both attempts fail
		$this->validator->method('validateHeader')
			->willThrowException(new SignatureDoesNotMatchException('bad'));

		$this->expectException(SignatureDoesNotMatchException::class);
		$this->service->authenticate($this->reqWithHeader());
	}

	public function testModeASecondSecretMatchesReturnsContext(): void {
		$this->credentialMapper->method('findByAccessKey')
			->willThrowException(new DoesNotExistException(''));
		$this->userManager->method('get')->willReturn($this->mockUser(self::ACCESS_KEY));

		$cred1 = $this->makeCredential(self::ACCESS_KEY, 'wrong',       self::ACCESS_KEY);
		$cred2 = $this->makeCredential(self::ACCESS_KEY, self::SECRET_KEY, self::ACCESS_KEY);
		$this->credentialMapper->method('findByUser')->willReturn([$cred1, $cred2]);

		// First secret fails, second succeeds
		$this->validator->method('validateHeader')
			->willReturnCallback(function ($req, $parsed, string $secret): void {
				if ($secret === 'wrong') {
					throw new SignatureDoesNotMatchException('bad');
				}
				// correct secret → no exception
			});

		$ctx = $this->service->authenticate($this->reqWithHeader());
		$this->assertTrue($ctx->authenticated);
	}

	// ─── Presigned URL path ──────────────────────────────────────────────────────

	public function testPresignedModeBSuccess(): void {
		$cred = $this->makeCredential(self::ACCESS_KEY, self::SECRET_KEY, 'alice');
		$this->credentialMapper->method('findByAccessKey')->willReturn($cred);
		$this->userManager->method('get')->with('alice')->willReturn($this->mockUser('alice'));
		$this->validator->method('validatePresigned'); // success

		$ctx = $this->service->authenticate($this->reqPresigned());

		$this->assertTrue($ctx->authenticated);
		$this->assertSame(AuthContext::METHOD_PRESIGNED, $ctx->authMethod);
	}

	public function testPresignedModeASuccess(): void {
		$this->credentialMapper->method('findByAccessKey')
			->willThrowException(new DoesNotExistException(''));
		$this->userManager->method('get')->willReturn($this->mockUser(self::ACCESS_KEY));
		$cred = $this->makeCredential(self::ACCESS_KEY, self::SECRET_KEY, self::ACCESS_KEY);
		$this->credentialMapper->method('findByUser')->willReturn([$cred]);
		$this->validator->method('validatePresigned'); // success

		$ctx = $this->service->authenticate($this->reqPresigned());

		$this->assertSame(AuthContext::METHOD_PRESIGNED, $ctx->authMethod);
	}

	public function testPresignedMissingDateThrows(): void {
		// X-Amz-Date missing from presigned params → S3Exception
		$this->credentialMapper->method('findByAccessKey')
			->willThrowException(new DoesNotExistException(''));

		$reqMissingDate = new S3Request('GET', 'b', 'k', [
			'X-Amz-Algorithm'    => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'   => self::ACCESS_KEY . '/20240101/us-east-1/s3/aws4_request',
			// X-Amz-Date intentionally absent
			'X-Amz-Expires'      => '3600',
			'X-Amz-SignedHeaders' => 'host',
			'X-Amz-Signature'    => 'sig',
		], [], '', '/b/k', 'localhost');

		$this->expectException(\OCA\NcS3Api\Exception\S3Exception::class);
		$this->service->authenticate($reqMissingDate);
	}
}
