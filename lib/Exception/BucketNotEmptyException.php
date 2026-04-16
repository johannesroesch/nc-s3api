<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Exception;

use OCA\NcS3Api\S3\S3ErrorCodes;

class BucketNotEmptyException extends S3Exception {
	public function __construct(string $bucket) {
		parent::__construct(S3ErrorCodes::BUCKET_NOT_EMPTY, "Bucket '$bucket' is not empty", "/$bucket");
	}
}
