<?php

declare(strict_types=1);

namespace OCP\AppFramework\Db;

/**
 * Test stub for OCP\AppFramework\Db\Entity.
 * Provides the magic getter/setter used by all Db entities.
 */
abstract class Entity {
	protected ?int $id = null;

	private array $updatedFields = [];

	public function getId(): ?int {
		return $this->id;
	}

	public function setId(int $id): void {
		$this->id = $id;
	}

	public function markFieldUpdated(string $attribute): void {
		$this->updatedFields[$attribute] = true;
	}

	public function getUpdatedFields(): array {
		return $this->updatedFields;
	}

	public function resetUpdatedFields(): void {
		$this->updatedFields = [];
	}

	public function addType(string $attribute, string $type): void {
		// stub — no-op
	}

	public function __call(string $name, array $args): mixed {
		if (str_starts_with($name, 'set')) {
			$field = lcfirst(substr($name, 3));
			$this->$field = $args[0];
			$this->markFieldUpdated($field);
			return null;
		}
		if (str_starts_with($name, 'get')) {
			$field = lcfirst(substr($name, 3));
			return $this->$field ?? null;
		}
		throw new \BadMethodCallException("Unknown method: $name");
	}
}
