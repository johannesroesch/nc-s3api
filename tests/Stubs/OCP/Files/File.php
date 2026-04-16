<?php

declare(strict_types=1);

namespace OCP\Files;

/**
 * Test stub for OCP\Files\File.
 */
interface File extends Node {
	public function getMimeType(): string;
	public function hash(string $type, bool $raw = false): string;
}
