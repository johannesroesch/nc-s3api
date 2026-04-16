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
 * @template-extends QBMapper<MultipartUpload>
 */
class MultipartUploadMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 's3api_multipart_uploads', MultipartUpload::class);
	}

	/** @throws DoesNotExistException */
	public function findByUploadId(string $uploadId): MultipartUpload {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($uploadId)));
		return $this->findEntity($qb);
	}

	/** @return list<MultipartUpload> */
	public function findByUserAndBucket(string $userId, string $bucket): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('bucket', $qb->createNamedParameter($bucket)))
			->orderBy('created_at', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteByUploadId(string $uploadId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($uploadId)));
		$qb->executeStatement();
	}
}
