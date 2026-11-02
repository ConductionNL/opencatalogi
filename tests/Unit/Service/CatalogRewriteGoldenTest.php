<?php

/**
 * Golden test for CatalogiService::computeRewrittenRegistersAndSchemas.
 *
 * Every world hands the rewrite a catalogue payload and a register/schema
 * table, and records the changed keys (or the exception) and every mapper
 * lookup it made.
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

use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Common\Exception\NotFoundException;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for CatalogiService::computeRewrittenRegistersAndSchemas.
 *
 * @covers \OCA\OpenCatalogi\Service\CatalogiService
 * @uses   \OCA\OpenCatalogi\Service\CatalogScopeSlugResolver
 */
class CatalogRewriteGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/catalog-rewrite-golden.json';

	/**
	 * Registers by slug: id and the schema ids it lists.
	 */
	private const REGISTERS = [
		'publicaties' => [20, [33, 34]],
		'stackiq' => [21, [9]],
		'22.0' => [22, []],
	];

	/**
	 * Schemas by id: slug.
	 */
	private const SCHEMAS = [
		9 => 'organization',
		33 => 'organization',
		34 => 'Document',
		40 => 'loose',
	];

	/**
	 * The mapper lookups the world made.
	 *
	 * @var list<string>
	 */
	private array $lookups = [];

	/**
	 * The recorded worlds.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function worlds(): array {
		return [
			'empty payload' => [['object' => []]],
			'only numeric ids' => [['object' => ['registers' => [20, '21'], 'schemas' => ['33', 9]]]],
			'register slugs' => [['object' => ['registers' => ['publicaties', 21]]]],
			'register slugs with keys' => [['object' => ['registers' => ['a' => 'stackiq', 'b' => '20']]]],
			'register not found' => [['object' => ['registers' => ['ghost'], 'schemas' => ['organization']]]],
			'register lookup fails otherwise' => [['object' => ['registers' => ['broken']]]],
			'registers not a list' => [['object' => ['registers' => 'publicaties']]],
			'registers null' => [['object' => ['registers' => null, 'schemas' => ['40']]]],
			'schema slug inside the register' => [['object' => ['registers' => ['publicaties'], 'schemas' => ['organization', 'document', 34]]]],
			'schema slug inside a numeric register' => [['object' => ['registers' => [21], 'schemas' => ['ORGANIZATION']]]],
			'schema slug outside the registers' => [['object' => ['registers' => [21], 'schemas' => ['document']]]],
			'schema slug without registers' => [['object' => ['schemas' => ['loose', '40']]]],
			'schema slug without registers, not found' => [['object' => ['schemas' => ['nothing']]]],
			'schema slug with slug-only registers' => [['object' => ['registers' => ['nope-numeric'], 'schemas' => ['loose']], 'registerFind' => 'string-id']],
			'schemas not a list' => [['object' => ['registers' => [20], 'schemas' => 'organization']]],
			'schemas with keys' => [['object' => ['registers' => [20], 'schemas' => ['x' => 'document', 'y' => 33]]]],
			'registers string, schemas list' => [['object' => ['registers' => 'publicaties', 'schemas' => [33]]]],
			'unchanged keys dropped' => [['object' => ['registers' => [20], 'schemas' => ['document']]]],
			'numeric-looking register slug' => [['object' => ['registers' => ['22.0']]]],
			'registers string, schemas string' => [['object' => ['registers' => 'publicaties', 'schemas' => 'organization']]],
			'same slug twice' => [['object' => ['registers' => [20], 'schemas' => ['organization', 'organization']]]],
		];

	}//end worlds()

	/**
	 * Run the rewrite in the world and compare with the recording.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return void
	 */
	#[DataProvider('worlds')]
	public function testRewriteMatchesTheRecording(array $world): void {
		$this->lookups = [];
		try {
			$outcome = ['result' => $this->service(world: $world)->computeRewrittenRegistersAndSchemas(object: $world['object'])];
		} catch (\Throwable $e) {
			$outcome = ['exception' => get_class($e), 'message' => preg_replace('/, called in .*$/', '', $e->getMessage())];
		}

		$actual = ['outcome' => $outcome, 'lookups' => $this->lookups];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$name = (string)$this->dataName();
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = json_decode((string)json_encode($actual), true);
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($actual), true));

	}//end testRewriteMatchesTheRecording()

	/**
	 * Build the service around the register and schema tables.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return CatalogiService
	 */
	private function service(array $world): CatalogiService {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			function (string|int $id, bool $_rbac = true, bool $_multitenancy = true) use ($world): Register {
				$this->lookups[] = 'register ' . $id . ' rbac=' . var_export($_rbac, true) . ' mt=' . var_export($_multitenancy, true);
				if ((string)$id === 'broken') {
					throw new DoesNotExistException('broken table');
				}

				foreach (self::REGISTERS as $slug => [$registerId, $schemas]) {
					if ((string)$id === $slug || (string)$id === (string)$registerId) {
						$register = new Register();
						$register->setId($registerId);
						$register->setSlug($slug);
						$register->setSchemas($schemas);
						return $register;
					}
				}

				if (($world['registerFind'] ?? '') === 'string-id') {
					$register = new Register();
					$register->setSlug((string)$id);
					return $register;
				}

				throw new NotFoundException('no register ' . $id);
			}
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function (string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true): Schema {
				$this->lookups[] = 'schema ' . $id . ' rbac=' . var_export($_rbac, true) . ' mt=' . var_export($_multitenancy, true);
				foreach (self::SCHEMAS as $schemaId => $slug) {
					if ((string)$id === (string)$schemaId || (is_string($id) === true && $id === $slug && $schemaId === 40)) {
						$schema = new Schema();
						$schema->setId($schemaId);
						$schema->setSlug($slug);
						return $schema;
					}
				}

				throw new DoesNotExistException('no schema ' . $id);
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => match ($id) {
				'OCA\OpenRegister\Db\RegisterMapper' => $registerMapper,
				'OCA\OpenRegister\Db\SchemaMapper' => $schemaMapper,
				default => null,
			}
		);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		return new CatalogiService(
			$this->createMock(IAppConfig::class),
			$this->createMock(IRequest::class),
			$container,
			$appManager,
			$cacheFactory,
			$this->createMock(LoggerInterface::class),
		);

	}//end service()
}//end class
