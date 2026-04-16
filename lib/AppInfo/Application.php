<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\AppInfo;

use OCA\NcS3Api\Settings\AdminSection;
use OCA\NcS3Api\Settings\AdminSettings;
use OCA\NcS3Api\Settings\UserSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'nc_s3api';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerSettingsSection(AdminSection::class);
		$context->registerAdminSettings(AdminSettings::class);
		$context->registerPersonalSettings(UserSettings::class);
	}

	public function boot(IBootContext $context): void {
		// Nothing to do at boot time.
	}
}
