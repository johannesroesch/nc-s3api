<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class UserSettings implements ISettings {
    public function getForm(): TemplateResponse {
        return new TemplateResponse('nc_s3api', 'settings/user', []);
    }

    public function getSection(): string {
        return 'personal';
    }

    public function getPriority(): int {
        return 50;
    }
}
