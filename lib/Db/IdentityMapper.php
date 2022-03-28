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
use OCP\IUserManager;

/**
 * This class manages OIDC identity entries
 *
 * @package OCA\CesnetOpenIdConnect\Db
 */
class IdentityMapper extends Mapper
{
    /** @var IDBConnection */
    protected $db;
    private $appName;
    /** @var array */
    private $logCtx;
    /** @var ILogger */
    private $logger;
    /** @var IUserManager */
    private $userManager;

    public function __construct(
        $appName, ILogger $logger, IDBConnection $db, IUserManager $userManager
    )
    {
        parent::__construct($db, 'oidc_users_mapping');
        $this->appName = $appName;
        $this->logger = $logger;
        $this->db = $db;
        $this->userManager = $userManager;
        $this->logCtx = array('app' => $this->appName);
    }

    /**
     * Returns an identity associated with the OC user
     *
     * @param string $ocUid OC userid
     *
     * @return Identity|null identity associated with an OC userid
     */
    public function getIdentityForOCUser($ocUid)
    {
        if (!$this->userManager->userExists($ocUid)) {
            $this->logger->error(
                sprintf('OC user %s doesn\'t exist', $ocUid),
                $this->logCtx
            );
        }
        $sql = sprintf('SELECT * FROM `%s` WHERE `oc_userid`=?', $this->getTableName());
        return $this->getSingleIdentity($sql, [$ocUid]);
    }

    /**
     * Returns a single identity
     *
     * @param string $sql query to be performed
     * @param string $args query args
     *
     * @return Identity|null identity DB entity
     * or null if not found
     */
    public function getSingleIdentity($sql, $args)
    {
        try {
            $identity = $this->findEntity($sql, $args);
        } catch (DoesNotExistException $e) {
            $this->logger->warning(
                'Identity for: ' . array_pop($args)
                . ' not found.', $this->logCtx
            );
            return null;
        } catch (MultipleObjectsReturnedException $e) {
            $this->logger->error(
                'There are multiple identities for:'
                . array_pop($args), $this->logCtx
            );
            return null;
        }
        return $identity;
    }

    /**
     * Find identities by its nickname
     *
     * @param string $nickname search
     * @param int $limit the maximum number of returned rows
     * @param int $offset from which row we want to start
     *
     * @return array(Identity) identities found
     */
    public function findIdentities($nickname = '', $limit = null, $offset = null)
    {
        $sql = sprintf('SELECT * FROM `%s` WHERE LOWER(`nickname`) = LOWER(?)',
            $this->getTableName());
        return $this->findEntities($sql, [$nickname], $limit, $offset);
    }

    /**
     * Find OC uids having last_seen beyond an expiration treshold
     *
     * @param int $expirationTreshold timestamp
     *
     * @return array(string) expired OC uids
     */
    public function findExpired($expirationTreshold)
    {
        $sql = sprintf('SELECT `oc_userid` FROM `%s` GROUP BY `oc_userid`'
            . ' HAVING MAX(`last_seen`) <= ?',
            $this->getTableName());
        $result = $this->findEntities($sql, [$expirationTreshold]);
        return array_unique(array_map(function ($id) {
            return $id->getOcUserid();
        }, $result));
    }

    /**
     * Returns an OC account ID assigned with the OIDC userid
     *
     * @param string $oidcUid OIDC userid
     *
     * @return string|false owncloud user id or false
     * if mapping to oc uid doesn't exist or is invalid
     */
    public function getOcUserID($oidcUid)
    {
        $identity = $this->getIdentityForOIDCUser($oidcUid);
        if ($identity) {
            return $identity->getOcUserid();
        } else {
            return false;
        }
    }

    /**
     * Returns an identity by its OIDC userid
     *
     * @param string $oidcUid OIDC userid
     *
     * @return Identity|null identity associated with an OIDC userid
     */
    public function getIdentityForOIDCUser($oidcUid)
    {
        if (!$oidcUid || $oidcUid === '') {
            return null;
        }

        $sql = sprintf('SELECT * FROM `%s` WHERE `oidc_userid` = ?', $this->getTableName());
        return $this->getSingleIdentity($sql, [$oidcUid]);
    }

    /**
     * Adds a new SAML -> OC identity mapping
     *
     * @param string $oidcUid OIDC uid of the user
     * @param string $ocUid OC user account ID
     * @param string $nickname nickname of the user
     * @param int $lastSeen timestamp of last login
     *
     * @return null
     */
    public function addIdentity($oidcUid, $ocUid, $nickname, $lastSeen)
    {
        if (!$oidcUid || $oidcUid === '') {
            $this->logger->error(
                'Cannot add OIDC identity with'
                . ' empty OIDC userid.', $this->logCtx
            );
            return;
        }
        $identity = new Identity();
        $identity->setOidcUserid($oidcUid);
        $identity->setOcUserid($ocUid);
        $identity->setNickname($nickname);
        $identity->setLastSeen($lastSeen);
        $this->logger->info(
            sprintf(
                'Creating identity mapping: %s -> %s.', $oidcUid, $ocUid
            ), $this->logCtx
        );
        try {
            $this->insert($identity);
        } catch (UniqueConstraintViolationException $e) {
            $this->logger->error(
                sprintf(
                    'Failed to create mapping:'
                    . ' %s -> %s. Mapping for this OIDC identity'
                    . ' already exists.', $oidcUid, $ocUid
                ), $this->logCtx
            );
        }
    }
}
