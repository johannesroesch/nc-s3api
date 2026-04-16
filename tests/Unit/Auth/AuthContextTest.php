<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Tests\Unit\Auth;

use OCA\NcS3Api\Auth\AuthContext;
use OCA\NcS3Api\Exception\AccessDeniedException;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

class AuthContextTest extends TestCase {
	private function mockUser(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	public function testAuthenticatedContextHoldsUserId(): void {
		$ctx = AuthContext::authenticated($this->mockUser('alice'), AuthContext::METHOD_SIGV4);

		$this->assertTrue($ctx->authenticated);
		$this->assertSame('alice', $ctx->userId);
		$this->assertSame(AuthContext::METHOD_SIGV4, $ctx->authMethod);
		$this->assertNotNull($ctx->user);
	}

	public function testPresignedContextMethod(): void {
		$ctx = AuthContext::authenticated($this->mockUser('bob'), AuthContext::METHOD_PRESIGNED);

		$this->assertSame(AuthContext::METHOD_PRESIGNED, $ctx->authMethod);
	}

	public function testUnauthenticatedContextHasEmptyUserId(): void {
		$ctx = AuthContext::unauthenticated();

		$this->assertFalse($ctx->authenticated);
		$this->assertSame('', $ctx->userId);
		$this->assertSame(AuthContext::METHOD_NONE, $ctx->authMethod);
		$this->assertNull($ctx->user);
	}

	public function testRequireAuthenticatedPassesWhenAuthenticated(): void {
		$ctx = AuthContext::authenticated($this->mockUser('alice'), AuthContext::METHOD_SIGV4);
		$ctx->requireAuthenticated(); // must not throw
		$this->addToAssertionCount(1);
	}

	public function testRequireAuthenticatedThrowsWhenUnauthenticated(): void {
		$this->expectException(AccessDeniedException::class);
		AuthContext::unauthenticated()->requireAuthenticated();
	}
}
