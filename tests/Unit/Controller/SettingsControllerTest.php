<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Tests\Unit\Controller;

use OCA\NcS3Api\Controller\SettingsController;
use OCA\NcS3Api\Db\Credential;
use OCA\NcS3Api\Db\CredentialMapper;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SettingsControllerTest extends TestCase {
	private CredentialMapper&MockObject $credentialMapper;
	private IRequest&MockObject $request;
	private IUserSession&MockObject $userSession;
	private SettingsController $controller;

	protected function setUp(): void {
		$this->credentialMapper = $this->createMock(CredentialMapper::class);
		$this->request          = $this->createMock(IRequest::class);
		$this->userSession      = $this->createMock(IUserSession::class);

		$this->controller = new SettingsController(
			'nc_s3api',
			$this->request,
			$this->credentialMapper,
			$this->userSession,
		);
	}

	private function makeCredential(int $id, string $userId, string $accessKey, ?string $label = null): Credential {
		$c = new Credential();
		$c->setId($id);
		$c->setUserId($userId);
		$c->setAccessKey($accessKey);
		$c->setSecretKey('secret');
		$c->setLabel($label);
		$c->setCreatedAt(time());
		return $c;
	}

	private function mockUser(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	// -------------------------------------------------------------------------
	// adminListCredentials
	// -------------------------------------------------------------------------

	public function testAdminListCredentialsReturnsAllRows(): void {
		$this->credentialMapper->method('findAll')->willReturn([
			$this->makeCredential(1, 'alice', 'KEY1', 'CI'),
			$this->makeCredential(2, 'bob',   'KEY2', null),
		]);

		$response = $this->controller->adminListCredentials();

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertCount(2, $data);
		$this->assertSame('alice', $data[0]['userId']);
		$this->assertSame('KEY1',  $data[0]['accessKey']);
		$this->assertSame('CI',    $data[0]['label']);
		// secretKey must NOT be exposed
		$this->assertArrayNotHasKey('secretKey',     $data[0]);
		$this->assertArrayNotHasKey('secretKeyHash', $data[0]);
	}

	public function testAdminListCredentialsEmpty(): void {
		$this->credentialMapper->method('findAll')->willReturn([]);

		$response = $this->controller->adminListCredentials();
		$this->assertSame([], $response->getData());
	}

	// -------------------------------------------------------------------------
	// adminCreateCredential
	// -------------------------------------------------------------------------

	public function testAdminCreateCredentialInsertsAndReturns201(): void {
		$this->request->method('getParam')->willReturnMap([
			['userId',    null, 'alice'],
			['accessKey', null, 'MYKEY'],
			['secretKey', null, 'supersecret'],
			['label',     null, 'test'],
		]);

		$capturedEntity = null;
		$this->credentialMapper->expects($this->once())->method('insert')
			->willReturnCallback(function (Credential $c) use (&$capturedEntity) {
				$capturedEntity = $c;
				return $c;
			});

		$response = $this->controller->adminCreateCredential();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('alice',  $capturedEntity->getUserId());
		$this->assertSame('MYKEY',  $capturedEntity->getAccessKey());
		$this->assertGreaterThan(0, $capturedEntity->getCreatedAt()); // createdAt set
	}

	public function testAdminCreateCredentialAutoGeneratesAccessKey(): void {
		$this->request->method('getParam')->willReturnMap([
			['userId',    null, 'alice'],
			['accessKey', null, ''],      // empty → auto-generate
			['secretKey', null, 'supersecret'],
			['label',     null, ''],
		]);

		$capturedEntity = null;
		$this->credentialMapper->method('insert')
			->willReturnCallback(function (Credential $c) use (&$capturedEntity) {
				$capturedEntity = $c;
				return $c;
			});

		$this->controller->adminCreateCredential();

		$this->assertNotEmpty($capturedEntity->getAccessKey());
		$this->assertNotSame('', $capturedEntity->getAccessKey());
	}

	public function testAdminCreateCredentialMissingUserIdReturnsBadRequest(): void {
		$this->request->method('getParam')->willReturnMap([
			['userId',    null, ''],
			['accessKey', null, ''],
			['secretKey', null, 'secret'],
			['label',     null, ''],
		]);

		$response = $this->controller->adminCreateCredential();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testAdminCreateCredentialMissingSecretReturnsBadRequest(): void {
		$this->request->method('getParam')->willReturnMap([
			['userId',    null, 'alice'],
			['accessKey', null, ''],
			['secretKey', null, ''],
			['label',     null, ''],
		]);

		$response = $this->controller->adminCreateCredential();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// -------------------------------------------------------------------------
	// adminDeleteCredential
	// -------------------------------------------------------------------------

	public function testAdminDeleteCredentialDeletesAndReturnsId(): void {
		$cred = $this->makeCredential(7, 'alice', 'KEY7');
		$this->credentialMapper->method('find')->with(7)->willReturn($cred);
		$this->credentialMapper->expects($this->once())->method('delete')->with($cred);

		$response = $this->controller->adminDeleteCredential(7);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(7, $response->getData()['deleted']);
	}

	// -------------------------------------------------------------------------
	// userListCredentials
	// -------------------------------------------------------------------------

	public function testUserListCredentialsReturnsOwnRows(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
		$this->credentialMapper->method('findByUser')->with('alice')->willReturn([
			$this->makeCredential(3, 'alice', 'ALICEKEY', 'My backup'),
		]);

		$response = $this->controller->userListCredentials();

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertCount(1, $data);
		$this->assertSame('ALICEKEY', $data[0]['accessKey']);
		$this->assertArrayNotHasKey('secretKey', $data[0]);
	}

	// -------------------------------------------------------------------------
	// userCreateCredential
	// -------------------------------------------------------------------------

	public function testUserCreateCredentialInsertsAndReturnsSecretOnce(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
		$this->request->method('getParam')->willReturnMap([
			['secretKey', null, 'my-strong-secret'],
			['label',     null, 'Backup job'],
		]);

		$capturedEntity = null;
		$this->credentialMapper->method('insert')
			->willReturnCallback(function (Credential $c) use (&$capturedEntity) {
				$c->setId(99);
				$capturedEntity = $c;
				return $c;
			});

		$response = $this->controller->userCreateCredential();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		$this->assertArrayHasKey('secretKey', $data);  // secret returned exactly once
		$this->assertSame('my-strong-secret', $data['secretKey']);
		$this->assertNotEmpty($data['accessKey']);      // auto-generated
		$this->assertGreaterThan(0, $capturedEntity->getCreatedAt()); // createdAt set
	}

	public function testUserCreateCredentialMissingSecretReturnsBadRequest(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
		$this->request->method('getParam')->willReturnMap([
			['secretKey', null, ''],
			['label',     null, ''],
		]);

		$response = $this->controller->userCreateCredential();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUserCreateCredentialUnauthenticatedReturnsUnauthorized(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->request->method('getParam')->willReturnMap([
			['secretKey', null, 'secret'],
			['label',     null, ''],
		]);

		$response = $this->controller->userCreateCredential();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	// -------------------------------------------------------------------------
	// userDeleteCredential
	// -------------------------------------------------------------------------

	public function testUserDeleteCredentialDeletesOwnRow(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
		$cred = $this->makeCredential(5, 'alice', 'KEY5');
		$this->credentialMapper->method('find')->with(5)->willReturn($cred);
		$this->credentialMapper->expects($this->once())->method('delete')->with($cred);

		$response = $this->controller->userDeleteCredential(5);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(5, $response->getData()['deleted']);
	}

	public function testUserDeleteCredentialCannotDeleteOtherUsersRow(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
		$cred = $this->makeCredential(6, 'bob', 'KEY6'); // belongs to 'bob'
		$this->credentialMapper->method('find')->with(6)->willReturn($cred);
		$this->credentialMapper->expects($this->never())->method('delete');

		$response = $this->controller->userDeleteCredential(6);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}
}
