<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Db;

use OCP\AppFramework\Db\Entity;

class MultipartUpload extends Entity {
	protected string $uploadId = '';
	protected string $bucket = '';
	protected string $objectKey = '';
	protected string $userId = '';
	protected ?string $contentType = null;
	protected ?string $metadata = null;
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('createdAt', 'integer');
	}

	public function getMetadataDecoded(): array {
		if ($this->metadata === null) {
			return [];
		}
		return json_decode($this->metadata, true) ?? [];
	}
}
