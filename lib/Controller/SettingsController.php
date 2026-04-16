<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Controller;

use OCA\NcS3Api\Db\Credential;
use OCA\NcS3Api\Db\CredentialMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for the settings pages.
 *
 * Admin routes  (/apps/nc_s3api/admin/credentials):
 *   GET    → list all admin-managed key-pairs
 *   POST   → create a new key-pair (body: {userId, accessKey?, label?})
 *   DELETE /{id} → delete a key-pair
 *
 * User routes  (/apps/nc_s3api/user/credentials):
 *   GET    → list the calling user's own key-pairs
 *   POST   → create a new key-pair for the calling user (body: {label?})
 *   DELETE /{id} → delete one of the calling user's key-pairs
 */
class SettingsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private CredentialMapper $credentialMapper,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	// -------------------------------------------------------------------------
	// Admin endpoints (Nextcloud framework enforces admin-only via routes.php)
	// -------------------------------------------------------------------------

	public function adminListCredentials(): JSONResponse {
		$rows = $this->credentialMapper->findAll();
		return new JSONResponse(array_map([$this, 'toPublic'], $rows));
	}

	public function adminCreateCredential(): JSONResponse {
		$userId = (string)($this->request->getParam('userId') ?? '');
		$accessKey = (string)($this->request->getParam('accessKey') ?? '');
		$secretKey = (string)($this->request->getParam('secretKey') ?? '');
		$label = (string)($this->request->getParam('label') ?? '');

		if ($userId === '' || $secretKey === '') {
			return new JSONResponse(['error' => 'userId and secretKey are required'], Http::STATUS_BAD_REQUEST);
		}

		if ($accessKey === '') {
			$accessKey = strtoupper(bin2hex(random_bytes(10)));
		}

		$credential = new Credential();
		$credential->setUserId($userId);
		$credential->setAccessKey($accessKey);
		$credential->setSecretKey($secretKey);
		$credential->setLabel($label);
		$credential->setCreatedAt(time());
		$this->credentialMapper->insert($credential);

		return new JSONResponse($this->toPublic($credential), Http::STATUS_CREATED);
	}

	public function adminDeleteCredential(int $id): JSONResponse {
		$credential = $this->credentialMapper->find($id);
		$this->credentialMapper->delete($credential);
		return new JSONResponse(['deleted' => $id]);
	}

	// -------------------------------------------------------------------------
	// User endpoints (current user only)
	// -------------------------------------------------------------------------

	#[NoAdminRequired]
	public function userListCredentials(): JSONResponse {
		$userId = $this->userSession->getUser()?->getUID() ?? '';
		$rows = $this->credentialMapper->findByUser($userId);
		return new JSONResponse(array_map([$this, 'toPublic'], $rows));
	}

	#[NoAdminRequired]
	public function userCreateCredential(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$secretKey = (string)($this->request->getParam('secretKey') ?? '');
		$label = (string)($this->request->getParam('label') ?? '');

		if ($secretKey === '') {
			return new JSONResponse(['error' => 'secretKey is required'], Http::STATUS_BAD_REQUEST);
		}

		$accessKey = strtoupper(bin2hex(random_bytes(10)));

		$credential = new Credential();
		$credential->setUserId($user->getUID());
		$credential->setAccessKey($accessKey);
		$credential->setSecretKey($secretKey);
		$credential->setLabel($label);
		$credential->setCreatedAt(time());
		$this->credentialMapper->insert($credential);

		// Return secretKey once — caller must save it
		return new JSONResponse([
			'id' => $credential->getId(),
			'userId' => $credential->getUserId(),
			'accessKey' => $credential->getAccessKey(),
			'secretKey' => $secretKey,
			'label' => $credential->getLabel(),
		], Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function userDeleteCredential(int $id): JSONResponse {
		$userId = $this->userSession->getUser()?->getUID() ?? '';
		$credential = $this->credentialMapper->find($id);

		if ($credential->getUserId() !== $userId) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$this->credentialMapper->delete($credential);
		return new JSONResponse(['deleted' => $id]);
	}

	// -------------------------------------------------------------------------

	private function toPublic(Credential $c): array {
		return [
			'id' => $c->getId(),
			'userId' => $c->getUserId(),
			'accessKey' => $c->getAccessKey(),
			'label' => $c->getLabel(),
		];
	}
}
