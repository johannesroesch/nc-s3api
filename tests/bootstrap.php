<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

// Load the standard Composer autoloader first
require_once __DIR__ . '/../vendor/autoload.php';

// Register test stubs with highest priority (prepend: true).
// These intercept OCP\* classes BEFORE the nextcloud/ocp package is consulted,
// allowing lightweight stub versions to be used instead of the real NC classes
// which have deep transitive dependencies not available in test scope.
spl_autoload_register(static function (string $class): void {
	if (!str_starts_with($class, 'OCP\\') && !str_starts_with($class, 'NCU\\')) {
		return;
	}
	$relative = str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
	// Try stubs directory first
	$stubPath = __DIR__ . '/Stubs/' . $relative;
	if (is_file($stubPath)) {
		require_once $stubPath;
		return;
	}
	// Fall back to real nextcloud/ocp package
	$ocpPath = __DIR__ . '/../vendor/nextcloud/ocp/' . $relative;
	if (is_file($ocpPath)) {
		require_once $ocpPath;
	}
}, prepend: true);
