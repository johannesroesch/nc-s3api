<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Exception;

use OCA\NcS3Api\S3\S3ErrorCodes;

/** Base class for all S3-level errors. Carries the S3 error code. */
class S3Exception extends \RuntimeException {
	public function __construct(
		private readonly string $s3Code,
		string $message = '',
		private readonly ?string $resource = null,
		?\Throwable $previous = null,
	) {
		parent::__construct($message ?: $s3Code, 0, $previous);
	}

	public function getS3Code(): string {
		return $this->s3Code;
	}

	public function getResource(): ?string {
		return $this->resource;
	}

	public function getHttpStatus(): int {
		return S3ErrorCodes::httpStatus($this->s3Code);
	}
}
