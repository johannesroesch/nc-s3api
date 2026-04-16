<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int    getId()
 * @method string getToken()
 * @method void   setToken(string $token)
 * @method string getUserId()
 * @method void   setUserId(string $userId)
 * @method string getBucket()
 * @method void   setBucket(string $bucket)
 * @method string getObjectKey()
 * @method void   setObjectKey(string $objectKey)
 * @method string getMethod()
 * @method void   setMethod(string $method)
 * @method int    getExpiresAt()
 * @method void   setExpiresAt(int $expiresAt)
 * @method int    getCreatedAt()
 * @method void   setCreatedAt(int $createdAt)
 * @method bool   getUsed()
 * @method void   setUsed(bool $used)
 */
class PresignedUrl extends Entity {
    protected string $token     = '';
    protected string $userId    = '';
    protected string $bucket    = '';
    protected string $objectKey = '';
    protected string $method    = '';
    protected int    $expiresAt = 0;
    protected int    $createdAt = 0;
    protected bool   $used      = false;
}
