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
 * @template-extends QBMapper<Credential>
 */
class CredentialMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 's3api_credentials', Credential::class);
	}

	/** @throws DoesNotExistException */
	public function findByAccessKey(string $accessKey): Credential {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('access_key', $qb->createNamedParameter($accessKey)));
		return $this->findEntity($qb);
	}

	/** @return list<Credential> */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		return $this->findEntities($qb);
	}

	/** @return list<Credential> */
	public function findByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('nc_user_id', $qb->createNamedParameter($userId)));
		return $this->findEntities($qb);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): Credential {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		return $this->findEntity($qb);
	}
}
