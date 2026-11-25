<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\RegisterSchemaLinkService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Service\ConfigurationService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An upgrade lands the schemas a newer version added, without importing over anything (REQ-PLC-007).
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
 */
class SettingsServiceMissingSchemasTest extends TestCase {

	/**
	 * The app folder holding the test payload.
	 *
	 * @var string
	 */
	private string $appDir = '';

	/**
	 * Write a payload with a publication register and three schemas.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appDir = sys_get_temp_dir() . '/opencatalogi_missing_' . uniqid();
		mkdir($this->appDir . '/lib/Settings', 0777, true);
		file_put_contents(
			$this->appDir . '/lib/Settings/publication_register.json',
			(string)json_encode(
				[
					'x-openregister' => ['sourceUrl' => 'test/path', 'sourceType' => 'local'],
					'components' => [
						'registers' => ['publication' => ['slug' => 'publication', 'schemas' => ['publication', 'publicationVersion', 'depublication']]],
						'schemas' => [
							'publication' => ['slug' => 'publication'],
							'publicationVersion' => ['slug' => 'publicationVersion'],
							'depublication' => ['slug' => 'depublication'],
						],
					],
				]
			)
		);
	}//end setUp()

	/**
	 * Remove the test payload.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unlink($this->appDir . '/lib/Settings/publication_register.json');
		rmdir($this->appDir . '/lib/Settings');
		rmdir($this->appDir . '/lib');
		rmdir($this->appDir);
	}//end tearDown()

	/**
	 * The service, with the given missing schemas and the importer that records its calls.
	 *
	 * @param list<string>                  $missing The schemas the link service calls missing.
	 * @param list<array{data: array, force: bool}> $imports The import calls, in order.
	 *
	 * @return SettingsService The service.
	 */
	private function service(array $missing, array &$imports): SettingsService {
		$importer = $this->createMock(originalClassName: ConfigurationService::class);
		$importer->method('importFromApp')->willReturnCallback(
			function (string $appId, array $data, string $version, bool $force=false) use (&$imports): array {
				$imports[] = ['data' => $data, 'force' => $force];
				$schemas = array_map(static fn (string $slug): string => 'imported ' . $slug, array_keys($data['components']['schemas'] ?? []));
				return ['registers' => [], 'schemas' => ($force === true ? $schemas : []), 'objects' => []];
			}
		);
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('getAppPath')->willReturn($this->appDir);
		$apps->method('getAppVersion')->willReturn('2.1.73');
		$apps->method('getInstalledApps')->willReturn(['openregister', 'opencatalogi']);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($importer);
		$link = $this->createMock(originalClassName: RegisterSchemaLinkService::class);
		$link->method('missingSchemas')->willReturn($missing);

		return new SettingsService(
			$this->createMock(originalClassName: IAppConfig::class),
			$container,
			$apps,
			$this->createMock(originalClassName: LoggerInterface::class),
			$link
		);
	}//end service()

	/**
	 * After the regular import, only the missing schemas are imported, forced, without register or objects.
	 *
	 * @return void
	 */
	public function testOnlyTheMissingSchemasAreImportedAndAddedToTheResult(): void {
		$imports = [];

		$result = $this->service(missing: ['publicationVersion', 'depublication'], imports: $imports)->loadSettings();

		$this->assertCount(expectedCount: 2, haystack: $imports);
		$this->assertFalse(condition: $imports[0]['force']);
		$this->assertTrue(condition: $imports[1]['force']);
		$this->assertSame(expected: ['schemas'], actual: array_keys($imports[1]['data']['components']));
		$this->assertSame(
			expected: ['publicationVersion', 'depublication'],
			actual: array_keys($imports[1]['data']['components']['schemas'])
		);
		$this->assertSame(expected: ['imported publicationVersion', 'imported depublication'], actual: $result['schemas']);
	}//end testOnlyTheMissingSchemasAreImportedAndAddedToTheResult()

	/**
	 * With nothing missing (a fresh install, or an instance already complete) there is one import.
	 *
	 * @return void
	 */
	public function testNothingMissingMeansOneImport(): void {
		$imports = [];

		$this->service(missing: [], imports: $imports)->loadSettings();

		$this->assertCount(expectedCount: 1, haystack: $imports);
	}//end testNothingMissingMeansOneImport()

	/**
	 * A forced load imports everything at once and never asks what is missing.
	 *
	 * @return void
	 */
	public function testAForcedLoadImportsOnce(): void {
		$imports = [];

		$this->service(missing: ['publicationVersion'], imports: $imports)->loadSettings(force: true);

		$this->assertCount(expectedCount: 1, haystack: $imports);
		$this->assertTrue(condition: $imports[0]['force']);
	}//end testAForcedLoadImportsOnce()
}//end class
