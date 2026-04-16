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
 * @template-extends QBMapper<ObjectTag>
 */
class ObjectTagMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 's3api_object_tags', ObjectTag::class);
	}

	/** @return list<ObjectTag> */
	public function findByObject(string $userId, string $bucket, string $objectKey): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('bucket', $qb->createNamedParameter($bucket)))
			->andWhere($qb->expr()->eq('object_key', $qb->createNamedParameter($objectKey)));
		return $this->findEntities($qb);
	}

	public function deleteByObject(string $userId, string $bucket, string $objectKey): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('bucket', $qb->createNamedParameter($bucket)))
			->andWhere($qb->expr()->eq('object_key', $qb->createNamedParameter($objectKey)));
		$qb->executeStatement();
	}
}
