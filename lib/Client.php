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
namespace OCA\CesnetOpenIdConnect;

use Jumbojett\OpenIDConnectClient;
use Jumbojett\OpenIDConnectClientException;
use OCP\IConfig;
use OCP\ISession;
use OCP\IURLGenerator;

class Client extends OpenIDConnectClient {

	/** @var ISession */
	private $session;
	/** @var IConfig */
	private $config;
	/** @var array */
	private $wellKnownConfig;
	/**
	 * @var IURLGenerator
	 */
	private $generator;

	/**
	 * Client constructor.
	 *
	 * @param IConfig $config
	 * @param IURLGenerator $generator
	 * @param ISession $session
	 */
	public function __construct(IConfig $config,
								IURLGenerator $generator,
								ISession $session
	) {
		$this->session = $session;
		$this->config = $config;
		$this->generator = $generator;

		$openIdConfig = $this->getOpenIdConfig();
		if ($openIdConfig === null) {
			return;
		}
		parent::__construct(
			$openIdConfig['provider-url'],
			$openIdConfig['client-id'],
			$openIdConfig['client-secret']
		);
		$scopes = $openIdConfig['scopes'] ?? ['openid', 'profile', 'email'];
		$this->addScope($scopes);

		$insecure = $openIdConfig['insecure'] ?? false;
		if ($insecure) {
			$this->setVerifyHost(false);
			$this->setVerifyPeer(false);
		}
		// set config parameters in case well known is not supported
		if (isset($openIdConfig['provider-params'])) {
			$this->providerConfigParam($openIdConfig['provider-params']);
		}
		// set additional auth parameters
		if (isset($openIdConfig['auth-params'])) {
			$this->addAuthParam($openIdConfig['auth-params']);
		}
	}

	/**
	 * @return mixed
	 */
	public function getOpenIdConfig() {
		return $this->config->getSystemValue('openid-connect', null);
	}

	/**
	 * @throws OpenIDConnectClientException
	 */
	public function getWellKnownConfig() {
		if (!$this->wellKnownConfig) {
			$well_known_config_url = \rtrim($this->getProviderURL(), '/') . '/.well-known/openid-configuration';
			$this->wellKnownConfig = \json_decode($this->fetchURL($well_known_config_url), false);
		}
		return $this->wellKnownConfig;
	}

	public function getUserInfo() {
		$openIdConfig = $this->getOpenIdConfig();
		if (isset($openIdConfig['use-access-token-payload-for-user-info']) && $openIdConfig['use-access-token-payload-for-user-info']) {
			return $this->getAccessTokenPayload();
		}

		return $this->requestUserInfo();
	}

	public function checkEligible($userInfo): bool {
		$openIdConfig = $this->getOpenIdConfig();
		if (isset($openIdConfig['eligible-timestamp-claim']) && $openIdConfig['eligible-timestamp-claim']) {
			$eligibleAttribute = $openIdConfig['eligible-timestamp-claim'];
			$eligibleTimestamp = \strtotime($userInfo->$eligibleAttribute);
			$eligibleExpiry = \strtotime($openIdConfig['eligible-expiry'] ?? '-1 year');

			if (!$eligibleTimestamp or $eligibleTimestamp < $eligibleExpiry) {
				return false;
			}
		}
		return true;
	}

	public function storeRedirectUrl(?string $redirectUrl): void {
		if ($redirectUrl) {
			$this->setSessionKey('openid_connect_redirect_url', $redirectUrl);
		}
	}

	public function readRedirectUrl(): ?string {
		return $this->getSessionKey('openid_connect_redirect_url');
	}

	/**
	 * @codeCoverageIgnore
	 */
	protected function startSession() {
	}

	/**
	 * @codeCoverageIgnore
	 */
	protected function setSessionKey($key, $value) {
		$this->session->set($key, $value);
	}

	/**
	 * @codeCoverageIgnore
	 */
	protected function getSessionKey($key) {
		return $this->session->get($key);
	}

	/**
	 * @codeCoverageIgnore
	 */
	protected function unsetSessionKey($key) {
		$this->session->remove($key);
	}

	/**
	 * @codeCoverageIgnore
	 */
	protected function commitSession() {
	}

	/**
	 * @codeCoverageIgnore
	 */
	protected function fetchURL($url, $post_body = null, $headers = []) {
		// TODO: see how to use ownCloud HttpClient ....
		return parent::fetchURL($url, $post_body, $headers);
	}

	/**
	 * @codeCoverageIgnore
	 *
	 * @return bool
	 * @throws OpenIDConnectClientException
	 */
	public function authenticate() : bool {
		$redirectUrl = $this->generator->linkToRouteAbsolute('cesnet-openidconnect.loginFlow.login');

		$openIdConfig = $this->getOpenIdConfig();
		if (isset($openIdConfig['redirect-url'])) {
			$redirectUrl = $openIdConfig['redirect-url'];
		}

		$this->setRedirectURL($redirectUrl);
		return parent::authenticate();
	}
}
