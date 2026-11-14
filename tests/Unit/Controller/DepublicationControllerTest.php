<?php

/**
 * Unit tests for DepublicationController.
 *
 * Asserted on the response the caller gets AND on what reached storage,
 * because a refusal that answered 400 and still wrote the acknowledgement
 * would report a document gone from a harvester nobody ever wrote to. The
 * status alone cannot tell those apart.
 *
 * Moved here from PublicationRulesControllerTest on 2026-09-19 with the two
 * REQ-PIN-106 write paths, unchanged.
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

use OCA\OpenCatalogi\Controller\DepublicationController;
use OCA\OpenCatalogi\Controller\RegisterAccess;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\Publication\DepublicationService;
use OCA\OpenCatalogi\Service\Publication\NationalIndexService;
use OCA\OpenCatalogi\Service\Publication\PublicationStateService;
use OCA\OpenCatalogi\Service\ServiceCatalogueService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for DepublicationController.
 */
class DepublicationControllerTest extends TestCase {

	private IRequest|MockObject $request;
	private IAppConfig|MockObject $config;
	private ContainerInterface|MockObject $container;
	private ServiceCatalogueService|MockObject $objects;
	private DepublicationController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === IAppConfig::class) {
					return $this->config;
				}

				throw new \RuntimeException('not available: ' . $id);
			}
		);

		// onlyMethods against the real class: a double that could invent a
		// method the real service lacks would let a green test cover a call
		// that 500s in production.
		$this->objects = $this->getMockBuilder(ServiceCatalogueService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getObjectService'])
			->getMock();
		$this->config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				// The depublication register and schema are configured, so the
				// resolver resolves; everything else keeps its default.
				if ($key === 'publication_register' || $key === 'depublication_schema' || $key === 'publication_schema') {
					return '42';
				}

				return $default;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ambtenaar');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$indexService = $this->getMockBuilder(NationalIndexService::class)
			->disableOriginalConstructor()
			->onlyMethods(['composeNotices', 'deliver', 'withdraw'])
			->getMock();
		// No channel source is set in these tests, so every withdrawal is
		// unreachable, as the real service answers without a source.
		$indexService->method('withdraw')->willThrowException(
			new \OCA\OpenCatalogi\Service\Publication\IndexUnreachableException('No integriq source is set for the channel.')
		);

		$this->controller = new DepublicationController(
			'opencatalogi',
			$this->request,
			$l10n,
			$userSession,
			new DepublicationService($indexService, $this->createMock(LoggerInterface::class)),
			new RegisterAccess(new OpenRegisterLookup($this->container, $this->createMock(IAppManager::class)), $this->objects),
			new PublicationStateService()
		);

	}//end setUp()

	/**
	 * Answer request parameters from a map.
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($params) {
				return ($params[$key] ?? $default);
			}
		);

	}//end withParams()

	/**
	 * An in-memory stand-in for OpenRegister's ObjectService.
	 *
	 * OpenRegister is not on the autoload path in a standalone checkout, so it
	 * cannot be mocked by type. This stores what it is handed and answers what
	 * it stored, which is the only behaviour these tests depend on. A save that
	 * silently dropped the object would fail the assertions below rather than
	 * pass them.
	 *
	 * @param array<string, array<string, mixed>> $seed Objects already stored, by id.
	 *
	 * @return object The stand-in.
	 */
	private function objectStore(array $seed = []): object {
		return new class($seed) {
			/**
			 * What a save was handed, by id, so a refusal can be asserted to
			 * have written nothing at all rather than only to have answered
			 * with the right status.
			 *
			 * @var array<string, array<string, mixed>>
			 */
			public array $saved = [];

			/**
			 * @param array<string, array<string, mixed>> $stored Objects by id.
			 */
			public function __construct(
				private array $stored,
			) {
			}

			public function find(string $id, string $register, string $schema, bool $_rbac = true): array {
				if (array_key_exists($id, $this->stored) === false) {
					throw new \RuntimeException('no such object: ' . $id);
				}

				return $this->stored[$id];
			}

			public function saveObject(
				array $object,
				array $extend = [],
				string $register = '',
				string $schema = '',
				string $uuid = '',
				bool $_rbac = true,
				bool $_unowned = false,
			): array {
				$id = ($uuid !== '' ? $uuid : 'stored-1');
				$object['id'] = $id;
				$this->stored[$id] = $object;
				$this->saved[$id] = $object;

				return $object;
			}
		};

	}//end objectStore()

	public function testTheDepublicationNamesEveryOutstandingChannel(): void {
		$this->objects->method('getObjectService')->willReturn($this->objectStore(['p1' => $this->publicPublication()]));
		$this->withParams(
			[
				'publication' => ['id' => 'p1'],
				'reason' => 'Publicatiefout.',
				'channels' => ['local-channel'],
			]
		);

		$response = $this->controller->depublish();

		// With no gateway the withdrawal cannot be delivered, so the channel is
		// outstanding and the response says so rather than reporting done.
		$this->assertSame(['local-channel'], $response->getData()['outstandingChannels']);
		$this->assertFalse($response->getData()['complete']);

	}//end testTheDepublicationNamesEveryOutstandingChannel()

	/**
	 * A depublication this app sent is stored, not only answered.
	 *
	 * REQ-PIN-106 asks for each channel's acknowledgement to be recorded. That
	 * is impossible against a value that only ever existed inside one response,
	 * which is what this endpoint used to return.
	 */
	public function testTheDepublicationIsStoredAndComesBackWithItsId(): void {
		$this->objects->method('getObjectService')->willReturn($this->objectStore(['p1' => $this->publicPublication()]));
		$this->withParams(
			[
				'publication' => ['id' => 'p1'],
				'reason' => 'Publicatiefout.',
				'channels' => ['local-channel'],
			]
		);

		$data = $this->controller->depublish()->getData();

		$this->assertSame('stored-1', $data['id']);
		$this->assertSame('p1', $data['publication']);
		$this->assertSame('ambtenaar', $data['depublishedBy']);

	}//end testTheDepublicationIsStoredAndComesBackWithItsId()

	/**
	 * A withdrawal a channel acknowledged stops being outstanding.
	 *
	 * The whole point of the endpoint: before this, `recordAcknowledgement` had
	 * no caller, so a depublication could never leave the outstanding state.
	 */
	public function testAnAcknowledgedWithdrawalStopsBeingOutstanding(): void {
		$this->objects->method('getObjectService')->willReturn(
			$this->objectStore(
				[
					'd1' => [
						'publication' => 'p1',
						'reason' => 'Publicatiefout.',
						'withdrawals' => [
							['channel' => 'local-channel', 'sentAt' => '2026-09-01T00:00:00+00:00', 'acknowledgedAt' => null, 'answer' => null],
						],
					],
				]
			)
		);
		$this->withParams(['depublication' => 'd1', 'channel' => 'local-channel', 'answer' => 'verwijderd']);

		$data = $this->controller->acknowledgeWithdrawal()->getData();

		$this->assertSame([], $data['outstandingChannels']);
		$this->assertTrue($data['complete']);
		$this->assertNotNull($data['withdrawals'][0]['acknowledgedAt']);
		$this->assertSame('verwijderd', $data['withdrawals'][0]['answer']);

	}//end testAnAcknowledgedWithdrawalStopsBeingOutstanding()

	/**
	 * A channel no withdrawal was sent to cannot acknowledge one.
	 *
	 * The failure this guard exists for: a caller naming any channel it likes
	 * would let a document be reported as gone from a harvester nobody ever
	 * wrote to, which is the one thing depublication must never report.
	 */
	public function testAChannelNoWithdrawalWasSentToCannotAcknowledge(): void {
		$store = $this->objectStore(
			[
				'd1' => [
					'publication' => 'p1',
					'withdrawals' => [
						['channel' => 'local-channel', 'sentAt' => '2026-09-01T00:00:00+00:00', 'acknowledgedAt' => null, 'answer' => null],
					],
				],
			]
		);
		$this->objects->method('getObjectService')->willReturn($store);
		$this->withParams(['depublication' => 'd1', 'channel' => 'woo-index', 'answer' => 'ok']);

		$response = $this->controller->acknowledgeWithdrawal();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('unknown-channel', $response->getData()['error']);

		// The status is only half of it. A refusal that still wrote the
		// acknowledgement would report a document gone from a harvester
		// nobody wrote to, and answer 400 while doing it.
		$this->assertSame([], $store->saved);

	}//end testAChannelNoWithdrawalWasSentToCannotAcknowledge()

	/**
	 * An acknowledgement against a depublication that does not exist is a 404.
	 */
	public function testAnAcknowledgementAgainstAnUnknownDepublicationIsNotFound(): void {
		$store = $this->objectStore();
		$this->objects->method('getObjectService')->willReturn($store);
		$this->withParams(['depublication' => 'nope', 'channel' => 'local-channel']);

		$response = $this->controller->acknowledgeWithdrawal();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame([], $store->saved);

	}//end testAnAcknowledgementAgainstAnUnknownDepublicationIsNotFound()

	/**
	 * An acknowledgement without its two parameters is refused.
	 */
	public function testAnAcknowledgementWithoutItsParametersIsRefused(): void {
		$store = $this->objectStore();
		$this->objects->method('getObjectService')->willReturn($store);
		$this->withParams([]);

		$response = $this->controller->acknowledgeWithdrawal();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missing-parameters', $response->getData()['error']);
		$this->assertSame([], $store->saved);

	}//end testAnAcknowledgementWithoutItsParametersIsRefused()

	/**
	 * A publication that is public now.
	 *
	 * @return array<string, mixed> The stored properties.
	 */
	private function publicPublication(): array {
		return ['title' => 'Besluit', 'publicationDate' => '2020-01-01T00:00:00+00:00'];

	}//end publicPublication()

	/**
	 * The depublish action takes the publication itself off the public side.
	 *
	 * Task 5.4: before it, the action recorded who and why and sent the
	 * withdrawals while the publication stayed public. The stored record now
	 * carries a depublicationDate that has passed, which is what the public
	 * read refuses on, and nothing else of the record changes.
	 */
	public function testDepublishSetsThePublicationsDepublicationDate(): void {
		$store = $this->objectStore(['p1' => $this->publicPublication()]);
		$this->objects->method('getObjectService')->willReturn($store);
		$this->withParams(
			[
				'publication' => ['id' => 'p1', 'title' => 'Forged title'],
				'reason' => 'Publicatiefout.',
				'channels' => ['local-channel'],
			]
		);

		$data = $this->controller->depublish()->getData();

		$this->assertArrayHasKey('p1', $store->saved);
		$saved = $store->saved['p1'];
		$this->assertSame('Besluit', $saved['title']);
		$this->assertSame('2020-01-01T00:00:00+00:00', $saved['publicationDate']);
		$this->assertLessThanOrEqual(time(), strtotime($saved['depublicationDate']));
		$this->assertSame('withdrawn', (new PublicationStateService())->stateOf($saved));
		$this->assertSame('withdrawn', $data['publicationState']);
		$this->assertSame($saved['depublicationDate'], $data['publicationDepublicationDate']);
		$this->assertSame('p1', $data['publication']);

	}//end testDepublishSetsThePublicationsDepublicationDate()

	/**
	 * A publication that is already withdrawn keeps the date it came down on.
	 */
	public function testAnAlreadyWithdrawnPublicationKeepsItsDate(): void {
		$withdrawn = $this->publicPublication() + ['depublicationDate' => '2021-06-01T00:00:00+00:00'];
		$store = $this->objectStore(['p1' => $withdrawn]);
		$this->objects->method('getObjectService')->willReturn($store);
		$this->withParams(['publication' => ['id' => 'p1'], 'reason' => 'Publicatiefout.']);

		$data = $this->controller->depublish()->getData();

		$this->assertArrayNotHasKey('p1', $store->saved);
		$this->assertSame('2021-06-01T00:00:00+00:00', $data['publicationDepublicationDate']);
		$this->assertSame('withdrawn', $data['publicationState']);

	}//end testAnAlreadyWithdrawnPublicationKeepsItsDate()

	/**
	 * A publication that does not exist is not found, and nothing is sent or stored.
	 */
	public function testDepublishingAnUnknownPublicationIsNotFound(): void {
		$store = $this->objectStore();
		$this->objects->method('getObjectService')->willReturn($store);
		$this->withParams(['publication' => ['id' => 'nope'], 'reason' => 'Publicatiefout.', 'channels' => ['local-channel']]);

		$response = $this->controller->depublish();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('publication-not-found', $response->getData()['error']);
		$this->assertSame([], $store->saved);

	}//end testDepublishingAnUnknownPublicationIsNotFound()

	/**
	 * A publication named without an id is refused before anything is read.
	 */
	public function testDepublishingWithoutAnIdIsRefused(): void {
		$store = $this->objectStore();
		$this->objects->method('getObjectService')->willReturn($store);
		$this->withParams(['publication' => ['title' => 'Besluit'], 'reason' => 'Publicatiefout.']);

		$response = $this->controller->depublish();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missing-publication', $response->getData()['error']);
		$this->assertSame([], $store->saved);

	}//end testDepublishingWithoutAnIdIsRefused()

	/**
	 * Without a reason the publication stays up: the refusal comes first.
	 */
	public function testWithoutAReasonThePublicationIsNotTakenDown(): void {
		$store = $this->objectStore(['p1' => $this->publicPublication()]);
		$this->objects->method('getObjectService')->willReturn($store);
		$this->withParams(['publication' => ['id' => 'p1'], 'reason' => '   ']);

		$response = $this->controller->depublish();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('depublication-refused', $response->getData()['error']);
		$this->assertSame([], $store->saved);

	}//end testWithoutAReasonThePublicationIsNotTakenDown()

}//end class
