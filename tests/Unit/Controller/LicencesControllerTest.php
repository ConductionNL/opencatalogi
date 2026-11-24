<?php

/**
 * Unit tests for LicencesController.
 *
 * @category Test
 * @package  Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\LicencesController;
use OCA\OpenCatalogi\Service\DcatLicenceRule;
use OCA\OpenCatalogi\Service\DcatVocabularyService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The licence pickers read the same list a save is held to.
 *
 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
 */
class LicencesControllerTest extends TestCase {

	/**
	 * The session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * The controller under test.
	 *
	 * @var LicencesController
	 */
	private LicencesController $controller;

	/**
	 * Build the controller over the real bundled list.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new LicencesController(
			appName: 'opencatalogi',
			request: $this->createMock(originalClassName: IRequest::class),
			l10n: $l10n,
			userSession: $this->userSession,
			vocabulary: new DcatVocabularyService()
		);

	}//end setUp()

	/**
	 * A signed-in caller gets every member with its code, IRI and both labels, and the list's provenance.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
	 *
	 * @return void
	 */
	public function testASignedInCallerGetsTheListWithItsSource(): void {
		$this->userSession->method('getUser')->willReturn($this->createMock(originalClassName: IUser::class));

		$response = $this->controller->index();
		$data = $response->getData();

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: count((new DcatVocabularyService())->licenceList()), actual: $data['total']);
		$this->assertGreaterThan(expected: 0, actual: $data['total']);
		$this->assertNotSame(expected: '', actual: $data['source']);
		$this->assertNotSame(expected: '', actual: $data['version']);
		foreach ($data['results'] as $row) {
			$this->assertSame(expected: ['code', 'iri', 'label', 'labelNl'], actual: array_keys($row));
		}
	}//end testASignedInCallerGetsTheListWithItsSource()

	/**
	 * Every IRI the picker offers is one the save rule accepts.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
	 *
	 * @return void
	 */
	public function testEveryOfferedLicencePassesTheSaveRule(): void {
		$this->userSession->method('getUser')->willReturn($this->createMock(originalClassName: IUser::class));
		$rule = new DcatLicenceRule(vocabulary: new DcatVocabularyService());

		foreach ($this->controller->index()->getData()['results'] as $row) {
			$this->assertTrue(condition: $rule->isListed(value: $row['iri']), message: $row['code']);
		}
	}//end testEveryOfferedLicencePassesTheSaveRule()

	/**
	 * An anonymous caller is refused.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertSame(expected: 401, actual: $this->controller->index()->getStatus());
	}//end testAnAnonymousCallerIsRefused()
}//end class
