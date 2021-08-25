<?php
/**
 * @author Miroslav Bauer <bauer@cesnet.cz>
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

use OCA\CesnetOpenIdConnect\Db\GroupMapper;

use OC\User\LoginException;
use OCP\Http\Client\IClientService;
use OCP\IAvatarManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;
use Tobias\Urn\RFC8141\Parser;

class GroupSyncService {

	/**
	 * @var Parser
	 */
	private $urnParser;
	/**
	 * @var IUserManager
	 */
	private $userManager;
	/**
	 * @var IGroupManager
	 */
	private $groupManager;
	/**
	 * @var GroupMapper
	 */
	private $groupMapper;
	/**
	 * @var ILogger
	 */
	private $logger;
	/**
	 * @var IConfig
	 */
	private $config;

	public function __construct(IUserManager $userManager,
								IGroupManager $groupManager,
								GroupMapper $groupMapper,
								ILogger $logger,
								IConfig $config,
								Parser $urnParser) {
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->groupMapper = $groupMapper;
		$this->logger = $logger;
		$this->config = $config;
		$this->urnParser = $urnParser;
	}

	public function syncUserGroups($user, $userInfo) {
		if (!$this->enabled()) {
			throw new LoginException('Group sync is disabled.');
		}

		$groupsClaim = $this->groupsClaim();
		if (!$groupsClaim) {
			throw new LoginException('Groups claim must be configured for group sync.');
		}
		$groups = $userInfo->$groupsClaim;
		foreach ($groups as $groupUrn) {
			$groupAttrs = $this->urnParser->parse('urn:' . substr($groupUrn,4));
			$gNS = $groupAttrs->getNamespaceIdentifier();
			$gNSS = $groupAttrs->getNamespaceSpecificString();
			$gRQF = $groupAttrs->getRQF();

			if (substr($gNSS, 0, strlen($this->groupsRealm())+1) === $this->groupsRealm() . ':') {
				$gNSS = substr($gNSS, strlen($this->groupsRealm())+1);
				if (substr($gNSS, 0, strlen('group:')) === 'group:') {
					$gid = substr($gNSS, strlen('group:')+1);
					$this->logger->debug("Parsed group data: (NS: $gNS NSS: $gid)");

					$group = $this->groupMapper->getGroupID($gid);
					$g = $this->groupManager->get($group);
					if ($g) {
						$g->addUser($user);
					}
				}
			} else {
				$this->logger->debug("Skipping group " . $gNSS);
			}
		}
	}
	public function getOpenIdConfiguration(): array {
		return $this->config->getSystemValue('openid-connect', null) ?? [];
	}

	public function enabled(): bool {
		return $this->getOpenIdConfiguration()['group-sync']['enabled'] ?? false;
	}

	private function groupsClaim() {
		return $this->getOpenIdConfiguration()['group-sync']['groups-claim'] ?? 'eduperson_entitlement_extended';
	}

	private function groupsRealm() {
		return $this->getOpenIdConfiguration()['group-sync']['groups-realm'] ?? 'cesnet.cz';
	}
}
