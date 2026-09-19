<?php

/**
 * Unit tests for InspectionController.
 *
 * The inspection link is checked at the read, so these tests assert on the
 * response a reader who followed a link out of a letter actually gets. The two
 * failures that matter are opposite: serving documents whose statutory period
 * has run out, and answering "not found" to a reader whose window is still
 * open.
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

use OCA\OpenCatalogi\Controller\InspectionController;
use OCA\OpenCatalogi\Service\Catalogue\CatalogueUnreadableException;
use OCA\OpenCatalogi\Service\Publication\InspectionService;
use OCA\OpenCatalogi\Service\ServiceCatalogueService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Unit tests for InspectionController.
 */
class InspectionControllerTest extends TestCase {

	private IRequest|MockObject $request;
	private IAppConfig|MockObject $config;
	private ContainerInterface|MockObject $container;
	private IL10N|MockObject $l10n;
	private IUserSession|MockObject $userSession;
	private ServiceCatalogueService|MockObject $objects;
	private InspectionController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnArgument(0);
		$this->userSession = $this->createMock(IUserSession::class);

		// onlyMethods against the real class: a double that could invent a
		// method the real service lacks would let a green test cover a call
		// that 500s in production.
		$this->objects = $this->getMockBuilder(ServiceCatalogueService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getObjectService'])
			->getMock();

		$this->config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				if ($key === 'cors_allowed_origins') {
					return '*';
				}

				// Both register keys are configured, so the trait resolves.
				return '42';
			}
		);

		// The register resolver reads IAppConfig out of the container, so a
		// container that answers nothing makes every call here a 404 for the
		// wrong reason.
		$this->container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === \OCP\IAppConfig::class) {
					return $this->config;
				}

				throw new \RuntimeException('not available: ' . $id);
			}
		);

		$this->controller = new InspectionController(
			'opencatalogi',
			$this->request,
			$this->config,
			$this->container,
			$this->l10n,
			$this->userSession,
			new InspectionService(),
			$this->objects
		);

	}//end setUp()

	/**
	 * Make the object service answer one stored inspection.
	 *
	 * @param array<string, mixed> $inspection The stored inspection.
	 *
	 * @return void
	 */
	private function storedInspection(array $inspection): void {
		$objectService = new class($inspection) {
			public function __construct(private readonly array $inspection) {
			}

			public function find(string $id, string $register, string $schema): array {
				return $this->inspection;
			}
		};
		$this->objects->method('getObjectService')->willReturn($objectService);

	}//end storedInspection()

	/**
	 * Answer the `token` query parameter.
	 *
	 * @param string $token The token the reader presented.
	 *
	 * @return void
	 */
	private function withToken(string $token): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($token) {
				return ($key === 'token' ? $token : $default);
			}
		);

	}//end withToken()

	/**
	 * An open window answers the chosen documents, and only those.
	 *
	 * The wire contract of `GET /api/inspections/{id}`.
	 */
	public function testAnOpenWindowAnswersTheChosenDocuments(): void {
		$this->storedInspection(
			[
				'record' => 'besluit-1',
				'documents' => ['doc-1', 'doc-2'],
				'startDate' => '2026-09-01T00:00:00+00:00',
				'endDate' => '2099-01-01T00:00:00+00:00',
				'token' => 'the-token',
			]
		);
		$this->withToken('the-token');

		$response = $this->controller->follow('inspection-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('besluit-1', $data['record']);
		$this->assertSame(['doc-1', 'doc-2'], $data['documents']);

	}//end testAnOpenWindowAnswersTheChosenDocuments()

	/**
	 * A window whose period has ended answers 410 with the end date.
	 *
	 * Not 404: a reader who followed a link from a letter has to learn that the
	 * period is over, rather than that something is broken.
	 */
	public function testAClosedWindowAnswers410WithItsEndDate(): void {
		$this->storedInspection(
			[
				'record' => 'besluit-1',
				'documents' => ['doc-1'],
				'startDate' => '2020-01-01T00:00:00+00:00',
				'endDate' => '2020-02-01T00:00:00+00:00',
				'token' => 'the-token',
			]
		);
		$this->withToken('the-token');

		$response = $this->controller->follow('inspection-1');

		$this->assertSame(Http::STATUS_GONE, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('window-closed', $data['error']);
		$this->assertSame('2020-02-01T00:00:00+00:00', $data['endDate']);

	}//end testAClosedWindowAnswers410WithItsEndDate()

	/**
	 * A wrong token answers 404 and hands back no document.
	 */
	public function testAWrongTokenAnswersNotFoundAndNoDocuments(): void {
		$this->storedInspection(
			[
				'record' => 'besluit-1',
				'documents' => ['doc-1'],
				'startDate' => '2026-09-01T00:00:00+00:00',
				'endDate' => '2099-01-01T00:00:00+00:00',
				'token' => 'the-token',
			]
		);
		$this->withToken('a-guess');

		$response = $this->controller->follow('inspection-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertArrayNotHasKey('documents', $response->getData());

	}//end testAWrongTokenAnswersNotFoundAndNoDocuments()

	/**
	 * A register this app cannot read answers 503, never an empty inspection.
	 */
	public function testAnUnreadableRegisterAnswers503(): void {
		$this->objects->method('getObjectService')
			->willThrowException(new CatalogueUnreadableException('OpenRegister is unavailable'));
		$this->withToken('the-token');

		$response = $this->controller->follow('inspection-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('register-unreadable', $response->getData()['error']);

	}//end testAnUnreadableRegisterAnswers503()

	/**
	 * An object service stand-in that records what it was asked to store.
	 *
	 * @return object The stand-in.
	 */
	private function recordingStore(): object {
		return new class {
			/**
			 * Every object handed to saveObject, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			/**
			 * @param array<string, mixed> $object The object to store.
			 * @param array<int, string>   $extend Unused here.
			 *
			 * @return array<string, mixed> The stored object.
			 */
			public function saveObject(
				array $object,
				array $extend = [],
				string $register = '',
				string $schema = '',
				string $uuid = '',
			): array {
				$object['id'] = 'inspection-1';
				$this->saved[] = $object;

				return $object;
			}
		};

	}//end recordingStore()

	/**
	 * Answer request parameters from a map.
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return void
	 */
	private function withOpenParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

	}//end withOpenParams()

	/**
	 * An inspection is stored with the term its record type declares.
	 */
	public function testAnInspectionIsStoredForTheDeclaredTerm(): void {
		$store = $this->recordingStore();
		$this->objects->method('getObjectService')->willReturn($store);
		$this->withOpenParams(
			[
				'record' => ['id' => 'besluit-1'],
				'recordType' => ['slug' => 'omgevingsvergunning', 'inspectionTermDays' => 42],
				'documents' => ['doc-a', 'doc-b'],
			]
		);

		$response = $this->controller->open();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertCount(1, $store->saved);
		$this->assertSame(42, $store->saved[0]['termDays']);
		$this->assertSame(['doc-a', 'doc-b'], $store->saved[0]['documents']);

	}//end testAnInspectionIsStoredForTheDeclaredTerm()

	/**
	 * A record type with no term opens no inspection, and stores nothing.
	 *
	 * The term is the statutory period. Defaulting it would put documents on
	 * public inspection for a length of time nobody chose.
	 */
	public function testARecordTypeWithNoTermOpensNothingAndStoresNothing(): void {
		$store = $this->recordingStore();
		$this->objects->method('getObjectService')->willReturn($store);
		$this->withOpenParams(
			[
				'record' => ['id' => 'besluit-1'],
				'recordType' => ['slug' => 'onbekend'],
				'documents' => ['doc-a'],
			]
		);

		$response = $this->controller->open();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('inspection-refused', $response->getData()['error']);
		$this->assertSame([], $store->saved);

	}//end testARecordTypeWithNoTermOpensNothingAndStoresNothing()

	/**
	 * An inspection over no documents is refused, and stores nothing.
	 */
	public function testAnInspectionOverNoDocumentsIsRefusedAndStoresNothing(): void {
		$store = $this->recordingStore();
		$this->objects->method('getObjectService')->willReturn($store);
		$this->withOpenParams(
			[
				'record' => ['id' => 'besluit-1'],
				'recordType' => ['slug' => 'omgevingsvergunning', 'inspectionTermDays' => 42],
				'documents' => [],
			]
		);

		$response = $this->controller->open();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('inspection-refused', $response->getData()['error']);
		$this->assertSame([], $store->saved);

	}//end testAnInspectionOverNoDocumentsIsRefusedAndStoresNothing()

	/**
	 * Parameters of the wrong shape are refused before anything is opened.
	 */
	public function testParametersOfTheWrongShapeAreRefused(): void {
		$store = $this->recordingStore();
		$this->objects->method('getObjectService')->willReturn($store);
		$this->withOpenParams(['record' => 'not-an-array', 'recordType' => [], 'documents' => []]);

		$response = $this->controller->open();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missing-parameters', $response->getData()['error']);
		$this->assertSame([], $store->saved);

	}//end testParametersOfTheWrongShapeAreRefused()

	/**
	 * An inspection that cannot be stored answers 503, never a quiet success.
	 *
	 * The caller is told the documents are on inspection; if the write was
	 * lost, nobody can see them and nobody knows.
	 */
	public function testAnInspectionThatCannotBeStoredAnswers503(): void {
		$this->objects->method('getObjectService')
			->willThrowException(new CatalogueUnreadableException('OpenRegister is unavailable'));
		$this->withOpenParams(
			[
				'record' => ['id' => 'besluit-1'],
				'recordType' => ['slug' => 'omgevingsvergunning', 'inspectionTermDays' => 42],
				'documents' => ['doc-a'],
			]
		);

		$response = $this->controller->open();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('register-unreadable', $response->getData()['error']);

	}//end testAnInspectionThatCannotBeStoredAnswers503()

}//end class
