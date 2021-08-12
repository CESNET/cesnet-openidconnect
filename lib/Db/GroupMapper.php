<?php
/**
 *
 * @author Miroslav Bauer, CESNET <bauer@cesnet.cz>
 * @copyright Miroslav Bauer, CESNET 2021
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

namespace OCA\CesnetOpenIdConnect\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Mapper;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IDBConnection;
use OCP\ILogger;
use OCP\IGroupManager;

/**
 * This class manages OIDC identity entries
 *
 * @package OCA\CesnetOpenIdConnect\Db
 */
class GroupMapper extends Mapper
{
	/** @var IDBConnection */
	protected $db;
	private $appName;
	/** @var array */
	private $logCtx;
	/** @var ILogger */
	private $logger;
	/** @var IGroupManager */
	private $groupManager;

	public function __construct(
		$appName, ILogger $logger, IDBConnection $db, IGroupManager $groupManager
	)
	{
		parent::__construct($db, 'oidc_groups_mapping');
		$this->appName = $appName;
		$this->logger = $logger;
		$this->db = $db;
		$this->groupManager = $groupManager;
		$this->logCtx = array('app' => $this->appName);
	}

	/**
	 * Returns a Group entity by its external persistent UUID
	 *
	 * @param string $oidcGroupUuid external group UUID from OIDC claim
	 *
	 * @return ?Group Group DB entity
	 * or null if not found
	 */
	public function get($oidcGroupUuid): ?Group
	{
		$sql = sprintf('SELECT * FROM `%s` WHERE `oidc_group_uuid`=?', $this->getTableName());
		$args = [$oidcGroupUuid];

		try {
			$group = $this->findEntity($sql, $args);
		} catch (DoesNotExistException $e) {
			$this->logger->warning(
				'Group mapping for: ' . array_pop($args)
				. ' not found.', $this->logCtx
			);
			return null;
		} catch (MultipleObjectsReturnedException $e) {
			$this->logger->error(
				'There are multiple group mappings for:'
				. array_pop($args), $this->logCtx
			);
			return null;
		}
		return $group->getOcGroupId();
	}


	/**
	 * Returns an ownCloud group ID associated with the external group UUID
	 *
	 * @param string $oidcGroupUuid external group UUID from OIDC claim
	 *
	 * @return ?Group ownCloud internal group associated with UUID
	 *
	 * @return ?Group Group DB entity
	 * or null if not found
	 */
	public function getGroupID($oidcGroupUuid): ?Group
	{
		$group = $this->get($oidcGroupUuid);
		if ($group) {
			return $group->getOcGroupId();
		}
		return null;
	}

	/**
	 * Adds a new group mapping
	 *
	 * @param string $oidcUid OIDC uid of the user
	 * @param string $ocUid OC user account ID
	 * @param string $nickname nickname of the user
	 * @param int $lastSeen timestamp of last login
	 *
	 * @return null
	 */
	public function addGroupMapping($oidcGroupUuid, $ocGroupId)
	{
		if (!$oidcGroupUuid || !$ocGroupId) {
			$this->logger->error(
				'Cannot add group mapping without OIDC or OC ID.', $this->logCtx
			);
			return;
		}
		$g = new Group();
		$g->setOidcGroupUuid($oidcGroupUuid);
		$g->setOcGroupId($ocGroupId);
		$this->logger->info(
			sprintf(
				'Adding group mapping: %s -> %s.', $oidcGroupUuid, $ocGroupId
			), $this->logCtx
		);
		try {
			$this->insert($g);
		} catch (UniqueConstraintViolationException $e) {
			$this->logger->error(
				sprintf(
					'Failed to create mapping:'
					. ' %s -> %s. Mapping for this group'
					. ' already exists.', $oidcGroupUuid, $ocGroupId
				), $this->logCtx
			);
		}
	}

	/**
	 * List all group mappings
	 *
	 * @param string $nickname search
	 * @param int|null $limit the maximum number of returned rows
	 * @param int|null $offset from which row we want to start
	 *
	 * @return array(Identity) identities found
	 */
	public function list(int $limit = null, int $offset = null): array
	{
		$sql = sprintf('SELECT * FROM `%s`', $this->getTableName());
		return $this->findEntities($sql, [], $limit, $offset);
	}
}
