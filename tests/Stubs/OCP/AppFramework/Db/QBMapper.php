<?php

declare(strict_types=1);

namespace OCP\AppFramework\Db;

/**
 * Test stub for OCP\AppFramework\Db\QBMapper.
 * Provides just enough interface for PHPUnit to mock subclasses.
 */
abstract class QBMapper {
	public function __construct(
		protected mixed $db,
		protected string $tableName,
		protected ?string $entityClass = null,
	) {
	}

	public function getTableName(): string {
		return $this->tableName;
	}

	protected function findEntities(mixed $qb): array {
		return [];
	}

	protected function findEntity(mixed $qb): mixed {
		throw new DoesNotExistException('');
	}

	public function insert(mixed $entity): mixed {
		return $entity;
	}

	public function update(mixed $entity): mixed {
		return $entity;
	}

	public function delete(mixed $entity): mixed {
		return $entity;
	}
}
