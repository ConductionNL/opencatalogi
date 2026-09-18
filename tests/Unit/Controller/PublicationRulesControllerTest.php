<?php

/**
 * Unit tests for PublicationRulesController.
 *
 * Asserted on the response the caller gets, because that is the thing that
 * either discloses or refuses. A service that behaves and a controller that
 * discards its answer look identical from inside the service.
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

use OCA\OpenCatalogi\Controller\PublicationRulesController;
use OCA\OpenCatalogi\Service\Publication\DecisionPublicationValidator;
use OCA\OpenCatalogi\Service\Publication\DepublicationService;
use OCA\OpenCatalogi\Service\Publication\DocumentStampService;
use OCA\OpenCatalogi\Service\Publication\IndexUnreachableException;
use OCA\OpenCatalogi\Service\Publication\NationalIndexService;
use OCA\OpenCatalogi\Service\Publication\PublicationProcessService;
use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;
use OCA\OpenCatalogi\Service\Publication\PublishedCollectionsService;
use OCA\OpenCatalogi\Service\Publication\ZienswijzeService;
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
 * Unit tests for PublicationRulesController.
 */
class PublicationRulesControllerTest extends TestCase {

	private IRequest|MockObject $request;
	private IAppConfig|MockObject $config;
	private ContainerInterface|MockObject $container;
	private ServiceCatalogueService|MockObject $objects;
	private IUserSession|MockObject $userSession;
	private NationalIndexService|MockObject $indexService;
	private PublicationRulesController $controller;

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
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);

		// onlyMethods against the real classes: the doubles cannot answer a
		// call the production classes would not have.
		$this->indexService = $this->getMockBuilder(NationalIndexService::class)
			->disableOriginalConstructor()
			->onlyMethods(['composeNotices', 'deliver'])
			->getMock();

		$this->controller = new PublicationRulesController(
			'opencatalogi',
			$this->request,
			$this->config,
			$l10n,
			$this->userSession,
			new PublicationRuleService(),
			new DecisionPublicationValidator(),
			new PublicationProcessService(),
			new ZienswijzeService(),
			new DepublicationService($this->indexService, $this->createMock(LoggerInterface::class)),
			$this->indexService,
			new DocumentStampService(signingKey: 'test-key'),
			new PublishedCollectionsService($this->config, new PublicationRuleService(), 'opencatalogi'),
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

				return $object;
			}
		};

	}//end objectStore()

	public function testThePreviewNamesTheExposedPropertiesAndNotOnlyACount(): void {
		$this->withParams(
			[
				'rule' => [
					'recordType' => 'besluit',
					'anonymousProperties' => ['title'],
					'conditions' => [['property' => 'status', 'operator' => 'equals', 'value' => 'definitief']],
				],
				'sample' => [
					['@type' => 'besluit', 'status' => 'definitief', 'title' => 'Een', 'bsn' => '1'],
				],
			]
		);

		$data = $this->controller->previewRule()->getData();

		$this->assertTrue($data['valid']);
		$this->assertSame(['title'], $data['exposedProperties']);
		$this->assertArrayNotHasKey('bsn', $data['wouldPublish'][0]);

	}//end testThePreviewNamesTheExposedPropertiesAndNotOnlyACount()

	public function testARuleWithAnUnknownOperatorIsRefusedByThePreview(): void {
		$this->withParams(
			[
				'rule' => [
					'recordType' => 'besluit',
					'anonymousProperties' => ['title'],
					'conditions' => [['property' => 'status', 'operator' => 'sortOfLike', 'value' => 'x']],
				],
				'sample' => [],
			]
		);

		$data = $this->controller->previewRule()->getData();

		$this->assertFalse($data['valid']);
		$this->assertNotEmpty($data['errors']);

	}//end testARuleWithAnUnknownOperatorIsRefusedByThePreview()

	public function testADecisionThatFailsItsTypeIsRefusedWithTheReason(): void {
		$this->withParams(
			[
				'decision' => ['id' => 'b1'],
				'decisionType' => ['publicationObligation' => true, 'responseTermDays' => 42],
			]
		);

		$response = $this->controller->validateDecision();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('not-publishable', $response->getData()['error']);
		$this->assertNotEmpty($response->getData()['reasons']);

	}//end testADecisionThatFailsItsTypeIsRefusedWithTheReason()

	public function testAPublicationHeldByAnOpenAskAnswers409AndNamesIt(): void {
		$process = (new PublicationProcessService())->start(publicationId: 'p1');
		$ask = (new ZienswijzeService())->raise(
			publicationId: 'p1',
			party: 'J. de Vries',
			channel: 'digid',
			termDays: 3650
		);

		$this->withParams(['process' => $process, 'step' => 'channels', 'asks' => [$ask]]);

		$response = $this->controller->completeStep();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('J. de Vries', $response->getData()['heldBy'][0]['party']);

	}//end testAPublicationHeldByAnOpenAskAnswers409AndNamesIt()

	public function testAnAskOverAnUnidentifiedChannelIsRefused(): void {
		$this->withParams(
			['publication' => 'p1', 'party' => 'J. de Vries', 'channel' => 'anonymous-webform', 'termDays' => 14]
		);

		$response = $this->controller->raiseZienswijze();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('ask-refused', $response->getData()['error']);

	}//end testAnAskOverAnUnidentifiedChannelIsRefused()

	/**
	 * An announcement that reached neither channel answers 502 and names the
	 * channels. A 200 here would let an operator read a partial announcement as
	 * a complete one.
	 */
	public function testAnUndeliveredAnnouncementAnswers502AndNamesTheChannels(): void {
		$this->indexService->method('composeNotices')->willReturn(
			[['channel' => 'national-publication-platform'], ['channel' => 'local-channel']]
		);
		$this->indexService->method('deliver')->willThrowException(new IndexUnreachableException('no gateway'));

		$this->withParams(['decision' => ['id' => 'b1', 'title' => 'Kapvergunning']]);

		$response = $this->controller->announce();

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertFalse($response->getData()['complete']);
		$this->assertCount(2, $response->getData()['unreachable']);
		$this->assertCount(2, $response->getData()['notices']);

	}//end testAnUndeliveredAnnouncementAnswers502AndNamesTheChannels()

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
		$this->objects->method('getObjectService')->willReturn(
			$this->objectStore(
				[
					'd1' => [
						'publication' => 'p1',
						'withdrawals' => [
							['channel' => 'local-channel', 'sentAt' => '2026-09-01T00:00:00+00:00', 'acknowledgedAt' => null, 'answer' => null],
						],
					],
				]
			)
		);
		$this->withParams(['depublication' => 'd1', 'channel' => 'woo-index', 'answer' => 'ok']);

		$response = $this->controller->acknowledgeWithdrawal();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('unknown-channel', $response->getData()['error']);

	}//end testAChannelNoWithdrawalWasSentToCannotAcknowledge()

	/**
	 * An acknowledgement against a depublication that does not exist is a 404.
	 */
	public function testAnAcknowledgementAgainstAnUnknownDepublicationIsNotFound(): void {
		$this->objects->method('getObjectService')->willReturn($this->objectStore());
		$this->withParams(['depublication' => 'nope', 'channel' => 'local-channel']);

		$response = $this->controller->acknowledgeWithdrawal();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testAnAcknowledgementAgainstAnUnknownDepublicationIsNotFound()

	/**
	 * An acknowledgement without its two parameters is refused.
	 */
	public function testAnAcknowledgementWithoutItsParametersIsRefused(): void {
		$this->withParams([]);

		$response = $this->controller->acknowledgeWithdrawal();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missing-parameters', $response->getData()['error']);

	}//end testAnAcknowledgementWithoutItsParametersIsRefused()

	public function testThePublicSearchRunsOverTheProjections(): void {
		$this->withParams(
			[
				'records' => [
					['@type' => 'besluit', 'status' => 'definitief', 'title' => 'Dorpsstraat', 'bsn' => 'Zeldzaamwoord'],
				],
				'rules' => [
					['recordType' => 'besluit', 'enabled' => true, 'anonymousProperties' => ['title']],
				],
				'q' => 'Zeldzaamwoord',
			]
		);

		$data = $this->controller->publicSearch()->getData();

		$this->assertSame(0, $data['total']);

	}//end testThePublicSearchRunsOverTheProjections()

	public function testTheVerificationKeyIsPublishedAndIsNotTheKey(): void {
		$data = $this->controller->verificationKey()->getData();

		$this->assertSame('default', $data['keyId']);
		$this->assertStringNotContainsString('test-key', (string)json_encode($data));

	}//end testTheVerificationKeyIsPublishedAndIsNotTheKey()

	/**
	 * A document verifies against the stamp that was made for it.
	 *
	 * The wire contract of `POST /api/publications/verify`: the reader sends
	 * the document, its publication metadata and the stamp, and gets a verdict.
	 */
	public function testAStampedDocumentVerifiesThroughTheEndpoint(): void {
		$stampService = new DocumentStampService(signingKey: 'test-key');
		$metadata = ['publication' => 'p1', 'publishedAt' => '2026-09-01T00:00:00+00:00'];
		$stamp = $stampService->stamp(documentBytes: 'the document bytes', metadata: $metadata);

		$this->withParams(
			[
				'document' => base64_encode('the document bytes'),
				'metadata' => $metadata,
				'stamp' => $stamp,
			]
		);

		$response = $this->controller->verifyDocument();

		$this->assertTrue($response->getData()['valid']);

	}//end testAStampedDocumentVerifiesThroughTheEndpoint()

	/**
	 * A document that was changed after it was stamped does not verify.
	 *
	 * The half that matters: a verify endpoint that answered `valid` for
	 * everything would tell every reader a document is authentic while nobody
	 * checked anything.
	 */
	public function testAChangedDocumentDoesNotVerify(): void {
		$stampService = new DocumentStampService(signingKey: 'test-key');
		$metadata = ['publication' => 'p1', 'publishedAt' => '2026-09-01T00:00:00+00:00'];
		$stamp = $stampService->stamp(documentBytes: 'the document bytes', metadata: $metadata);

		$this->withParams(
			[
				'document' => base64_encode('the document bytes, altered'),
				'metadata' => $metadata,
				'stamp' => $stamp,
			]
		);

		$data = $this->controller->verifyDocument()->getData();

		$this->assertFalse($data['valid']);
		$this->assertSame('does-not-match', $data['reason']);

	}//end testAChangedDocumentDoesNotVerify()

	/**
	 * A verify call without a stamp is refused, never answered `valid`.
	 */
	public function testAVerifyWithoutAStampIsRefused(): void {
		$this->withParams(['document' => 'x', 'metadata' => [], 'stamp' => []]);

		$response = $this->controller->verifyDocument();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missing-parameters', $response->getData()['error']);

	}//end testAVerifyWithoutAStampIsRefused()
}//end class
