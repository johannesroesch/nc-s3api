<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Db;

use OCP\AppFramework\Db\Entity;

class BucketMetadata extends Entity {
    protected string $userId    = '';
    protected string $bucket    = '';
    protected string $metaKey   = '';
    protected string $metaValue = '';
    protected int    $updatedAt = 0;

    public function __construct() {
        $this->addType('updatedAt', 'integer');
    }

    public function getValueDecoded(): mixed {
        return json_decode($this->metaValue, true);
    }
}
