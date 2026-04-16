<?php

declare(strict_types=1);

namespace OCP\Files;

/**
 * Test stub for OCP\Files\IRootFolder.
 */
interface IRootFolder {
	public function getUserFolder(string $userId): Folder;
}
