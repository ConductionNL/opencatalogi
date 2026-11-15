<?php

/**
 * Golden test for CatalogiService::getConfig.
 *
 * Every world hands the request config reader a set of request parameters and
 * records the config it built.
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
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for CatalogiService::getConfig.
 *
 * @covers \OCA\OpenCatalogi\Service\CatalogiService
 */
class CatalogConfigGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/catalog-config-golden.json';

	/**
	 * The recorded worlds: request parameters.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function worlds(): array {
		return [
			'none' => [[]],
			'plain paging' => [['limit' => '5', 'offset' => '15', 'page' => '2']],
			'underscored paging' => [['_limit' => '7', '_offset' => '3', '_page' => '9']],
			'plain wins over underscored' => [['limit' => '1', '_limit' => '2', 'offset' => '3', '_offset' => '4', 'page' => '5', '_page' => '6']],
			'page without offset' => [['page' => '3', 'limit' => '10']],
			'underscored page without offset' => [['_page' => '4']],
			'page zero' => [['page' => '0']],
			'offset zero with a page' => [['offset' => '0', 'page' => '7']],
			'null offset falls back' => [['offset' => null, '_offset' => '8']],
			'null page falls back' => [['page' => null, '_page' => '2', 'limit' => '3']],
			'non-numeric values' => [['limit' => 'abc', 'offset' => 'x', 'page' => 'y']],
			'queries text' => [['queries' => 'title']],
			'underscored queries list' => [['_queries' => ['a', 'b']]],
			'queries null falls back' => [['queries' => null, '_queries' => 'c']],
			'sort and the rest' => [['order' => ['title' => 'asc'], '_order' => ['x' => 'desc'], '_search' => 's', 'extend' => 'e', '_extend' => 'f', 'fields' => 'g', '_fields' => 'h', 'unset' => 'i', '_unset' => 'j']],
			'underscored sort and the rest' => [['_order' => ['x' => 'desc'], '_extend' => 'f', '_fields' => 'h', '_unset' => 'j']],
			'id and route stripped' => [['id' => 9, '_route' => 'r', 'kind' => 'k']],
		];

	}//end worlds()

	/**
	 * Build the config in the world and compare with the recording.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return void
	 */
	#[DataProvider('worlds')]
	public function testConfigMatchesTheRecording(array $params): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		$service = new CatalogiService(
			$this->createMock(IAppConfig::class),
			$request,
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$cacheFactory,
			$this->createMock(LoggerInterface::class),
		);

		$actual = (new \ReflectionMethod(CatalogiService::class, 'getConfig'))->invoke($service);

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$name = (string)$this->dataName();
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], $actual);

	}//end testConfigMatchesTheRecording()
}//end class
