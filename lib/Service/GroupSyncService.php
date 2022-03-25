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
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;
use Tobias\Urn\Exception\ParserException;
use Tobias\Urn\RFC8141\Parser;

class GroupSyncService
{

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

	public function __construct(IUserManager  $userManager,
								IGroupManager $groupManager,
								GroupMapper   $groupMapper,
								ILogger       $logger,
								IConfig       $config,
								Parser        $urnParser)
	{
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->groupMapper = $groupMapper;
		$this->logger = $logger;
		$this->config = $config;
		$this->urnParser = $urnParser;
	}

	public function syncUserGroups($user, $userInfo)
	{
		if (!$this->enabled()) {
			throw new LoginException('Group sync is disabled.');
		}
		if (!$user || !$userInfo) {
			throw new LoginException('User data is missing.');
		}

		$externalGroups = array();

		// Add user to newly added groups
		foreach ($this->groupURNs($userInfo) as $groupURN) {
			// We expect a valid URN to be: urn:$gNS:$gNSS?=$gRQF
			try {
				$groupAttrs = $this->urnParser->parse('urn:' . substr($groupURN, 4));
			} catch (ParserException $e) {
				$this->logger->warning($groupURN . " is not a valid RFC8141 URN: " . $e->getMessage());
				continue;
			}
			$gNS = $groupAttrs->getNamespaceIdentifier();
			$gNSS = $groupAttrs->getNamespaceSpecificString();
			$gRQF = $groupAttrs->getRQF();

			// Check that group namespace and realm matches the configured values, skip it otherwise
			if (($gNS->toString() === $this->groupsNS()) &&
				(substr($gNSS, 0, strlen($this->groupsRealm()) + 1) === $this->groupsRealm() . ':')) {
				$gNSS = substr($gNSS, strlen($this->groupsRealm()) + 1);

				/**
				 * Try po parse group UUID from the 'group:' attribute following the realm field in $gNSS.
				 * Skip everything else not starting with this attribute.
				 *
				 * If we find an ownCloud group mapping for a given group UUID, make sure
				 * the user is a member of the target ownCloud group.
				 **/
				if (substr($gNSS, 0, strlen('group:')) === 'group:') {
					$gid = substr($gNSS, strlen('group:'));
					$this->logger->debug("Parsed group data: (NS: $gNS NSS: $gid)");

					$group = $this->groupMapper->getGroupID($gid);
					if (in_array($group, $this->protectedGroups(), true)) {
						$this->logger->warning("Group: " . $group . " is PROTECTED. Not adding...");
						continue;
					}

					$g = $this->groupManager->get($group);
					if ($g) {
						$externalGroups[] = $g;
						if (!$g->inGroup($user)) {
							$this->logger->info("Adding: " . $user->getUID() . " to: " . $g->getGID());
							$g->addUser($user);
						}
					} else {
						$this->logger->warning("Group " . $gid . "(" . $groupURN . ") doesn't exist. Skipping...");
					}
				}
			} else {
				$this->logger->debug("Skipping group " . $gNS->toString() . ':' . $gNSS);
			}
		}

		$internalGroups = array_map(array($this, 'getGroup'), $this->groupManager->getUserGroupIds($user));

		/**
		 * Compare the current groups the user is a member of with the external groups coming from the groupsClaim.
		 * At this point, the user is already a member of all groups from the current external groups list, plus
		 * any groups before the sync was run.
		 *
		 * Remove user from groups that are no longer present in the external groups list.
		 **/
		$groupsToRemoveFrom = array_udiff($internalGroups, $externalGroups, array($this, 'compareGroups'));
		foreach ($groupsToRemoveFrom as $g) {
			if (in_array($g->getGID(), $this->protectedGroups(), true)) {
				$this->logger->warning("Group: " . $g->getGID() . " is PROTECTED. Not removing...");
				continue;
			}
			$this->logger->info("Removing: " . $user->getUID() . " from: " . $g->getGID());
			$g->removeUser($user);
		}
	}

	public function enabled(): bool
	{
		return $this->getOpenIdConfiguration()['group-sync']['enabled'] ?? false;
	}

	public function getOpenIdConfiguration(): array
	{
		return $this->config->getSystemValue('openid-connect', null) ?? [];
	}

	public function groupURNs($userInfo): array
	{
		$groupsClaim = $this->groupsClaim();
		if (!$groupsClaim) {
			throw new LoginException('Groups claim must be configured for group sync.');
		}
		return $userInfo->$groupsClaim;
	}

	private function groupsClaim()
	{
		return $this->getOpenIdConfiguration()['group-sync']['groups-claim'] ?? 'eduperson_entitlement_extended';
	}

	private function groupsNS()
	{
		return $this->getOpenIdConfiguration()['group-sync']['groups-namespace'] ?? 'geant';
	}

	private function groupsRealm()
	{
		return $this->getOpenIdConfiguration()['group-sync']['groups-realm'] ?? 'cesnet.cz';
	}

	private function getGroup($g): IGroup
	{
		return $this->groupManager->get($g);
	}

	private function compareGroups($a, $b): int
	{
		if ($a instanceof IGroup) {
			$a = $a->getGID();
		}
		if ($b instanceof IGroup) {
			$b = $b->getGID();
		}

		if ($a == $b) {
			return 0;
		} elseif ($a > $b) {
			return 1;
		} else {
			return -1;
		}
	}

	private function protectedGroups(): array
	{
		return $this->getOpenIdConfiguration()['group-sync']['protected-groups'] ?? array('admin');
	}
}