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
 * @template-extends QBMapper<MultipartPart>
 */
class MultipartPartMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 's3api_multipart_parts', MultipartPart::class);
    }

    /** @return list<MultipartPart> ordered by part number */
    public function findByUploadId(string $uploadId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($uploadId)))
           ->orderBy('part_number', 'ASC');
        return $this->findEntities($qb);
    }

    public function upsertPart(MultipartPart $part): MultipartPart {
        // Delete existing part with same number (overwrite semantics)
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
           ->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($part->getUploadId())))
           ->andWhere($qb->expr()->eq('part_number', $qb->createNamedParameter($part->getPartNumber())));
        $qb->executeStatement();
        return $this->insert($part);
    }

    public function deleteByUploadId(string $uploadId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
           ->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($uploadId)));
        $qb->executeStatement();
    }
}
