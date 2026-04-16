<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\S3;

/**
 * Normalised S3 response ready to be converted to an OCP Http\Response.
 *
 * Either $xmlBody or $streamBody is set, never both.
 * A HEAD or DELETE response has neither (204 / 200 with empty body).
 */
final class S3Response {
	/**
	 * @param int $statusCode HTTP status code
	 * @param array<string,string> $headers Response headers (will be merged with defaults)
	 * @param string|null $xmlBody Serialised XML string, or null
	 * @param resource|null $streamBody File/body stream for GetObject, or null
	 */
	public function __construct(
		public readonly int $statusCode = 200,
		public readonly array $headers = [],
		public readonly ?string $xmlBody = null,
		public readonly mixed $streamBody = null,
	) {
	}

	public static function noContent(array $headers = []): self {
		return new self(statusCode: 204, headers: $headers);
	}

	public static function ok(string $xmlBody, array $headers = []): self {
		return new self(statusCode: 200, headers: $headers, xmlBody: $xmlBody);
	}

	public static function stream(mixed $stream, int $statusCode, array $headers = []): self {
		return new self(statusCode: $statusCode, headers: $headers, streamBody: $stream);
	}
}
