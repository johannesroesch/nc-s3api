<?php

declare(strict_types=1);

namespace OCP\Files;

/**
 * Test stub for OCP\Files\Node.
 */
interface Node {
	public function delete(): void;
	public function fopen(string $mode): mixed;
	public function getName(): string;
	public function getPath(): string;
	public function getMtime(): int;
	public function getSize(bool $includeMounts = true): int|float;
	public function copy(string $targetPath): Node;
}
