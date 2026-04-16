<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<PresignedUrl>
 */
class PresignedUrlMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 's3api_presigned_urls', PresignedUrl::class);
	}

	public function findByToken(string $token): PresignedUrl {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));

		return $this->findEntity($qb);
	}

	public function deleteExpired(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lte('expires_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
