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
use OCA\OpenCatalogi\Controller\InspectionRecords;
use OCA\OpenCatalogi\Controller\RegisterAccess;
use OCA\OpenCatalogi\Service\Catalogue\CatalogueUnreadableException;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\Publication\InspectionService;
use OCA\OpenCatalogi\Service\ServiceCatalogueService;
use OCP\App\IAppManager;
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

	/**
	 * The object service stand-in of the current case.
	 *
	 * @var object|null
	 */
	private ?object $store = null;

	private const GOLDEN = __DIR__ . '/fixtures/inspection-follow-golden.json';

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
			$this->l10n,
			$this->userSession,
			new InspectionRecords(
				new InspectionService(),
				new RegisterAccess(new OpenRegisterLookup($this->container, $this->createMock(IAppManager::class)), $this->objects)
			)
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
		$this->objects->method('getObjectService')->willReturn($this->inspectionStore(inspection: $inspection));

	}//end storedInspection()

	/**
	 * An object service stand-in that answers one stored inspection and records every read.
	 *
	 * @param array<string, mixed>|null $inspection The stored inspection, or null for an unknown id.
	 *
	 * @return object The stand-in.
	 */
	private function inspectionStore(?array $inspection): object {
		$this->store = new class($inspection) {
			/**
			 * Every find() call, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $reads = [];

			public function __construct(
				private readonly ?array $inspection,
			) {
			}

			public function find(string $id, string $register, string $schema, bool $_rbac = true): array {
				$this->reads[] = ['id' => $id, 'register' => $register, 'schema' => $schema, 'rbac' => $_rbac];
				if ($this->inspection === null) {
					throw new \RuntimeException('Object not found');
				}

				return $this->inspection;
			}
		};

		return $this->store;
	}//end inspectionStore()

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
	 * The link is read without RBAC, so an anonymous reader reaches an inspection only administrators may read.
	 */
	public function testTheLinkIsReadWithoutRbac(): void {
		$this->storedInspection(
			[
				'record' => 'besluit-1',
				'documents' => ['doc-1'],
				'startDate' => '2026-09-01T00:00:00+00:00',
				'endDate' => '2099-01-01T00:00:00+00:00',
				'token' => 'the-token',
			]
		);
		$this->withToken('the-token');

		$this->assertSame(Http::STATUS_OK, $this->controller->follow('inspection-1')->getStatus());
		$this->assertSame([['id' => 'inspection-1', 'register' => '42', 'schema' => '42', 'rbac' => false]], $this->store->reads);

	}//end testTheLinkIsReadWithoutRbac()

	/**
	 * A request without a token reads nothing, even when the register is down.
	 */
	public function testARequestWithoutATokenReadsNothing(): void {
		$this->objects->expects($this->never())->method('getObjectService');
		$this->withToken('');

		$response = $this->controller->follow('inspection-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'unknown-inspection'], $response->getData());

	}//end testARequestWithoutATokenReadsNothing()

	/**
	 * A forged token answers nothing that is stored on the inspection.
	 *
	 * The stored values a reader could try (the opener, the record, the dates)
	 * and a token one character off all answer the same 404.
	 */
	public function testAForgedTokenAnswersNothingStored(): void {
		$stored = [
			'record' => 'besluit-1',
			'documents' => ['doc-1'],
			'startDate' => '2026-09-01T00:00:00+00:00',
			'endDate' => '2099-01-01T00:00:00+00:00',
			'token' => 'the-token',
			'openedBy' => 'beheerder',
		];

		foreach (['beheerder', 'besluit-1', 'inspection-1', 'the-token ', ' the-token', 'the-tokeN'] as $forged) {
			$this->setUp();
			$this->storedInspection($stored);
			$this->withToken($forged);

			$response = $this->controller->follow('inspection-1');

			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus(), $forged);
			$this->assertSame(['error' => 'unknown-inspection'], $response->getData(), $forged);
		}

	}//end testAForgedTokenAnswersNothingStored()

	/**
	 * The stored inspections and presented tokens the follow golden replays.
	 *
	 * @return array<string, array{0: array<string, mixed>|null, 1: string}>
	 */
	public static function followWorlds(): array {
		$open = [
			'record' => 'besluit-1',
			'documents' => ['doc-1', 'doc-2'],
			'startDate' => '2026-09-01T00:00:00+00:00',
			'endDate' => '2099-01-01T00:00:00+00:00',
			'token' => 'the-token',
			'openedBy' => 'beheerder',
		];
		$closed = array_replace($open, ['startDate' => '2020-01-01T00:00:00+00:00', 'endDate' => '2020-02-01T00:00:00+00:00']);

		return [
			'open window, right token' => [$open, 'the-token'],
			'closed window, right token' => [$closed, 'the-token'],
			'open window, wrong token' => [$open, 'a-guess'],
			'open window, token prefix' => [$open, 'the-tok'],
			'open window, token in other case' => [$open, 'THE-TOKEN'],
			'closed window, wrong token' => [$closed, 'a-guess'],
			'open window, no token presented' => [$open, ''],
			'stored token empty, none presented' => [array_replace($open, ['token' => '']), ''],
			'stored token missing, one presented' => [array_diff_key($open, ['token' => true]), 'the-token'],
			'unreadable window, right token' => [array_replace($open, ['endDate' => 'not a date']), 'the-token'],
			'unreadable window, wrong token' => [array_replace($open, ['endDate' => 'not a date']), 'a-guess'],
			'documents not a list, right token' => [array_replace($open, ['documents' => 'doc-1']), 'the-token'],
			'unknown id, a token presented' => [null, 'the-token'],
		];

	}//end followWorlds()

	/**
	 * What following a link answers, and which inspection it read, matches the recording.
	 *
	 * @param array<string, mixed>|null $inspection The stored inspection, or null for an unknown id.
	 * @param string $token The token the reader presented.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('followWorlds')]
	public function testFollowMatchesTheRecording(?array $inspection, string $token): void {
		$this->objects->method('getObjectService')->willReturn($this->inspectionStore(inspection: $inspection));
		$this->withToken($token);

		$response = $this->controller->follow('inspection-1');
		$headers = $response->getHeaders();
		$actual = [
			'status' => $response->getStatus(),
			'data' => $response->getData(),
			'cors' => [($headers['Access-Control-Allow-Origin'] ?? null), ($headers['Access-Control-Allow-Credentials'] ?? null)],
		];

		// Whether a request without a token reads anything is pinned by its own test.
		if ($token !== '') {
			$actual['reads'] = array_map(
				static fn (array $read): array => array_diff_key($read, ['rbac' => true]),
				$this->store->reads
			);
		}

		$recorded = [];
		if (file_exists(self::GOLDEN) === true) {
			$recorded = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		$name = (string)$this->dataName();
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::GOLDEN, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($actual), true));

	}//end testFollowMatchesTheRecording()

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
			 * @param array<int, string> $extend Unused here.
			 *
			 * @return array<string, mixed> The stored object.
			 */
			public function saveObject(
				array $object,
				array $extend = [],
				string $register = '',
				string $schema = '',
				string $uuid = '',
				bool $_rbac = true,
				bool $_unowned = false,
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
	 * Documents that are not a list are refused like any other parameter, and
	 * a refused inspection names why.
	 */
	public function testTheRefusalsNameWhy(): void {
		$store = $this->recordingStore();
		$this->objects->method('getObjectService')->willReturn($store);
		$this->withOpenParams(['record' => ['id' => 'b'], 'recordType' => ['inspectionTermDays' => 4], 'documents' => 'doc-a']);
		$this->assertSame(['error' => 'missing-parameters'], $this->controller->open()->getData());

		$this->setUp();
		$this->objects->method('getObjectService')->willReturn($store);
		$this->withOpenParams(['record' => ['id' => 'b'], 'recordType' => ['slug' => 'x', 'inspectionTermDays' => 4], 'documents' => []]);
		$this->assertSame(
			['error' => 'inspection-refused', 'message' => 'An inspection needs the documents chosen for it. An inspection over nothing is not an inspection.'],
			$this->controller->open()->getData()
		);
		$this->assertSame([], $store->saved);

	}//end testTheRefusalsNameWhy()

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

	/**
	 * Numeric document ids are stored as strings.
	 */
	public function testNumericDocumentIdsAreStoredAsStrings(): void {
		$store = $this->recordingStore();
		$this->objects->method('getObjectService')->willReturn($store);
		$this->withOpenParams(
			[
				'record' => ['id' => 'besluit-1'],
				'recordType' => ['slug' => 'omgevingsvergunning', 'inspectionTermDays' => 42],
				'documents' => [7, 'doc-b'],
			]
		);

		$response = $this->controller->open();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['7', 'doc-b'], $store->saved[0]['documents']);

	}//end testNumericDocumentIdsAreStoredAsStrings()

	/**
	 * A stored object in an envelope answers its properties with the envelope id.
	 */
	public function testAnEnvelopedStoredInspectionAnswersItsPropertiesWithTheEnvelopeId(): void {
		$store = new class {
			/**
			 * Answer the saved inspection inside an OpenRegister envelope.
			 *
			 * @param array<string, mixed> $object The inspection.
			 * @param array<int, string> $extend Unused here.
			 * @param string $register Unused here.
			 * @param string $schema Unused here.
			 *
			 * @return array<string, mixed> The envelope.
			 */
			public function saveObject(array $object, array $extend = [], string $register = '', string $schema = '', bool $_rbac = true, bool $_unowned = false): array {
				return ['id' => 'inspection-7', 'object' => ['termDays' => $object['termDays']]];
			}
		};
		$this->objects->method('getObjectService')->willReturn($store);
		$this->withOpenParams(
			[
				'record' => ['id' => 'besluit-1'],
				'recordType' => ['slug' => 'omgevingsvergunning', 'inspectionTermDays' => 42],
				'documents' => ['doc-a'],
			]
		);

		$response = $this->controller->open();

		$this->assertSame(['termDays' => 42, 'id' => 'inspection-7'], $response->getData());

	}//end testAnEnvelopedStoredInspectionAnswersItsPropertiesWithTheEnvelopeId()

}//end class
