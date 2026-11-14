<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rules CatalogAccessService writes, enforced by OpenRegister itself: the
 * authorization is computed from a restricted catalogue, put on a real
 * OpenRegister schema and asked of OpenRegister's own PermissionHandler and
 * ConditionMatcher, with only the users and their groups mocked. OpenCatalogi
 * never decides access (ADR-022), so this is the one place the decision of the
 * written rules is checked. Without OpenRegister next to the app (set
 * OPENREGISTER_PATH) the tests are skipped with the reason.
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\CatalogAccessService;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\Permission\PermissionCallerServices;
use OCA\OpenRegister\Service\Object\Permission\PermissionDataServices;
use OCA\OpenRegister\Service\Object\Permission\PermissionDenyServices;
use OCA\OpenRegister\Service\Object\Permission\PermissionDerivedGrantServices;
use OCA\OpenRegister\Service\Object\Permission\PermissionGrantServices;
use OCA\OpenRegister\Service\Object\Permission\PermissionRuleServices;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * OpenRegister refuses and allows what the written staff access says.
 *
 * @covers \OCA\OpenCatalogi\Service\CatalogAccessService
 */
class CatalogAccessEnforcementTest extends TestCase {

	/**
	 * The publication schema's id.
	 */
	private const SCHEMA_ID = 7;

	/**
	 * The publication schema's rules before any restriction, as publication_register.json ships them.
	 */
	private const SHIPPED = [
		'read' => [
			[
				'group' => 'public',
				'match' => ['publicationDate' => ['$lte' => '$now'], 'depublicationDate' => ['$exists' => false]],
				'crossOrganisation' => true,
			],
			'authenticated',
		],
	];

	/**
	 * The groups of each user.
	 */
	private const GROUPS = [
		'woo-member' => ['woo-team'],
		'reader' => ['griffie'],
		'outsider' => ['staff'],
	];

	/**
	 * The catalogue "Woo requests": restricted, edit group woo-team, read group griffie.
	 *
	 * @var array<string, mixed>
	 */
	private const WOO_REQUESTS = [
		'title' => 'Woo requests',
		'schemas' => [self::SCHEMA_ID],
		'filters' => ['wooCategory' => 'infocat014'],
		'staffAccess' => ['restricted' => true, 'readGroups' => ['griffie'], 'editGroups' => ['woo-team']],
	];

	/**
	 * Skip without the real OpenRegister; its CI stub has no permission chain.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		if (class_exists(PermissionCallerServices::class) === false || class_exists(OperatorEvaluator::class) === false) {
			$this->markTestSkipped('OpenRegister is not installed next to OpenCatalogi (set OPENREGISTER_PATH); its PermissionHandler enforces these rules.');
		}
	}//end setUp()

	/**
	 * The schema with the rules CatalogAccessService computes for these catalogues.
	 *
	 * @param array<int, array<string, mixed>> $catalogs The catalogues.
	 *
	 * @return Schema The schema.
	 */
	private function schemaFor(array $catalogs): Schema {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$service = new CatalogAccessService(
			config: $config,
			container: $this->createMock(ContainerInterface::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		$schema = new Schema();
		$schema->setId(self::SCHEMA_ID);
		$schema->setAuthorization($service->authorizationFor(schemaId: self::SCHEMA_ID, current: self::SHIPPED, catalogs: $catalogs));

		return $schema;
	}//end schemaFor()

	/**
	 * OpenRegister's permission handler, with its real condition matcher; only users and groups are mocked.
	 *
	 * @return PermissionHandler The handler.
	 */
	private function openRegister(): PermissionHandler {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if (array_key_exists($uid, self::GROUPS) === false) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				return $user;
			}
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturnCallback(fn (IUser $user): array => self::GROUPS[$user->getUID()]);
		$groups->method('isAdmin')->willReturn(false);

		$session = $this->createMock(IUserSession::class);
		$logger = $this->createMock(LoggerInterface::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not wired in this test'));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnArgument(2);
		$appConfig->method('getValueString')->willReturnArgument(2);

		$matcher = new ConditionMatcher(
			userSession: $session,
			container: $container,
			operatorEvaluator: new OperatorEvaluator(logger: $logger),
			logger: $logger,
			groupManager: $groups,
		);

		return new PermissionHandler(
			new PermissionCallerServices(userSession: $session, userManager: $users, groupManager: $groups, appConfig: $appConfig),
			new PermissionDataServices(
				schemaMapper: $this->createMock(SchemaMapper::class),
				objectEntityMapper: $this->createMock(MagicMapper::class),
				container: $container,
				logger: $logger,
			),
			new PermissionRuleServices(conditionMatcher: $matcher),
			new PermissionGrantServices(deny: new PermissionDenyServices(), derived: new PermissionDerivedGrantServices()),
		);
	}//end openRegister()

	/**
	 * An unpublished publication with this Woo category.
	 *
	 * @param string $category The category.
	 *
	 * @return ObjectEntity The publication.
	 */
	private function publication(string $category): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('6f1c1e0a-0000-4000-8000-000000000012');
		$object->setSchema((string)self::SCHEMA_ID);
		$object->setOwner('somebody-else');
		$object->setObject(['title' => 'Request 12', 'wooCategory' => $category]);

		return $object;
	}//end publication()

	/**
	 * Whether OpenRegister lets this user do this to this publication.
	 *
	 * @param Schema $schema The schema.
	 * @param string $action The action.
	 * @param string $userId The user.
	 * @param ObjectEntity $object The publication.
	 *
	 * @return bool The verdict.
	 */
	private function allowed(Schema $schema, string $action, string $userId, ObjectEntity $object): bool {
		return $this->openRegister()->hasPermission(
			schema: $schema,
			action: $action,
			userId: $userId,
			objectOwner: $object->getOwner(),
			object: $object
		);
	}//end allowed()

	/**
	 * Spec scenario "Only the Woo team edits Woo requests".
	 *
	 * @return void
	 */
	public function testAUserOutsideTheEditGroupsIsRefused(): void {
		$schema = $this->schemaFor(catalogs: [self::WOO_REQUESTS]);
		$request = $this->publication(category: 'infocat014');

		$this->assertFalse($this->allowed(schema: $schema, action: 'update', userId: 'outsider', object: $request));
		$this->assertFalse($this->allowed(schema: $schema, action: 'read', userId: 'outsider', object: $request));
		$this->assertTrue($this->allowed(schema: $schema, action: 'update', userId: 'woo-member', object: $request));
		$this->assertTrue($this->allowed(schema: $schema, action: 'delete', userId: 'woo-member', object: $request));
	}//end testAUserOutsideTheEditGroupsIsRefused()

	/**
	 * A read group may see the catalogue's unpublished publications but not change them.
	 *
	 * @return void
	 */
	public function testAReadGroupReadsButDoesNotEdit(): void {
		$schema = $this->schemaFor(catalogs: [self::WOO_REQUESTS]);
		$request = $this->publication(category: 'infocat014');

		$this->assertTrue($this->allowed(schema: $schema, action: 'read', userId: 'reader', object: $request));
		$this->assertFalse($this->allowed(schema: $schema, action: 'update', userId: 'reader', object: $request));
	}//end testAReadGroupReadsButDoesNotEdit()

	/**
	 * The rule is narrowed to the catalogue's filters: an edit group has no rights outside them.
	 *
	 * @return void
	 */
	public function testTheEditGroupHasNoRightsOutsideTheCataloguesFilters(): void {
		$schema = $this->schemaFor(catalogs: [self::WOO_REQUESTS]);
		$other = $this->publication(category: 'infocat001');

		$this->assertFalse($this->allowed(schema: $schema, action: 'update', userId: 'woo-member', object: $other));
	}//end testTheEditGroupHasNoRightsOutsideTheCataloguesFilters()

	/**
	 * Without a restriction the shipped rules stay, so every signed-in user still reads.
	 *
	 * @return void
	 */
	public function testAnUnrestrictedCatalogueKeepsReadingOpenToStaff(): void {
		$open = self::WOO_REQUESTS;
		$open['staffAccess'] = ['restricted' => false];
		$schema = $this->schemaFor(catalogs: [$open]);

		$this->assertSame(self::SHIPPED, $schema->getAuthorization());
		$this->assertTrue($this->allowed(schema: $schema, action: 'read', userId: 'outsider', object: $this->publication(category: 'infocat014')));
	}//end testAnUnrestrictedCatalogueKeepsReadingOpenToStaff()
}//end class
