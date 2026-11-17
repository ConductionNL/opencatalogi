<?php

/**
 * Unit tests for DocumentsoortenController.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\DocumentsoortenController;
use OCA\OpenCatalogi\Service\TooiVocabularyService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DocumentsoortenController.
 */
class DocumentsoortenControllerTest extends TestCase {

	private IUserSession|MockObject $userSession;
	private DocumentsoortenController $controller;

	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new DocumentsoortenController(
			'opencatalogi',
			$this->createMock(IRequest::class),
			$l10n,
			$this->userSession,
			new TooiVocabularyService()
		);

	}//end setUp()

	/**
	 * Authenticate the session as $uid.
	 */
	private function authenticate(string $uid = 'admin'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

	}//end authenticate()

	/**
	 * REQ-DWP-002: the documentsoorten endpoint lists the bundled members as
	 * `{code, label, uri}`.
	 *
	 * @return void
	 */
	public function testDocumentsoortenListsTheBundledMembers(): void {
		$this->authenticate();

		$response = $this->controller->index();
		$data = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(91, $data['total']);
		$this->assertCount(91, $data['results']);
		$this->assertSame(
			[
				'code' => 'c_852da199',
				'label' => 'Afkondigingsblad',
				'uri' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_852da199',
			],
			$data['results'][0]
		);

	}//end testDocumentsoortenListsTheBundledMembers()

	/**
	 * The documentsoorten endpoint refuses an anonymous caller, like categories().
	 *
	 * @return void
	 */
	public function testDocumentsoortenAnonymousReturns401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->assertSame(401, $this->controller->index()->getStatus());

	}//end testDocumentsoortenAnonymousReturns401()

	/**
	 * The method has a caller: a GET route beside woo#categories, at the same url.
	 *
	 * @return void
	 */
	public function testTheDocumentsoortenRouteIsAGet(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$found = array_values(array_filter($routes['routes'], static fn (array $route): bool => $route['name'] === 'documentsoorten#index'));

		// ApiPathAliases serves every unversioned route under /api/v1/ as well, by the same name.
		$urls = array_column($found, 'url');
		sort($urls);
		$this->assertSame(['/api/v1/woo/documentsoorten', '/api/woo/documentsoorten'], $urls);
		$this->assertSame(['GET', 'GET'], array_column($found, 'verb'));

	}//end testTheDocumentsoortenRouteIsAGet()
}//end class
