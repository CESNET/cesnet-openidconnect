<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Ilja Neumann <ineumann@owncloud.com>
 *
 * @copyright Copyright (c) 2020, ownCloud GmbH
 * @license GPL-2.0
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\CesnetOpenIdConnect\Service;

use OC\User\LoginException;
use OCP\Http\Client\IClientService;
use OCP\IAvatarManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;

class AutoProvisioningService {

	/**
	 * @var IUserManager
	 */
	private $userManager;
	/**
	 * @var IGroupManager
	 */
	private $groupManager;
	/**
	 * @var IAvatarManager
	 */
	private $avatarManager;
	/**
	 * @var ILogger
	 */
	private $logger;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var IClientService
	 */
	private $clientService;

	public function __construct(IUserManager $userManager,
								IGroupManager $groupManager,
								IAvatarManager $avatarManager,
								IClientService $clientService,
								ILogger $logger,
								IConfig $config) {
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->avatarManager = $avatarManager;
		$this->logger = $logger;
		$this->config = $config;
		$this->clientService = $clientService;
	}

	public function createUser($userInfo): IUser {
		if (!$this->enabled()) {
			throw new LoginException('Auto provisioning is disabled.');
		}
		$attribute = $this->identityClaim();
		$emailOrUserId = $userInfo->$attribute ?? null;
		if (!$emailOrUserId) {
			throw new LoginException("Configured attribute $attribute is not known.");
		}
		$userId = $this->mode() === 'email' ? $this->generateUserId() : $emailOrUserId;

		$openIdConfig = $this->getOpenIdConfiguration();
		$provisioningClaim = $openIdConfig['auto-provision']['provisioning-claim'] ?? null;
		if ($provisioningClaim) {
			$this->logger->debug('ProvisioningClaim is defined for auto-provision', ['claim' => $provisioningClaim]);
			$provisioningAttribute = $openIdConfig['auto-provision']['provisioning-attribute'] ?? null;

			if (!\property_exists($userInfo, $provisioningClaim) || !\is_array($userInfo->$provisioningClaim)) {
				throw new LoginException('Required provisioning attribute is not found.');
			}

			if (!\in_array($provisioningAttribute, $userInfo->$provisioningClaim, true)) {
				throw new LoginException('Required provisioning attribute is not found.');
			}
		}

		if ($this->mode() === 'email') {
			$userId = \uniqid('oidc-user-');
			$email = $emailOrUserId;
		} else {
			$stripUserIdDomain = $openIdConfig['auto-provision']['strip-userid-domain'] ?? false;

			$userId = $stripUserIdDomain ? \strstr($emailOrUserId, '@', true): $emailOrUserId;
			$email = null;
		}

		$passwd = \uniqid('', true);
		$user = $this->userManager->createUser($userId, $this->generatePassword());
		if (!$user) {
			throw new LoginException("Unable to create user $userId");
		}
		$user->setEnabled(true);

		if ($email) {
			$user->setEMailAddress($email);
		} else {
			$emailClaim = $openIdConfig['auto-provision']['email-claim'] ?? null;
			if ($emailClaim) {
				$user->setEMailAddress($userInfo->$emailClaim);
			}
		}

		$displayNameClaim = $openIdConfig['auto-provision']['display-name-claim'] ?? null;
		if ($displayNameClaim) {
			$user->setDisplayName($userInfo->$displayNameClaim);
		}
		$groups = $openIdConfig['auto-provision']['groups'] ?? [];
		foreach ($groups as $group) {
			$g = $this->groupManager->get($group);
			if ($g) {
				$g->addUser($user);
			}
		}
		$pictureClaim = $openIdConfig['auto-provision']['picture-claim'] ?? null;
		if ($pictureClaim) {
			$pictureUrl = $userInfo->$pictureClaim;
			try {
				$resource = $this->downloadPicture($pictureUrl);
				$av = $this->avatarManager->getAvatar($user->getUID());
				$av->set($resource);
			} catch (\Exception $ex) {
				$this->logger->logException($ex, [
					'message' => "Error setting profile picture $pictureUrl"
				]);
			}
		}

		return $user;
	}
	public function getOpenIdConfiguration(): array {
		return $this->config->getSystemValue('openid-connect', null) ?? [];
	}

	public function enabled(): bool {
		return $this->getOpenIdConfiguration()['auto-provision']['enabled'] ?? false;
	}

	private function mode() {
		return $this->getOpenIdConfiguration()['mode'] ?? 'userid';
	}

	private function identityClaim() {
		return $this->getOpenIdConfiguration()['search-attribute'] ?? 'email';
	}

	protected function downloadPicture(string $pictureUrl) {
		$response = $this->clientService->newClient()->get($pictureUrl);
		return $response->getBody();
	}

	private function generateUserId() {
		return 'oidc-user-'.\bin2hex(\random_bytes(16));
	}

	private function generatePassword() {
		return \bin2hex(\random_bytes(32));
	}
}
