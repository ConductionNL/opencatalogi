<?php

/**
 * Golden test for the OOAPI resource reads of OoapiService.
 *
 * Every case is recorded once against the service and replayed from the
 * fixture: the answer, and every query and lookup the service sent to
 * OpenRegister to build it.
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

use OCA\OpenCatalogi\Contract\OoapiPage;
use OCA\OpenCatalogi\Contract\OoapiScope;
use OCA\OpenCatalogi\Service\OoapiMappingService;
use OCA\OpenCatalogi\Service\OoapiService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for the OOAPI resource reads of OoapiService.
 *
 * @covers \OCA\OpenCatalogi\Service\OoapiService
 * @uses   \OCA\OpenCatalogi\Service\OoapiMappingService
 * @uses   \OCA\OpenCatalogi\Contract\OoapiScope
 * @uses   \OCA\OpenCatalogi\Contract\OoapiPage
 */
class OoapiCollectionGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/ooapi-collection-golden.json';

	/**
	 * What the fake OpenRegister was asked, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * The recorded cases: a service method and its arguments.
	 *
	 * @return array<string, array{0: string, 1: array<int, mixed>}>
	 */
	public static function cases(): array {
		$cat = ['id' => 'catalog-1', 'organization' => 'org-1'];

		return [
			'courses' => ['listCourses', [$cat, 'reg-1', '11', 1, 50]],
			'courses clamp low' => ['listCourses', [$cat, 'reg-1', '11', 0, 0]],
			'courses clamp high' => ['listCourses', [$cat, 'reg-1', '11', 3, 5000]],
			'courses unannotated schema' => ['listCourses', [$cat, 'reg-1', '15', 2, 10]],
			'courses unreadable schema' => ['listCourses', [$cat, 'reg-1', '16', 1, 10]],
			'courses catalog without id' => ['listCourses', [['title' => 'x'], 'reg-1', '11', 1, 10]],
			'courses with a next page' => ['listCourses', [['id' => 'catalog-v'], 'reg-1', '11', 1, 2]],
			'programs' => ['listPrograms', [$cat, 'reg-2', '12', 2, 20]],
			'programs unannotated' => ['listPrograms', [$cat, 'reg-2', '15', 1, 20]],
			'offerings of a course' => ['listOfferings', [$cat, 'reg-3', '13', 'course-9', 1, 5]],
			'offerings of no course' => ['listOfferings', [$cat, 'reg-3', '13', null, 1, 5]],
			'offerings of a blank course' => ['listOfferings', [$cat, 'reg-3', '13', '', 0, 500]],
			'resource found' => ['getResource', [$cat, 'reg-1', '11', 'course-1', 'courseId']],
			'resource of another catalog' => ['getResource', [$cat, 'reg-1', '11', 'course-foreign', 'courseId']],
			'resource of another schema' => ['getResource', [$cat, 'reg-1', '11', 'course-otherschema', 'courseId']],
			'resource on unannotated schema' => ['getResource', [$cat, 'reg-1', '15', 'course-plain', 'courseId']],
			'resource missing' => ['getResource', [$cat, 'reg-1', '11', 'missing', 'courseId']],
			'resource lookup fails' => ['getResource', [$cat, 'reg-1', '11', 'broken', 'courseId']],
			'resource without catalog on either side' => ['getResource', [['title' => 'x'], 'reg-1', '11', 'course-nocat', 'offeringId']],
			'organization' => ['renderOrganization', [$cat, 'reg-o', '14']],
			'organization none' => ['renderOrganization', [['id' => 'catalog-1'], 'reg-o', '14']],
			'organization blank' => ['renderOrganization', [['id' => 'catalog-1', 'organization' => ''], 'reg-o', '14']],
			'organization not a string' => ['renderOrganization', [['id' => 'catalog-1', 'organization' => 7], 'reg-o', '14']],
			'organization missing' => ['renderOrganization', [['organization' => 'missing'], 'reg-o', '14']],
			'organization unannotated' => ['renderOrganization', [$cat, 'reg-o', '15']],
			'organization by id' => ['organizationById', [$cat, 'org-1', 'reg-o', '14']],
			'organization by other id' => ['organizationById', [$cat, 'org-2', 'reg-o', '14']],
			'organization by id without one' => ['organizationById', [['id' => 'c'], '', 'reg-o', '14']],
			'validate over two pages' => ['validateCatalog', [['id' => 'catalog-v'], 'reg-1', '11']],
			'validate unannotated' => ['validateCatalog', [['id' => 'catalog-v'], 'reg-1', '15']],
		];

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param string            $method The service method.
	 * @param array<int, mixed> $args   Its arguments.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testReadMatchesTheRecording(string $method, array $args): void {
		$name = $this->dataName();
		$actual = ['answer' => $this->call(method: $method, args: $args), 'asked' => $this->calls];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], $actual);

	}//end testReadMatchesTheRecording()

	/**
	 * Call a read with the case's arguments.
	 *
	 * @param string            $method The service method.
	 * @param array<int, mixed> $args   Its arguments.
	 *
	 * @return mixed The answer.
	 */
	private function call(string $method, array $args): mixed {
		$service = $this->service();
		return match ($method) {
			'listCourses', 'listPrograms' => $service->$method(
				new OoapiScope($args[0], $args[1], $args[2]),
				new OoapiPage($args[3], $args[4])
			),
			'listOfferings' => $service->listOfferings(
				new OoapiScope($args[0], $args[1], $args[2]),
				new OoapiPage($args[4], $args[5]),
				$args[3]
			),
			'getResource' => $service->getResource(new OoapiScope($args[0], $args[1], $args[2]), $args[3], $args[4]),
			'organizationById' => $service->organizationById(new OoapiScope($args[0], $args[2], $args[3]), $args[1]),
			default => $service->$method(new OoapiScope($args[0], $args[1], $args[2])),
		};

	}//end call()

	/**
	 * Build the service over a fake OpenRegister that records what it is asked.
	 *
	 * @return OoapiService The service.
	 */
	private function service(): OoapiService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$objects = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query, bool $_rbac, bool $_multitenancy, bool $deleted): array {
				$this->calls[] = ['search' => $query, 'flags' => [$_rbac, $_multitenancy, $deleted]];
				$page = (int)$query['_page'];
				$results = [
					['@self' => ['uuid' => 'c-' . $page . '-a'], 'catalog' => $query['catalog'], 'code' => 'A' . $page, 'name' => 'Course A'],
					['@self' => ['uuid' => 'c-' . $page . '-b'], 'catalog' => $query['catalog'], 'code' => '', 'name' => 'Course B'],
				];
				$next = null;
				if ($query['catalog'] === 'catalog-v' && $page < 2) {
					$next = 'next';
				}

				return ['results' => $results, 'next' => $next];
			}
		);
		$objects->method('find')->willReturnCallback(
			function (string|int $id, array $_extend): ?\OCA\OpenRegister\Db\ObjectEntity {
				$this->calls[] = ['find' => $id];
				$known = [
					'course-1' => [['catalog' => 'catalog-1', 'code' => 'INF101', 'name' => 'Course'], '11'],
					'course-foreign' => [['catalog' => 'catalog-2', 'code' => 'X'], '11'],
					'course-otherschema' => [['catalog' => 'catalog-1', 'code' => 'Y'], '12'],
					'course-plain' => [['catalog' => 'catalog-1', 'code' => 'Z'], '15'],
					'course-nocat' => [['code' => 'N'], '11'],
					'org-1' => [['name' => 'Universiteit'], '14'],
				];
				if ($id === 'broken') {
					throw new \RuntimeException('lookup failed');
				}

				if (isset($known[$id]) === false) {
					return null;
				}

				$entity = new \OCA\OpenRegister\Db\ObjectEntity();
				$entity->setObject($known[$id][0]);
				$entity->setUuid((string)$id);
				$entity->setSchema($known[$id][1]);

				return $entity;
			}
		);

		$annotations = [
			11 => ['x-ooapi' => ['resource' => 'course']],
			12 => ['x-ooapi' => ['resource' => 'program']],
			13 => ['x-ooapi' => ['resource' => 'offering', 'mapping' => ['code' => 'offeringCode']]],
			14 => ['x-ooapi' => ['resource' => 'organization', 'mapping' => ['name' => 'name']]],
			15 => ['title' => 'plain'],
		];
		$schemas = $this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			function (int|string $id) use ($annotations): \OCA\OpenRegister\Db\Schema {
				$this->calls[] = ['schema' => $id];
				if (isset($annotations[$id]) === false) {
					throw new \RuntimeException('no such schema');
				}

				$schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
				$schema->method('jsonSerialize')->willReturn($annotations[$id]);

				return $schema;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				'OCA\OpenRegister\Service\ObjectService' => $objects,
				'OCA\OpenRegister\Db\SchemaMapper' => $schemas,
				default => throw new \RuntimeException('unexpected container lookup: ' . $id),
			}
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getBaseUrl')->willReturn('https://host');

		return new OoapiService(
			$container,
			$appManager,
			new OoapiMappingService(),
			$urls,
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class)
		);

	}//end service()
}//end class
