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

use OCA\CesnetOpenIdConnect\Logger;
use OCA\CesnetOpenIdConnect\LoginPageBehaviour;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class LoginPageBehaviourTest extends TestCase {

	/**
	 * @var MockObject | Logger
	 */
	private $logger;
	/**
	 * @var MockObject | LoginPageBehaviour
	 */
	private $loginPageBehaviour;
	/**
	 * @var MockObject | IUserSession
	 */
	private $userSession;
	/**
	 * @var MockObject | IURLGenerator
	 */
	private $urlGenerator;
	/**
	 * @var MockObject | IRequest
	 */
	private $request;

	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(Logger::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->request = $this->createMock(IRequest::class);

		$this->loginPageBehaviour = $this->getMockBuilder(LoginPageBehaviour::class)
			->setConstructorArgs([$this->logger, $this->userSession, $this->urlGenerator, $this->request])
			->setMethods(['registerAlternativeLogin', 'redirect'])
			->getMock();
	}

	public function testLoggedIn(): void {
		$this->userSession->method('isLoggedIn')->willReturn(true);
		$this->loginPageBehaviour->expects(self::never())->method('registerAlternativeLogin');
		$this->loginPageBehaviour->handleLoginPageBehaviour([]);
	}

	public function testNotLoggedInNoAutoRedirect(): void {
		$this->userSession->method('isLoggedIn')->willReturn(false);
		$this->request->expects(self::never())->method('getRequestUri');
		$this->loginPageBehaviour->expects(self::once())->method('registerAlternativeLogin')->with('foo');
		$this->loginPageBehaviour->handleLoginPageBehaviour(['loginButtonName' => 'foo']);
	}

	public function testNotLoggedInAutoRedirect(): void {
		$this->userSession->method('isLoggedIn')->willReturn(false);
		$this->request->method('getRequestUri')->willReturn('https://example.com/login');
		$this->urlGenerator->method('linkToRoute')->willReturn('https://example.com/openid/redirect');
		$this->loginPageBehaviour->expects(self::once())->method('registerAlternativeLogin')->with('OpenID Connect');
		$this->loginPageBehaviour->expects(self::once())->method('redirect')->with('https://example.com/openid/redirect');
		$this->loginPageBehaviour->handleLoginPageBehaviour(['autoRedirectOnLoginPage' => true]);
	}

	public function testNotLoggedInAutoRedirectNoLoginPage(): void {
		$this->userSession->method('isLoggedIn')->willReturn(false);
		$this->request->method('getRequestUri')->willReturn('https://example.com/apps/files');
		$this->urlGenerator->method('linkToRoute')->willReturn('https://example.com/openid/redirect');
		$this->loginPageBehaviour->expects(self::once())->method('registerAlternativeLogin')->with('OpenID Connect');
		$this->loginPageBehaviour->expects(self::never())->method('redirect')->with('https://example.com/openid/redirect');
		$this->loginPageBehaviour->handleLoginPageBehaviour(['autoRedirectOnLoginPage' => true]);
	}
}
