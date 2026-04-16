<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<BucketTag>
 */
class BucketTagMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 's3api_bucket_tags', BucketTag::class);
    }

    /** @return list<BucketTag> */
    public function findByBucket(string $userId, string $bucket): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('bucket', $qb->createNamedParameter($bucket)));
        return $this->findEntities($qb);
    }

    public function deleteByBucket(string $userId, string $bucket): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('bucket', $qb->createNamedParameter($bucket)));
        $qb->executeStatement();
    }
}
