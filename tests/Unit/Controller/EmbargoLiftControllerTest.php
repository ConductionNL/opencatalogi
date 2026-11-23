<?php

/**
 * EmbargoLiftControllerTest
 *
 * Tests for `POST /api/publications/{id}/lift-embargo` (REQ-PSG-002).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace Unit\Controller;

use DateTimeImmutable;
use OCA\OpenCatalogi\Contract\OpenRegisterServicesInterface;
use OCA\OpenCatalogi\Contract\PublicationRightsInterface;
use OCA\OpenCatalogi\Controller\EmbargoLiftController;
use OCA\OpenCatalogi\Controller\EmbargoLifts;
use OCA\OpenCatalogi\Controller\PublicationTransitions;
use OCA\OpenCatalogi\Service\Publication\EmbargoGuard;
use OCA\OpenCatalogi\Service\Publication\PublicationDocumentCount;
use OCA\OpenCatalogi\Service\Publication\PublicationReviewPolicy;
use OCA\OpenCatalogi\Service\Publication\PublicationStateService;
use OCA\OpenCatalogi\Service\Publication\PublishTransition;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The route, the refusals in order, the publish and the one audit entry.
 *
 * @covers \OCA\OpenCatalogi\Controller\EmbargoLiftController
 * @covers \OCA\OpenCatalogi\Controller\EmbargoLifts
 * @uses   \OCA\OpenCatalogi\Service\Publication\EmbargoGuard
 * @uses   \OCA\OpenCatalogi\Service\Publication\PublishTransition
 */
final class EmbargoLiftControllerTest extends TestCase {

	/**
	 * The stored publication; empty means not found; a Throwable is thrown by find().
	 *
	 * @var mixed
	 */
	private mixed $publication = [];

	/**
	 * The caller's groups.
	 *
	 * @var list<string>
	 */
	private array $groups = ['admin'];

	/**
	 * Whether the caller may update the publication.
	 *
	 * @var boolean
	 */
	private bool $mayUpdate = true;

	/**
	 * Whether a session user is present.
	 *
	 * @var boolean
	 */
	private bool $signedIn = true;

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = ['reason' => 'Raad heeft het besluit vervroegd'];

	/**
	 * The saves made, each `[object, uuid, rbac]`.
	 *
	 * @var list<array{0: array<string, mixed>, 1: string|null, 2: bool}>
	 */
	public array $saves = [];

	/**
	 * The transitions run, each `[id, action, data]`.
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public array $transitions = [];

	/**
	 * The audit entries written, each `[old, new, action, context]`.
	 *
	 * @var list<array{0: mixed, 1: mixed, 2: string|null, 3: array<string, mixed>|null}>
	 */
	public array $audits = [];

	/**
	 * Whether the audit write throws.
	 *
	 * @var boolean
	 */
	public bool $auditFails = false;

	/**
	 * A publication under embargo until next week.
	 *
	 * @var string
	 */
	private string $embargo;

	/**
	 * Set the embargo a week ahead.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->embargo = (new DateTimeImmutable(datetime: '+7 days'))->format(format: DATE_ATOM);
	}//end setUp()

	/**
	 * An ObjectEntity double answering $properties.
	 *
	 * @param array<string, mixed> $properties The properties.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $properties): ObjectEntity {
		$entity = $this->getMockBuilder(className: ObjectEntity::class)->disableOriginalConstructor()->onlyMethods(['jsonSerialize'])->getMock();
		$entity->method('jsonSerialize')->willReturn($properties);
		return $entity;
	}//end entity()

	/**
	 * The ObjectService double: find answers the stored publication, saveObject records.
	 *
	 * @return ObjectService
	 */
	private function objectService(): ObjectService {
		$objects = $this->getMockBuilder(className: ObjectService::class)->disableOriginalConstructor()->onlyMethods(['find', 'saveObject'])->getMock();
		$objects->method('find')->willReturnCallback(
			function () {
				if ($this->publication instanceof \Throwable) {
					throw $this->publication;
				}

				if ($this->publication === []) {
					throw new DoesNotExistException('gone');
				}

				return $this->entity(properties: $this->publication);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true) {
				$this->saves[] = [$object, $options->uuid, $_rbac];
				return $this->entity(properties: $object);
			}
		);

		return $objects;
	}//end objectService()

	/**
	 * The controller over the real EmbargoLifts and PublishTransition, with recording doubles.
	 *
	 * @return EmbargoLiftController
	 */
	private function controller(): EmbargoLiftController {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturnCallback(fn (): array => $this->groups);

		$engine = new class ($this) {
			/**
			 * Constructor.
			 *
			 * @param EmbargoLiftControllerTest $test The test that records the transitions.
			 */
			public function __construct(private readonly EmbargoLiftControllerTest $test) {
			}

			/**
			 * Record a transition.
			 *
			 * @param string               $objectId The object id.
			 * @param string               $action   The transition.
			 * @param array<string, mixed> $data     The inputs.
			 *
			 * @return object
			 */
			public function transition(string $objectId, string $action, array $data = []): object {
				$this->test->transitions[] = [$objectId, $action, $data];
				return (object)['id' => $objectId];
			}
		};
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($engine);

		$states = new PublicationStateService();
		$registers = $this->getMockBuilder(className: PublicationTransitions::class)
			->disableOriginalConstructor()
			->onlyMethods(['registerConfiguration', 'getObjectService', 'publishedRecord'])
			->getMock();
		$registers->method('registerConfiguration')->willReturn(['register' => '19', 'schema' => '173']);
		$registers->method('getObjectService')->willReturn($this->objectService());
		$registers->method('publishedRecord')->willReturnCallback(
			static fn (array $publication): array => $states->publishedRecord(publication: $publication)
		);

		$rights = $this->createMock(originalClassName: PublicationRightsInterface::class);
		$rights->method('mayUpdate')->willReturnCallback(fn (): bool => $this->mayUpdate);

		$audit = new class ($this) {
			/**
			 * Constructor.
			 *
			 * @param EmbargoLiftControllerTest $test The test that records the entries.
			 */
			public function __construct(private readonly EmbargoLiftControllerTest $test) {
			}

			/**
			 * Record an audit entry, or throw when the test says the trail is unavailable.
			 *
			 * @param mixed                     $old            The old state.
			 * @param mixed                     $new            The new state.
			 * @param string|null               $action         The action.
			 * @param array<string, mixed>|null $cascadeContext The context.
			 *
			 * @return object
			 */
			public function createAuditTrail(mixed $old = null, mixed $new = null, ?string $action = null, ?array $cascadeContext = null): object {
				if ($this->test->auditFails === true) {
					throw new RuntimeException('The audit trail is unavailable.');
				}

				$this->test->audits[] = [$old, $new, $action, $cascadeContext];
				return new \stdClass();
			}
		};
		$openRegister = $this->createMock(originalClassName: OpenRegisterServicesInterface::class);
		$openRegister->method('installedService')->willReturn($audit);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('griffier');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function () use ($user): ?IUser {
				if ($this->signedIn === false) {
					return null;
				}

				return $user;
			}
		);

		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($this->params[$key] ?? $default));

		$publisher = new PublishTransition(
			container: $container,
			review: new PublicationReviewPolicy(appConfig: $config),
			embargo: new EmbargoGuard(config: $config, groups: $groupManager, logger: new NullLogger()),
			documents: $this->createMock(originalClassName: PublicationDocumentCount::class)
		);

		return new EmbargoLiftController(
			request: $request,
			userSession: $session,
			lifts: new EmbargoLifts(rights: $rights, registers: $registers, publisher: $publisher, openRegister: $openRegister)
		);
	}//end controller()

	/**
	 * Assert nothing was saved, transitioned or logged.
	 *
	 * @return void
	 */
	private function assertNothingWritten(): void {
		$this->assertSame(expected: [], actual: $this->saves);
		$this->assertSame(expected: [], actual: $this->transitions);
		$this->assertSame(expected: [], actual: $this->audits);
	}//end assertNothingWritten()

	/**
	 * The route reaches lift.
	 *
	 * @return void
	 */
	public function testTheRouteReachesLift(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$matches = array_values(
			array_filter(
				$routes['routes'],
				static fn (array $route): bool => $route['url'] === '/api/publications/{id}/lift-embargo' && $route['verb'] === 'POST'
			)
		);

		$this->assertCount(expectedCount: 1, haystack: $matches);
		$this->assertSame(expected: 'embargoLift#lift', actual: $matches[0]['name']);
		$this->assertTrue(condition: method_exists(EmbargoLiftController::class, 'lift'));
	}//end testTheRouteReachesLift()

	/**
	 * A user outside the lift groups is refused with 403 and nothing changes.
	 *
	 * @return void
	 */
	public function testALiftByAUserOutsideTheGroupsIsRefused(): void {
		$this->groups = ['editors'];
		$this->publication = ['id' => 'p1', 'status' => 'published', 'publicationDate' => $this->embargo];

		$response = $this->controller()->lift(id: 'p1');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'not-allowed-to-lift'], actual: $response->getData());
		$this->assertNothingWritten();
	}//end testALiftByAUserOutsideTheGroupsIsRefused()

	/**
	 * A lift group member who may not update the publication is refused as well.
	 *
	 * @return void
	 */
	public function testALiftWithoutTheUpdateRightIsRefused(): void {
		$this->mayUpdate = false;
		$this->publication = ['id' => 'p1', 'status' => 'published', 'publicationDate' => $this->embargo];

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller()->lift(id: 'p1')->getStatus());
		$this->assertNothingWritten();
	}//end testALiftWithoutTheUpdateRightIsRefused()

	/**
	 * A lift without a reason, or with an unreadable date, is refused with 422 and nothing changes.
	 *
	 * @return void
	 */
	public function testALiftWithoutAReasonIsRefused(): void {
		$this->publication = ['id' => 'p1', 'status' => 'published', 'publicationDate' => $this->embargo];

		$this->params = ['reason' => '   '];
		$response = $this->controller()->lift(id: 'p1');
		$this->assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'reason-missing'], actual: $response->getData());

		$this->params = ['reason' => 'Vervroegd', 'publicationDate' => 'not a date'];
		$response = $this->controller()->lift(id: 'p1');
		$this->assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'invalid-date'], actual: $response->getData());

		$this->assertNothingWritten();
	}//end testALiftWithoutAReasonIsRefused()

	/**
	 * A scheduled publication is published now with RBAC, and one audit entry names the user, the reason and both dates.
	 *
	 * @return void
	 */
	public function testALiftPublishesAndWritesOneAuditEntry(): void {
		$this->publication = ['id' => 'p1', 'status' => 'published', 'publicationDate' => $this->embargo, 'title' => 'Besluit'];

		$response = $this->controller()->lift(id: 'p1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$data = $response->getData();
		$this->assertSame(expected: $this->embargo, actual: $data['previousPublicationDate']);
		$this->assertLessThan(expected: new DateTimeImmutable(datetime: $this->embargo), actual: new DateTimeImmutable(datetime: $data['publicationDate']));

		$this->assertCount(expectedCount: 1, haystack: $this->saves);
		[$record, $uuid, $rbac] = $this->saves[0];
		$this->assertSame(expected: $data['publicationDate'], actual: $record['publicationDate']);
		$this->assertSame(expected: 'p1', actual: $uuid);
		$this->assertTrue(condition: $rbac);
		$this->assertArrayNotHasKey(key: 'id', array: $record);
		$this->assertSame(expected: [], actual: $this->transitions);

		$this->assertCount(expectedCount: 1, haystack: $this->audits);
		[$old, $new, $action, $context] = $this->audits[0];
		$this->assertInstanceOf(expected: ObjectEntity::class, actual: $old);
		$this->assertInstanceOf(expected: ObjectEntity::class, actual: $new);
		$this->assertSame(expected: 'publication.embargo.lifted', actual: $action);
		$this->assertSame(
			expected: [
				'user' => 'griffier',
				'reason' => 'Raad heeft het besluit vervroegd',
				'previousPublicationDate' => $this->embargo,
				'publicationDate' => $data['publicationDate'],
			],
			actual: $context
		);
	}//end testALiftPublishesAndWritesOneAuditEntry()

	/**
	 * An approved publication dated ahead runs the publish transition with the given moment.
	 *
	 * @return void
	 */
	public function testALiftOfAnApprovedPublicationRunsThePublishTransition(): void {
		$this->publication = ['id' => 'p1', 'status' => 'approved', 'publicationDate' => $this->embargo];
		$this->params = ['reason' => 'Vervroegd', 'publicationDate' => '2026-12-01T09:00:00+01:00'];

		$response = $this->controller()->lift(id: 'p1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: '2026-12-01T09:00:00+01:00', actual: $response->getData()['publicationDate']);
		$this->assertSame(
			expected: [['p1', 'publish', ['publicationDate' => '2026-12-01T09:00:00+01:00', 'depublicationDate' => null]]],
			actual: $this->transitions
		);
		$this->assertSame(expected: [], actual: $this->saves);
		$this->assertCount(expectedCount: 1, haystack: $this->audits);
	}//end testALiftOfAnApprovedPublicationRunsThePublishTransition()

	/**
	 * No session answers 401, an unreadable publication 404, a register failure 503.
	 *
	 * @return void
	 */
	public function testTheGuardsBeforeTheRight(): void {
		$this->signedIn = false;
		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller()->lift(id: 'p1')->getStatus());

		$this->signedIn = true;
		$this->publication = [];
		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $this->controller()->lift(id: 'p1')->getStatus());

		$this->publication = new RuntimeException('register down');
		$this->assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $this->controller()->lift(id: 'p1')->getStatus());

		$this->assertNothingWritten();
	}//end testTheGuardsBeforeTheRight()

	/**
	 * A state no publish leaves from answers 422 and logs nothing.
	 *
	 * @return void
	 */
	public function testAStateWithoutAPublishIsRefused(): void {
		$this->publication = ['id' => 'p1', 'status' => 'archived', 'publicationDate' => $this->embargo];

		$response = $this->controller()->lift(id: 'p1');

		$this->assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'transition-not-allowed', 'state' => 'archived'], actual: $response->getData());
		$this->assertNothingWritten();
	}//end testAStateWithoutAPublishIsRefused()

	/**
	 * A lift whose audit entry cannot be written says so, with both dates.
	 *
	 * @return void
	 */
	public function testALiftThatCannotBeLoggedSaysSo(): void {
		$this->auditFails = true;
		$this->publication = ['id' => 'p1', 'status' => 'published', 'publicationDate' => $this->embargo];

		$response = $this->controller()->lift(id: 'p1');

		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
		$this->assertSame(expected: 'lift-not-logged', actual: $response->getData()['error']);
		$this->assertSame(expected: $this->embargo, actual: $response->getData()['previousPublicationDate']);
		$this->assertCount(expectedCount: 1, haystack: $this->saves);
	}//end testALiftThatCannotBeLoggedSaysSo()
}//end class
