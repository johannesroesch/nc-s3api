<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	public function __construct(
		private IL10N $l,
		private IURLGenerator $url,
	) {
	}

	public function getID(): string {
		return 'nc_s3api';
	}

	public function getName(): string {
		return $this->l->t('S3 API Gateway');
	}

	public function getPriority(): int {
		return 75;
	}

	public function getIcon(): string {
		return $this->url->imagePath('nc_s3api', 'app-dark.svg');
	}
}
