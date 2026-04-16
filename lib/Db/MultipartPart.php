<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Db;

use OCP\AppFramework\Db\Entity;

class MultipartPart extends Entity {
	protected string $uploadId = '';
	protected int $partNumber = 0;
	protected string $etag = '';
	protected int $size = 0;
	protected string $tmpPath = '';
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('partNumber', 'integer');
		$this->addType('size', 'integer');
		$this->addType('createdAt', 'integer');
	}
}
