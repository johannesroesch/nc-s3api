<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<BucketMetadata>
 */
class BucketMetadataMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 's3api_bucket_metadata', BucketMetadata::class);
    }

    /** @throws DoesNotExistException */
    public function find(string $userId, string $bucket, string $metaKey): BucketMetadata {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('user_id',  $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('bucket',   $qb->createNamedParameter($bucket)))
           ->andWhere($qb->expr()->eq('meta_key', $qb->createNamedParameter($metaKey)));
        return $this->findEntity($qb);
    }

    public function upsert(string $userId, string $bucket, string $metaKey, mixed $value): void {
        try {
            $entity = $this->find($userId, $bucket, $metaKey);
            $entity->setMetaValue(json_encode($value, JSON_UNESCAPED_UNICODE));
            $entity->setUpdatedAt(time());
            $this->update($entity);
        } catch (DoesNotExistException) {
            $entity = new BucketMetadata();
            $entity->setUserId($userId);
            $entity->setBucket($bucket);
            $entity->setMetaKey($metaKey);
            $entity->setMetaValue(json_encode($value, JSON_UNESCAPED_UNICODE));
            $entity->setUpdatedAt(time());
            $this->insert($entity);
        }
    }

    public function delete(string $userId, string $bucket, string $metaKey): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
           ->where($qb->expr()->eq('user_id',  $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('bucket',   $qb->createNamedParameter($bucket)))
           ->andWhere($qb->expr()->eq('meta_key', $qb->createNamedParameter($metaKey)));
        $qb->executeStatement();
    }
}
