<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
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

use OC\HintException;
use OC\User\LoginException;
use OCA\CesnetOpenIdConnect\Client;
use OCA\CesnetOpenIdConnect\Db\IdentityMapper;
use OCP\IUser;
use OCP\IUserManager;
use OCP\ILogger;

class UserLookupService {

	/**
	 * @var IUserManager
	 */
	private $userManager;
	/**
	 * @var Client
	 */
	private $client;
	/**
	 * @var AutoProvisioningService
	 */
	private $autoProvisioningService;
	/**
	 * @var ILogger
	 */
	private $logger;
    /**
     * @var IdentityMapper
     */
    private $idMapper;

	public function __construct(
		IUserManager $userManager,
		Client $client,
		AutoProvisioningService $autoProvisioningService,
		ILogger $logger,
		IdentityMapper $idMapper
	) {
		$this->userManager = $userManager;
		$this->client = $client;
		$this->autoProvisioningService = $autoProvisioningService;
		$this->logger = $logger;
		$this->idMapper = $idMapper;
	}

	/**
	 * @param mixed $userInfo
	 * @return IUser
	 * @throws LoginException
	 * @throws HintException
	 */
	public function lookupUser($userInfo): IUser {
		$openIdConfig = $this->client->getOpenIdConfig();
		if ($openIdConfig === null) {
			throw new HintException('Configuration issue in openidconnect app');
		}
		$searchByEmail = true;
		if ($this->client->mode() === 'userid') {
			$searchByEmail = false;
		}
		$attribute = $this->client->getIdentityClaim();
		if (!\property_exists($userInfo, $attribute)) {
			throw new LoginException("Configured attribute $attribute is not known.");
		}

		if ($searchByEmail) {
			$user = $this->userManager->getByEmail($userInfo->$attribute);
			if (!$user) {
				if ($this->autoProvisioningService->autoProvisioningEnabled()) {
					return $this->autoProvisioningService->createUser($userInfo);
				}

				throw new LoginException("User with {$userInfo->$attribute} is not known.");
			}
			if (\count($user) !== 1) {
				throw new LoginException("{$userInfo->$attribute} is not unique.");
			}
			$this->validUser($user[0]);
			return $user[0];
		}

		$stripUserIdDomain = $this->client->getAutoProvisionConfig()['strip-userid-domain'] ?? false;
		$userName = $stripUserIdDomain? \strstr($userInfo->$attribute, '@', true) : $userInfo->$attribute;

		$user = $this->userManager->get($userName);
		if (!$user) {
			$this->logger->debug('UserLookupService::lookupUser : looking up mapping for: ' . $userInfo->$attribute);
			$userId = $this->idMapper->getOcUserID($userInfo->$attribute);
			if ($userId) {
				$user = $this->userManager->get($userId);
				$this->logger->info(sprintf('UserLookupService::lookupUser : mapped: %s to %s',  $userInfo->$attribute, $user->getUID()));
			}
			if (!$user) {
				if ($this->autoProvisioningService->autoProvisioningEnabled()) {
					return $this->autoProvisioningService->createUser($userInfo);
				}
				throw new LoginException("User {$userInfo->$attribute} is not known.");
			}
		}
		$this->validUser($user);
		return $user;
	}

	private function validUser(IUser $user): void {
		$openIdConfig = $this->client->getOpenIdConfig();
		$allowedUserBackEnds = $openIdConfig['allowed-user-backends'] ?? null;
		if ($allowedUserBackEnds === null) {
			return;
		}
		if (\in_array($user->getBackendClassName(), $allowedUserBackEnds, true)) {
			return;
		}
		throw new LoginException("User is from wrong user backend <{$user->getBackendClassName()}>");
	}
}
