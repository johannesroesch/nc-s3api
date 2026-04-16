<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Db;

use OCP\AppFramework\Db\Entity;

class BucketTag extends Entity {
	protected string $userId = '';
	protected string $bucket = '';
	protected string $tagKey = '';
	protected string $tagValue = '';
}
