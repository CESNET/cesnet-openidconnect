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

namespace OCA\CesnetOpenIdConnect\Tests\Unit;

use OCA\CesnetOpenIdConnect\Client;
use OCP\IConfig;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\ILogger;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class ClientTest extends TestCase {

	/**
	 * @var MockObject | Client
	 */
	private $client;
	/**
	 * @var MockObject | ISession
	 */
	private $session;
	/**
	 * @var MockObject | IURLGenerator
	 */
	private $urlGenerator;
	/**
	 * @var MockObject | ILogger
	 */
	private $logger;
	/**
	 * @var MockObject | IConfig
	 */
	private $config;

	public function providesGetUserInfoData(): array {
		return [
			'access-token' => [true],
			'user-info-endpoint' => [false],
		];
	}

	public function appConfigProvider(): \Generator {
		yield 'invalid json' => ['from system config', '{[s', 'Loaded config from DB is not valid (malformed JSON); JSON Last Error: 4'];
		yield 'empty app config' => ['from system config', ''];
		yield 'empty array' => [[], '[]'];
		yield 'json object' => [['foo' => 'bar'], '{"foo": "bar"}'];
	}

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->session = $this->createMock(ISession::class);
		$this->logger = $this->createMock(ILogger::class);

		$this->client = $this->getMockBuilder(Client::class)
			->setConstructorArgs([$this->config, $this->urlGenerator, $this->session, $this->logger])
			->setMethods(['fetchURL'])
			->getMock();
	}

	public function testGetConfig(): void {
		$this->config->expects(self::once())->method('getSystemValue')->willReturn('foo');
		$return = $this->client->getOpenIdConfig();
		self::assertEquals('foo', $return);
	}

	/**
	 * @dataProvider appConfigProvider
	 */
	public function testGetAppConfig($expectedData, $dataInConfig, $expectedErrorMessage = null): void {
		$this->config->method('getSystemValue')->willReturn('from system config');
		$this->config->expects(self::once())->method('getAppValue')->willReturnCallback(function ($app, $key, $default) use ($dataInConfig) {
			return $dataInConfig;
		});
		if ($expectedErrorMessage) {
			$this->logger->expects(self::once())->method('error')->with($expectedErrorMessage);
		}
		$return = $this->client->getOpenIdConfig();
		self::assertEquals($expectedData, $return);
	}

	public function testGetWellKnown(): void {
		$this->client->setProviderURL('https://example.net');
		$this->client->expects(self::once())->method('fetchURL')->with('https://example.net/.well-known/openid-configuration')->willReturn('{"foo": "bar"}');
		$return = $this->client->getWellKnownConfig();
		self::assertEquals((object)['foo' => 'bar'], $return);
	}

	public function testCtor(): void {
		$providerUrl = 'https://example.net';

		$this->config->method('getSystemValue')->willReturnCallback(static function ($key) use ($providerUrl) {
			if ($key === 'openid-connect') {
				return [
					'provider-url' => $providerUrl,
					'client-id' => 'client-id',
					'client-secret' => 'secret',
					'scopes' => ['openid', 'profile'],
					'provider-params' => ['bar'],
					'auth-params' => ['foo'],
				];
			}
			if ($key === 'proxy') {
				return null;
			}
			if ($key === 'proxyuserpwd') {
				return null;
			}
			throw new \InvalidArgumentException("Unexpected key: $key");
		});
		$this->client = $this->getMockBuilder(Client::class)
			->setConstructorArgs([$this->config, $this->urlGenerator, $this->session, $this->logger])
			->setMethods(['fetchURL'])
			->getMock();

		self::assertEquals($providerUrl, $this->client->getProviderURL());
		self::assertEquals(true, $this->client->getVerifyHost());
		self::assertEquals(true, $this->client->getVerifyPeer());
	}

	public function testCtorInsecure(): void {
		$providerUrl = 'https://example.net';

		$this->config->method('getSystemValue')->willReturnCallback(static function ($key) use ($providerUrl) {
			if ($key === 'openid-connect') {
				return [
					'provider-url' => $providerUrl,
					'client-id' => 'client-id',
					'client-secret' => 'secret',
					'scopes' => ['openid', 'profile'],
					'provider-params' => ['bar'],
					'auth-params' => ['foo'],
					'insecure' => true
				];
			}
			if ($key === 'proxy') {
				return null;
			}
			if ($key === 'proxyuserpwd') {
				return null;
			}
			throw new \InvalidArgumentException("Unexpected key: $key");
		});
		$this->client = $this->getMockBuilder(Client::class)
			->setConstructorArgs([$this->config, $this->urlGenerator, $this->session, $this->logger])
			->setMethods(['fetchURL'])
			->getMock();

		self::assertEquals($providerUrl, $this->client->getProviderURL());
		self::assertEquals(false, $this->client->getVerifyHost());
		self::assertEquals(false, $this->client->getVerifyPeer());
	}

	/**
	 * @dataProvider providesGetUserInfoData
	 * @param $useAccessTokenPayloadForUserInfo
	 */
	public function testGetUserInfo($useAccessTokenPayloadForUserInfo): void {
		$this->config->method('getSystemValue')->willReturnCallback(static function ($key) use ($useAccessTokenPayloadForUserInfo) {
			if ($key === 'openid-connect') {
				return [
					'provider-url' => '$providerUrl',
					'client-id' => 'client-id',
					'client-secret' => 'secret',
					'use-access-token-payload-for-user-info' => $useAccessTokenPayloadForUserInfo
				];
			}
			if ($key === 'proxy') {
				return null;
			}
			if ($key === 'proxyuserpwd') {
				return null;
			}
			throw new \InvalidArgumentException("Unexpected key: $key");
		});

		$this->client = $this->getMockBuilder(Client::class)
			->setConstructorArgs([$this->config, $this->urlGenerator, $this->session, $this->logger])
			->onlyMethods(['requestUserInfo', 'getAccessTokenPayload'])
			->getMock();
		if ($useAccessTokenPayloadForUserInfo) {
			$this->client->expects(self::never())->method('requestUserInfo');
			$this->client->expects(self::once())->method('getAccessTokenPayload')->willReturn([
				'preferred_username' => 'alice@example.net'
			]);
		} else {
			$this->client->expects(self::never())->method('getAccessTokenPayload');
			$this->client->expects(self::once())->method('requestUserInfo')->willReturn([
				'preferred_username' => 'alice@example.net'
			]);
		}

		$info = $this->client->getUserInfo();
		self::assertEquals([
			'preferred_username' => 'alice@example.net'
		], $info);
	}
}
