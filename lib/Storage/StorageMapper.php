<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Storage;

/**
 * Translates S3 concepts to Nextcloud filesystem paths.
 *
 * Mapping:
 *   S3 Bucket   → /s3/<bucket>/            under the user's home
 *   S3 Key      → /s3/<bucket>/<key>        (slashes preserved)
 *   Multipart   → /s3/.uploads/<uploadId>/part-<n>
 */
final class StorageMapper {
	public const BASE_DIR = 's3';
	public const UPLOADS_DIR = 's3/.uploads';

	/** Full Nextcloud path for a bucket */
	public function bucketPath(string $bucket): string {
		return self::BASE_DIR . '/' . $bucket;
	}

	/** Full Nextcloud path for an object */
	public function objectPath(string $bucket, string $key): string {
		return self::BASE_DIR . '/' . $bucket . '/' . $key;
	}

	/** Path for a single multipart part (inside user home) */
	public function partPath(string $uploadId, int $partNumber): string {
		return self::UPLOADS_DIR . '/' . $uploadId . '/part-' . $partNumber;
	}

	/** Directory holding all parts of one upload */
	public function uploadDir(string $uploadId): string {
		return self::UPLOADS_DIR . '/' . $uploadId;
	}

	/**
	 * Derive the S3 key from a Nextcloud node path relative to the user home.
	 * E.g. "s3/mybucket/dir/file.txt" → key = "dir/file.txt", bucket = "mybucket"
	 */
	public function nodeToKey(string $relativePath): ?string {
		if (!str_starts_with($relativePath, self::BASE_DIR . '/')) {
			return null;
		}
		$rest = substr($relativePath, strlen(self::BASE_DIR) + 1);
		$slashPos = strpos($rest, '/');
		if ($slashPos === false) {
			return null; // this is the bucket folder itself
		}
		return substr($rest, $slashPos + 1);
	}

	public function nodeToBucket(string $relativePath): ?string {
		if (!str_starts_with($relativePath, self::BASE_DIR . '/')) {
			return null;
		}
		$rest = substr($relativePath, strlen(self::BASE_DIR) + 1);
		$slashPos = strpos($rest, '/');
		return $slashPos === false ? $rest : substr($rest, 0, $slashPos);
	}
}
