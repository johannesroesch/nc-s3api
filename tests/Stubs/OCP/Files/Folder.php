<?php

declare(strict_types=1);

namespace OCP\Files;

/**
 * Test stub for OCP\Files\Folder.
 */
interface Folder extends Node {
	public function get(string $path): Node;
	public function nodeExists(string $path): bool;
	public function newFolder(string $path): Folder;
	public function newFile(string $path, mixed $content = null): File;
}
