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
use OCA\OpenCatalogi\Service\Publication\DepublicationService;
use OCA\OpenCatalogi\Service\Publication\NationalIndexService;
use OCA\OpenCatalogi\Service\ServiceCatalogueService;
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
				if ($key === 'publication_register' || $key === 'depublication_schema') {
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
			->onlyMethods(['composeNotices', 'deliver'])
			->getMock();

		$this->controller = new DepublicationController(
			'opencatalogi',
			$this->request,
			$l10n,
			$userSession,
			new DepublicationService($indexService, $this->createMock(LoggerInterface::class)),
			$this->container,
			$this->objects
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
			public function __construct(private array $stored) {
			}

			public function find(string $id, string $register, string $schema): array {
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
		$this->objects->method('getObjectService')->willReturn($this->objectStore());
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
		$this->objects->method('getObjectService')->willReturn($this->objectStore());
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

}//end class
