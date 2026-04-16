<?php

declare(strict_types=1);

namespace OCP\Files;

/**
 * Test stub for OCP\Files\Node.
 */
interface Node {
	public function delete(): void;
	public function fopen(string $mode): mixed;
}
