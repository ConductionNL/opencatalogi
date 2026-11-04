<?php

/**
 * The saves behind the admin setting work for a delegated administrator.
 *
 * `#[AuthorizedAdminSetting]` lets delegated administrators in, while the shipped
 * blocks name group `admin`, so these flows must not ask RBAC again. The store
 * below applies the block as shipped to whoever calls it, so a save that still
 * asks RBAC is refused here as it is in OpenRegister.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec exclude test-only guard over the admin-gated saves.
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\CaseTypeCatalogueServiceInterface;
use OCA\OpenCatalogi\Contract\ServiceCatalogueServiceInterface;
use OCA\OpenCatalogi\Controller\BannerDismissals;
use OCA\OpenCatalogi\Controller\CommunityController;
use OCA\OpenCatalogi\Controller\CommunityPages;
use OCA\OpenCatalogi\Controller\DepublicationController;
use OCA\OpenCatalogi\Controller\InspectionController;
use OCA\OpenCatalogi\Controller\InspectionRecords;
use OCA\OpenCatalogi\Controller\NoticeBoardController;
use OCA\OpenCatalogi\Controller\RegisterAccess;
use OCA\OpenCatalogi\Controller\ServiceCatalogueController;
use OCA\OpenCatalogi\Service\Community\AtomFeedService;
use OCA\OpenCatalogi\Service\Community\BannerService;
use OCA\OpenCatalogi\Service\Community\MarkupRenderService;
use OCA\OpenCatalogi\Service\Community\NoticeBoardService;
use OCA\OpenCatalogi\Service\Community\StatusPageService;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\Publication\DepublicationService;
use OCA\OpenCatalogi\Service\Publication\IndexUnreachableException;
use OCA\OpenCatalogi\Service\Publication\InspectionService;
use OCA\OpenCatalogi\Service\Publication\NationalIndexService;
use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Every admin-gated save, called by a delegated administrator.
 *
 * @covers \OCA\OpenCatalogi\Controller\CommunityController
 * @covers \OCA\OpenCatalogi\Controller\DepublicationController
 * @covers \OCA\OpenCatalogi\Controller\InspectionController
 * @covers \OCA\OpenCatalogi\Controller\InspectionRecords
 * @covers \OCA\OpenCatalogi\Controller\NoticeBoardController
 * @covers \OCA\OpenCatalogi\Controller\ServiceCatalogueController
 * @uses \OCA\OpenCatalogi\Controller\AnswersCrossOriginRequests
 * @uses \OCA\OpenCatalogi\Controller\BannerDismissals
 * @uses \OCA\OpenCatalogi\Controller\CommunityPages
 * @uses \OCA\OpenCatalogi\Controller\CorsSettings
 * @uses \OCA\OpenCatalogi\Controller\ForwardsRegisterAccess
 * @uses \OCA\OpenCatalogi\Controller\ReadsOpenRegisterResults
 * @uses \OCA\OpenCatalogi\Controller\ReadsPublicationRegister
 * @uses \OCA\OpenCatalogi\Controller\RegisterAccess
 * @uses \OCA\OpenCatalogi\Controller\ResolvesRegisterConfiguration
 * @uses \OCA\OpenCatalogi\Service\Community\AtomFeedService
 * @uses \OCA\OpenCatalogi\Service\Community\BannerService
 * @uses \OCA\OpenCatalogi\Service\Community\MarkupRenderService
 * @uses \OCA\OpenCatalogi\Service\Community\NoticeBoardService
 * @uses \OCA\OpenCatalogi\Service\Community\StatusPageService
 * @uses \OCA\OpenCatalogi\Service\OpenRegisterLookup
 * @uses \OCA\OpenCatalogi\Service\Publication\DepublicationService
 * @uses \OCA\OpenCatalogi\Service\Publication\InspectionService
 * @uses \OCA\OpenCatalogi\Service\Publication\PublicationRuleService
 * @uses \OCA\OpenCatalogi\Service\ReadsObjectProperties
 */
class DelegatedAdminSavesTest extends TestCase {

	/**
	 * The schema each configuration key resolves to, named by slug so the store can find its block.
	 */
	private const SCHEMA_KEYS = [
		'inspection_schema' => 'inspection',
		'depublication_schema' => 'depublication',
		'service_status_schema' => 'serviceStatus',
		'notice_board_schema' => 'noticeBoard',
		'notice_schema' => 'notice',
		'case_type_definition_schema' => 'caseTypeDefinition',
	];

	private const DEPUBLICATION = [
		'publication' => 'pub-1',
		'reason' => 'Te vroeg gepubliceerd',
		'withdrawals' => [['channel' => 'koop']],
	];

	private const IMPORTED_CASE_TYPE = [
		'identifier' => 'ct-1',
		'title' => 'Melding',
		'source' => ['sourceId' => 'src', 'externalId' => 'ext'],
		'importedSnapshot' => ['title' => 'Melding'],
	];

	private BlockEnforcingStore $store;

	/**
	 * Build the store for a caller in the given groups, holding the objects a flow starts from.
	 *
	 * @param array<int, string> $groups The caller's groups.
	 *
	 * @return void
	 */
	private function storeFor(array $groups): void {
		$this->store = new BlockEnforcingStore(
			groups: $groups,
			seed: [
				'dep-1' => ['schema' => 'depublication', 'object' => self::DEPUBLICATION],
				'ct-1' => ['schema' => 'caseTypeDefinition', 'object' => self::IMPORTED_CASE_TYPE],
			]
		);
	}//end storeFor()

	/**
	 * The session of the delegated administrator.
	 *
	 * @return IUserSession The session.
	 */
	private function session(): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('gedelegeerd');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * A request answering these parameters.
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return IRequest The request.
	 */
	private function requestWith(array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		return $request;
	}//end requestWith()

	/**
	 * App configuration that resolves every schema key to its slug.
	 *
	 * @return IAppConfig The configuration.
	 */
	private function config(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (self::SCHEMA_KEYS[$key] ?? (str_ends_with($key, '_register') === true ? 'publication' : $default))
		);

		return $config;
	}//end config()

	/**
	 * Resolves the configured pairs from config(), without OpenRegister's resolver.
	 *
	 * @return OpenRegisterLookup The lookup.
	 */
	private function lookup(): OpenRegisterLookup {
		$config = $this->config();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($config) {
				if ($id === IAppConfig::class) {
					return $config;
				}

				throw new \RuntimeException('not available: ' . $id);
			}
		);

		return new OpenRegisterLookup($container, $this->createMock(IAppManager::class));
	}//end lookup()

	/**
	 * The catalogue service that reaches the store.
	 *
	 * @return ServiceCatalogueServiceInterface The service.
	 */
	private function catalogue(): ServiceCatalogueServiceInterface {
		$catalogue = $this->createMock(ServiceCatalogueServiceInterface::class);
		$catalogue->method('getObjectService')->willReturn($this->store);

		return $catalogue;
	}//end catalogue()

	/**
	 * Register access over the store.
	 *
	 * @return RegisterAccess The access.
	 */
	private function registers(): RegisterAccess {
		return new RegisterAccess($this->lookup(), $this->catalogue());
	}//end registers()

	/**
	 * Localisation that answers the key.
	 *
	 * @return IL10N The localisation.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return $l10n;
	}//end l10n()

	/**
	 * The inspection controller.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return InspectionController The controller.
	 */
	private function inspection(IRequest $request): InspectionController {
		return new InspectionController(
			'opencatalogi',
			$request,
			$this->config(),
			$this->l10n(),
			$this->session(),
			new InspectionRecords(new InspectionService(), $this->registers())
		);
	}//end inspection()

	/**
	 * The depublication controller, with channels that answer unreachable.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return DepublicationController The controller.
	 */
	private function depublication(IRequest $request): DepublicationController {
		$index = $this->getMockBuilder(NationalIndexService::class)
			->disableOriginalConstructor()
			->onlyMethods(['composeNotices', 'deliver', 'withdraw'])
			->getMock();
		$index->method('withdraw')->willThrowException(new IndexUnreachableException('No source.'));

		return new DepublicationController(
			'opencatalogi',
			$request,
			$this->l10n(),
			$this->session(),
			new DepublicationService($index, $this->createMock(LoggerInterface::class)),
			$this->registers()
		);
	}//end depublication()

	/**
	 * The community controller.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return CommunityController The controller.
	 */
	private function community(IRequest $request): CommunityController {
		return new CommunityController(
			'opencatalogi',
			$request,
			$this->config(),
			$this->l10n(),
			$this->session(),
			new CommunityPages(
				new StatusPageService(),
				new AtomFeedService(new PublicationRuleService(), new NoticeBoardService()),
				new MarkupRenderService(),
				$this->registers()
			)
		);
	}//end community()

	/**
	 * The notice board controller.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return NoticeBoardController The controller.
	 */
	private function noticeBoard(IRequest $request): NoticeBoardController {
		return new NoticeBoardController(
			'opencatalogi',
			$request,
			$this->session(),
			new BannerDismissals($this->config(), new BannerService()),
			new NoticeBoardService(),
			$this->registers()
		);
	}//end noticeBoard()

	/**
	 * The service catalogue controller, with a case type service that answers without a source.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return ServiceCatalogueController The controller.
	 */
	private function serviceCatalogue(IRequest $request): ServiceCatalogueController {
		$caseTypes = $this->createMock(CaseTypeCatalogueServiceInterface::class);
		$caseTypes->method('importDefinition')->willReturn(self::IMPORTED_CASE_TYPE);
		$caseTypes->method('diffAgainstSource')->willReturn(['title' => ['local' => 'Melding', 'source' => 'Melding!']]);
		$caseTypes->method('applyResync')->willReturnArgument(0);

		return new ServiceCatalogueController(
			'opencatalogi',
			$request,
			$this->config(),
			$this->lookup(),
			$this->l10n(),
			$this->catalogue(),
			$caseTypes
		);
	}//end serviceCatalogue()

	/**
	 * Every flow: its controller factory and method, the method's arguments, the request, and the schema it writes.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<int, string>, 3: array<string, mixed>, 4: string}>
	 */
	public static function flows(): array {
		$forged = ['openedBy' => 'vervalst', 'depublishedBy' => 'vervalst', 'setBy' => 'vervalst', 'userId' => 'vervalst'];

		return [
			'inspection open' => [
				'inspection',
				'open',
				[],
				$forged + ['record' => ['id' => 'r-1'], 'recordType' => ['inspectionTermDays' => 42], 'documents' => ['doc-1']],
				'inspection',
			],
			'depublish' => [
				'depublication',
				'depublish',
				[],
				$forged + ['publication' => ['id' => 'pub-1'], 'reason' => 'Te vroeg gepubliceerd', 'channels' => ['koop']],
				'depublication',
			],
			'acknowledge a withdrawal' => [
				'depublication',
				'acknowledgeWithdrawal',
				[],
				$forged + ['depublication' => 'dep-1', 'channel' => 'koop', 'answer' => 'verwijderd'],
				'depublication',
			],
			'set a status' => [
				'community',
				'setStatus',
				[],
				$forged + ['component' => 'Zoeken', 'state' => 'degraded', 'message' => 'Traag'],
				'serviceStatus',
			],
			'save a notice board' => [
				'noticeBoard',
				'saveNoticeBoard',
				[],
				$forged + ['board' => ['catalog' => 'c-1', 'title' => 'Bord']],
				'noticeBoard',
			],
			'save a notice' => [
				'noticeBoard',
				'saveNotice',
				[],
				$forged + ['notice' => ['board' => 'b-1', 'title' => 'Werk', 'startDate' => '2026-11-01T00:00:00+00:00', 'endDate' => '2026-11-30T00:00:00+00:00']],
				'notice',
			],
			'import a case type' => [
				'serviceCatalogue',
				'importCaseType',
				[],
				$forged + ['sourceId' => 'src', 'externalId' => 'ext'],
				'caseTypeDefinition',
			],
			'apply a resynchronisation' => [
				'serviceCatalogue',
				'applyResync',
				['ct-1'],
				$forged + ['accepted' => ['title']],
				'caseTypeDefinition',
			],
		];
	}//end flows()

	/**
	 * A delegated administrator completes the flow, and the record reaches the schema.
	 *
	 * @param string $factory The controller factory.
	 * @param string $method The route's method.
	 * @param array<int, string> $arguments The method's arguments.
	 * @param array<string, mixed> $params The request.
	 * @param string $schema The schema written.
	 *
	 * @return void
	 */
	#[DataProvider('flows')]
	public function testADelegatedAdministratorCompletesTheFlow(string $factory, string $method, array $arguments, array $params, string $schema): void {
		$this->storeFor(['gedelegeerden']);

		$response = $this->{$factory}($this->requestWith($params))->{$method}(...$arguments);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertLessThan(300, $response->getStatus(), json_encode($response->getData()));
		$this->assertCount(1, $this->store->saves);
		$this->assertSame($schema, $this->store->saves[0]['schema']);
		$this->assertStringNotContainsString('vervalst', json_encode($this->store->saves[0]['object']));
	}//end testADelegatedAdministratorCompletesTheFlow()

	/**
	 * The identity on a record is the session's, whatever the request says.
	 *
	 * @param string $factory The controller factory.
	 * @param string $method The route's method.
	 * @param string $property The property holding the identity.
	 * @param array<string, mixed> $params The request.
	 *
	 * @return void
	 */
	#[DataProvider('identities')]
	public function testTheRecordNamesTheSessionUser(string $factory, string $method, string $property, array $params): void {
		$this->storeFor(['gedelegeerden']);

		$this->{$factory}($this->requestWith($params))->{$method}();

		$this->assertSame('gedelegeerd', $this->store->saves[0]['object'][$property]);
	}//end testTheRecordNamesTheSessionUser()

	/**
	 * The flows whose record names who acted.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
	 */
	public static function identities(): array {
		$flows = self::flows();

		return [
			'inspection open' => ['inspection', 'open', 'openedBy', $flows['inspection open'][3]],
			'depublish' => ['depublication', 'depublish', 'depublishedBy', $flows['depublish'][3]],
			'set a status' => ['community', 'setStatus', 'stateSetBy', $flows['set a status'][3]],
		];
	}//end identities()

	/**
	 * The store refuses a save that asks RBAC, so the flows above prove something.
	 *
	 * @param string $factory The controller factory.
	 * @param string $method The route's method.
	 * @param array<int, string> $arguments The method's arguments.
	 * @param array<string, mixed> $params The request.
	 * @param string $schema The schema written.
	 *
	 * @return void
	 */
	#[DataProvider('flows')]
	public function testTheShippedBlockRefusesThatCallerWhenRbacIsAsked(string $factory, string $method, array $arguments, array $params, string $schema): void {
		$this->storeFor(['gedelegeerden']);

		$this->expectException(\RuntimeException::class);
		$this->store->saveObject(object: [], register: 'publication', schema: $schema);
	}//end testTheShippedBlockRefusesThatCallerWhenRbacIsAsked()

	/**
	 * A caller the admin setting does not admit never reaches the save: the route carries the attribute and nothing that opens it.
	 *
	 * @param string $factory The controller factory.
	 * @param string $method The route's method.
	 *
	 * @return void
	 */
	#[DataProvider('flows')]
	public function testARefusedCallerIsStoppedByTheAdminSetting(string $factory, string $method): void {
		$class = [
			'inspection' => InspectionController::class,
			'depublication' => DepublicationController::class,
			'community' => CommunityController::class,
			'noticeBoard' => NoticeBoardController::class,
			'serviceCatalogue' => ServiceCatalogueController::class,
		][$factory];
		$reflection = new ReflectionMethod($class, $method);

		$attributes = $reflection->getAttributes(AuthorizedAdminSetting::class);
		$this->assertCount(1, $attributes);
		$this->assertSame(['settings' => OpenCatalogiAdmin::class], $attributes[0]->getArguments());
		$this->assertStringNotContainsString('@NoAdminRequired', (string)$reflection->getDocComment());
		$this->assertStringNotContainsString('@PublicPage', (string)$reflection->getDocComment());
		$this->assertSame([], $reflection->getAttributes('OCP\AppFramework\Http\Attribute\NoAdminRequired'));
		$this->assertSame([], $reflection->getAttributes('OCP\AppFramework\Http\Attribute\PublicPage'));
	}//end testARefusedCallerIsStoppedByTheAdminSetting()

	/**
	 * Refused input stores nothing, with RBAC out of the way: the body's own checks still run.
	 *
	 * @return void
	 */
	public function testAnAcknowledgementForAChannelNeverWrittenToIsRefusedAndNotStored(): void {
		$this->storeFor(['gedelegeerden']);

		$response = $this->depublication($this->requestWith(['depublication' => 'dep-1', 'channel' => 'oneigenlijk']))->acknowledgeWithdrawal();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame([], $this->store->saves);
	}//end testAnAcknowledgementForAChannelNeverWrittenToIsRefusedAndNotStored()

	/**
	 * A resynchronisation works on the stored definition, its snapshot included, for a delegated administrator.
	 *
	 * @return void
	 */
	public function testAResynchronisationReadsTheStoredSnapshot(): void {
		$this->storeFor(['gedelegeerden']);

		$response = $this->serviceCatalogue($this->requestWith(['accepted' => ['title']]))->applyResync('ct-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['title' => 'Melding'], $this->store->saves[0]['object']['importedSnapshot']);
	}//end testAResynchronisationReadsTheStoredSnapshot()
}//end class

/**
 * An in-memory ObjectService that applies a schema's shipped block unless told not to.
 *
 * OpenRegister is not on the autoload path in a standalone checkout. Reading the
 * block from the shipped documents keeps this double honest about who is refused.
 */
final class BlockEnforcingStore {

	/** @var array<int, array<string, mixed>> */
	public array $saves = [];

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $groups The caller's groups.
	 * @param array<string, array{schema: string, object: array<string, mixed>}> $seed Stored objects, by id.
	 */
	public function __construct(
		private readonly array $groups,
		private readonly array $seed,
	) {
	}//end __construct()

	/**
	 * Save, refusing a caller the schema's block does not name unless RBAC is off.
	 *
	 * @param array<string, mixed> $object The object.
	 * @param array<int, string> $extend Ignored.
	 * @param string|null $register The register.
	 * @param string|null $schema The schema.
	 * @param string|null $uuid The id; an update when known.
	 * @param bool $_rbac Whether to apply the block.
	 * @param bool $_multitenancy Ignored.
	 * @param bool $_unowned Stamp the system identity as owner.
	 *
	 * @return array<string, mixed> The object with its id.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function saveObject(
		array $object,
		array $extend = [],
		?string $register = null,
		?string $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $_unowned = false,
	): array {
		$action = 'create';
		if (isset($this->seed[(string)$uuid]) === true) {
			$action = 'update';
		}

		$this->refuseUnlessGranted(schema: (string)$schema, action: $action, rbac: $_rbac);
		$this->saves[] = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid, 'rbac' => $_rbac, 'unowned' => $_unowned];

		return $object + ['id' => ($uuid !== null && $uuid !== '' ? $uuid : 'new-id')];
	}//end saveObject()

	/**
	 * Find a stored object, refusing a caller the schema's block does not name unless RBAC is off.
	 *
	 * @param string $id The id.
	 * @param string|null $register The register.
	 * @param string|null $schema The schema.
	 * @param bool $_rbac Whether to apply the block.
	 *
	 * @return array<string, mixed> The object with its id.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function find(string $id, ?string $register = null, ?string $schema = null, bool $_rbac = true): array {
		$this->refuseUnlessGranted(schema: (string)$schema, action: 'read', rbac: $_rbac);

		return $this->seed[$id]['object'] + ['id' => $id];
	}//end find()

	/**
	 * Apply the shipped block.
	 *
	 * @param string $schema The schema slug.
	 * @param string $action The action.
	 * @param bool $rbac Whether RBAC was asked.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the caller is refused.
	 */
	private function refuseUnlessGranted(string $schema, string $action, bool $rbac): void {
		if ($rbac === false) {
			return;
		}

		$grants = [];
		foreach (glob(__DIR__ . '/../../../lib/Settings/register.d/*.json') as $file) {
			$document = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
			$grants = array_merge($grants, ($document['components']['schemas'][$schema]['authorization'][$action] ?? []));
		}

		if (array_intersect($grants, array_merge($this->groups, ['public', 'authenticated'])) === []) {
			throw new \RuntimeException('Not authorized to ' . $action . ' ' . $schema);
		}
	}//end refuseUnlessGranted()
}//end class
