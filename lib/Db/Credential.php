<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Admin-managed or user-managed credential record.
 *
 * DB columns (s3api_credentials):
 *   access_key, secret_key_hash, nc_user_id, label, created_at
 *
 * @method string getAccessKey()
 * @method void setAccessKey(string $v)
 * @method string getSecretKeyHash()
 * @method void setSecretKeyHash(string $v)
 * @method string getNcUserId()
 * @method void setNcUserId(string $v)
 * @method ?string getLabel()
 * @method void setLabel(?string $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 */
class Credential extends Entity {
	protected string $accessKey = '';
	protected string $secretKeyHash = '';  // plaintext secret stored here (name kept for compat; TODO: encrypt via NC ISecret)
	protected string $ncUserId = '';
	protected ?string $label = null;
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('createdAt', 'integer');
	}

	/** Alias used by SettingsController and AuthService */
	public function getUserId(): string {
		return $this->ncUserId;
	}

	public function setUserId(string $userId): void {
		$this->ncUserId = $userId;
		$this->markFieldUpdated('ncUserId');
	}

	/** Alias: secret is stored in secretKeyHash column */
	public function getSecretKey(): string {
		return $this->secretKeyHash;
	}

	public function setSecretKey(string $secret): void {
		$this->secretKeyHash = $secret;
		$this->markFieldUpdated('secretKeyHash');
	}
}
