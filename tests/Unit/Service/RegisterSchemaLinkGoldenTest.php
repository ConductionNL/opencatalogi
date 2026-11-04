<?php

/**
 * Golden test for RegisterSchemaLinkService::reconcile().
 *
 * Every world is recorded once and replayed from the fixture: the register
 * lookups, the schema list and configuration written back, the update and the
 * tables provisioned. OpenRegister's RegisterMapper and MagicMapper are doubled
 * from classes whose methods exist both in the tests/Stubs tree and in the real
 * app, so the recording means the same locally and in CI.
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

namespace Unit\Service;

use OCA\OpenCatalogi\Service\RegisterSchemaLinkService;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Golden test for RegisterSchemaLinkService.
 *
 * @covers \OCA\OpenCatalogi\Service\RegisterSchemaLinkService
 */
class RegisterSchemaLinkGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/register-schema-link-golden.json';

	/**
	 * What the doubles were asked, in order.
	 *
	 * @var list<mixed>
	 */
	private array $log = [];

	/**
	 * The recorded worlds.
	 *
	 * A schema is [id, slug]; `register` says where the publication register comes
	 * from: the import ('import'), the mapper ('mapper') or nowhere (null).
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function worlds(): array {
		$schemas = [[28, 'publication'], [29, 'document'], [30, 'theme']];
		return [
			'import register, two new schemas' => [['register' => 'import', 'held' => [28], 'config' => [], 'schemas' => $schemas]],
			'mapper register' => [['register' => 'mapper', 'held' => ['28', 'x', 29.0], 'config' => ['schemas' => ['theme' => ['magicMapping' => false]]], 'schemas' => $schemas]],
			'no register anywhere' => [['register' => null, 'held' => [], 'config' => [], 'schemas' => $schemas]],
			'every schema held' => [['register' => 'import', 'held' => [28, 29, 30], 'config' => [], 'schemas' => $schemas]],
			'unusable schemas and duplicates' => [['register' => 'import', 'held' => [], 'config' => ['other' => 1], 'schemas' => [[31, 'a'], 'not an object', [31, 'a-again'], ['noslug', 32], [33, 'b']]]],
			'one table fails to provision' => [['register' => 'import', 'held' => [], 'config' => [], 'schemas' => [[29, 'fails'], [30, 'theme']]]],
			'update fails' => [['register' => 'import', 'held' => [], 'config' => [], 'schemas' => $schemas, 'updateFails' => true]],
			'openregister not installed' => [['register' => 'mapper', 'held' => [], 'config' => [], 'schemas' => $schemas, 'installed' => false]],
			'other import registers before the mapper' => [['register' => 'mapper', 'held' => [], 'config' => [], 'schemas' => [[40, 'x']], 'extraRegisters' => true]],
			'no schemas in the import' => [['register' => 'import', 'held' => [], 'config' => [], 'schemas' => null]],
		];

	}//end worlds()

	/**
	 * Replay a world and compare it to the recording.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return void
	 */
	#[DataProvider('worlds')]
	public function testReconcileMatchesTheRecording(array $world): void {
		$this->log = [];
		$register = $this->register(world: $world);
		$import = ['registers' => []];
		if (($world['extraRegisters'] ?? false) === true) {
			$import['registers'] = ['a string', $this->register(world: ['slug' => 'other'] + $world), new \stdClass()];
		}

		if ($world['register'] === 'import') {
			$import['registers'][] = $register;
		}

		if ($world['schemas'] !== null) {
			$import['schemas'] = array_map(fn ($schema) => $this->schema(schema: $schema), $world['schemas']);
		}

		$this->service(world: $world, register: $register)->reconcile(importResult: $import);

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$name = (string)$this->dataName();
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = json_decode((string)json_encode($this->log), true);
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($this->log), true));

	}//end testReconcileMatchesTheRecording()

	/**
	 * A register double that logs what is written to it.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return Register
	 */
	private function register(array $world): Register {
		$register = $this->getMockBuilder(Register::class)
			->disableOriginalConstructor()
			->onlyMethods(['getSchemas', 'getConfiguration', 'setSchemas', 'setConfiguration'])
			->addMethods(['getSlug'])
			->getMock();
		$slug = ($world['slug'] ?? 'publication');
		$register->method('getSlug')->willReturn($slug);
		$register->method('getSchemas')->willReturn($world['held']);
		$register->method('getConfiguration')->willReturn($world['config']);
		$register->method('setSchemas')->willReturnCallback(
			function ($schemas) use ($slug, $register) {
				$this->log[] = ['setSchemas', $slug, $schemas];
				return $register;
			}
		);
		$register->method('setConfiguration')->willReturnCallback(
			function ($configuration) use ($slug): void {
				$this->log[] = ['setConfiguration', $slug, $configuration];
			}
		);

		return $register;
	}//end register()

	/**
	 * A schema double, or the raw value when the world names something that is not a schema.
	 *
	 * @param mixed $schema [id, slug], ['noslug', id] or any other value.
	 *
	 * @return mixed
	 */
	private function schema(mixed $schema): mixed {
		if (is_array($schema) === false) {
			return $schema;
		}

		if ($schema[0] === 'noslug') {
			return new class($schema[1]) {
				public function __construct(
					private int $id,
				) {
				}

				public function getId(): int {
					return $this->id;
				}
			};
		}

		$double = $this->getMockBuilder(Schema::class)
			->disableOriginalConstructor()
			->addMethods(['getId', 'getSlug'])
			->getMock();
		$double->method('getId')->willReturn($schema[0]);
		$double->method('getSlug')->willReturn($schema[1]);

		return $double;
	}//end schema()

	/**
	 * The service around the world's OpenRegister.
	 *
	 * @param array<string, mixed> $world The world.
	 * @param Register $register The publication register.
	 *
	 * @return RegisterSchemaLinkService
	 */
	private function service(array $world, Register $register): RegisterSchemaLinkService {
		$mapper = $this->getMockBuilder(RegisterMapper::class)->disableOriginalConstructor()->onlyMethods(['find', 'update'])->getMock();
		$mapper->method('find')->willReturnCallback(
			function ($id, $_rbac = true, $_multitenancy = true) use ($world, $register) {
				$this->log[] = ['find', $id, $_rbac, $_multitenancy];
				if ($world['register'] !== 'mapper') {
					throw new RuntimeException('not found');
				}

				return $register;
			}
		);
		$mapper->method('update')->willReturnCallback(
			function ($entity) use ($world) {
				$this->log[] = ['update', $entity->getSlug()];
				if (($world['updateFails'] ?? false) === true) {
					throw new RuntimeException('update failed');
				}

				return $entity;
			}
		);
		$magic = $this->getMockBuilder(MagicMapper::class)->disableOriginalConstructor()->onlyMethods(['ensureTableForRegisterSchema'])->getMock();
		$magic->method('ensureTableForRegisterSchema')->willReturnCallback(
			function ($register, $schema) {
				$this->log[] = ['ensureTable', $register->getSlug(), $schema->getId()];
				if ($schema->getSlug() === 'fails') {
					throw new RuntimeException('table failed');
				}

				return true;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				'OCA\OpenRegister\Db\RegisterMapper' => $mapper,
				'OCA\OpenRegister\Db\MagicMapper' => $magic,
			}
		);
		$appManager = $this->createMock(IAppManager::class);
		$installed = ['opencatalogi'];
		if (($world['installed'] ?? true) === true) {
			$installed[] = 'openregister';
		}

		$appManager->method('getInstalledApps')->willReturn($installed);

		return new RegisterSchemaLinkService($container, $appManager);
	}//end service()
}//end class
