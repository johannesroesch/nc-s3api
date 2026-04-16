<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Auth;

use OCP\IUser;

/**
 * Immutable value object representing an authenticated (or unauthenticated) caller.
 */
final class AuthContext {
	public const METHOD_SIGV4 = 'sigv4';
	public const METHOD_PRESIGNED = 'presigned';
	public const METHOD_NONE = 'none';

	private function __construct(
		public readonly bool $authenticated,
		public readonly string $userId,
		public readonly string $authMethod,
		public readonly ?IUser $user = null,
	) {
	}

	public static function authenticated(IUser $user, string $method): self {
		return new self(true, $user->getUID(), $method, $user);
	}

	public static function unauthenticated(): self {
		return new self(false, '', self::METHOD_NONE);
	}

	public function requireAuthenticated(): void {
		if (!$this->authenticated) {
			throw new \OCA\NcS3Api\Exception\AccessDeniedException('Authentication required');
		}
	}
}
