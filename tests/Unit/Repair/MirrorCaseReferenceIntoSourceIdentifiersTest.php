<?php

/**
 * Unit tests for the MirrorCaseReferenceIntoSourceIdentifiers repair step.
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

namespace Unit\Repair;

use OCA\OpenCatalogi\Repair\MirrorCaseReferenceIntoSourceIdentifiers;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Every stored publication gets its case reference as a source identifier, once.
 *
 * @covers \OCA\OpenCatalogi\Repair\MirrorCaseReferenceIntoSourceIdentifiers
 * @uses \OCA\OpenCatalogi\Service\Publication\PublicationLinkRules
 */
class MirrorCaseReferenceIntoSourceIdentifiersTest extends TestCase {

	/**
	 * An in-memory object store over two pages of publications.
	 *
	 * @param array<int, mixed> $publications The stored publications.
	 *
	 * @return object The store.
	 */
	private static function store(array $publications): object {
		return new class($publications) {
			/** @var array<int, array<string, mixed>> */
			public array $saves = [];

			/** @var array<int, array<string, mixed>> */
			public array $queries = [];

			public function __construct(public array $publications) {
			}

			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->queries[] = $query + ['rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				$pages = array_chunk($this->publications, 2);
				return ['results' => ($pages[$query['_page'] - 1] ?? []), 'pages' => count($pages)];
			}

			public function saveObject(array $object, ?string $register = null, ?string $schema = null, string $uuid = '', bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				foreach ($this->publications as $index => $stored) {
					if (is_array($stored) === true && ($stored['id'] ?? null) === $uuid) {
						$this->publications[$index] = $object;
					}
				}

				return $object;
			}
		};
	}//end store()

	/**
	 * The step over the store, with the publication register and schema configured or not.
	 *
	 * @param object|null $store The store; null when OpenRegister is missing.
	 * @param bool $configured Whether the register and schema are configured.
	 *
	 * @return MirrorCaseReferenceIntoSourceIdentifiers The step.
	 */
	private function step(?object $store, bool $configured = true): MirrorCaseReferenceIntoSourceIdentifiers {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $configured === true
				? (['publication_register' => '7', 'publication_schema' => '12'][$key] ?? $default)
				: $default
		);
		$container = $this->createMock(ContainerInterface::class);
		if ($store === null) {
			$container->method('get')->willThrowException(new \RuntimeException('not installed'));
		} else {
			$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($store);
		}

		return new MirrorCaseReferenceIntoSourceIdentifiers($config, $container);
	}//end step()

	/**
	 * Publications with a case reference get the entry; a second run changes nothing.
	 *
	 * @return void
	 */
	public function testEveryCaseReferenceIsMirroredOnce(): void {
		$entity = new class {
			public function jsonSerialize(): array {
				return ['id' => 'p3', 'caseReference' => 'Z-3'];
			}
		};
		$store = self::store([
			['id' => 'p1', 'caseReference' => 'Z-1'],
			['id' => 'p2'],
			$entity,
			['id' => 'p4', 'caseReference' => 'Z-4', 'sourceIdentifiers' => [['system' => 'case', 'identifier' => 'Z-4', 'public' => false]]],
			'not a publication',
		]);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->exactly(2))->method('info')->willReturnCallback(static function (string $message) use (&$messages): void {
			$messages[] = $message;
		});
		$messages = [];

		$this->step($store)->run($output);

		$this->assertSame(['p1', 'p3'], array_column($store->saves, 'uuid'));
		$this->assertSame([['system' => 'case', 'identifier' => 'Z-1', 'public' => false]], $store->saves[0]['object']['sourceIdentifiers']);
		$this->assertSame('7', $store->saves[0]['register']);
		$this->assertSame('12', $store->saves[0]['schema']);
		$this->assertSame([1, 2, 3], array_column($store->queries, '_page'));
		$this->assertSame(['register' => '7', 'schema' => '12'], $store->queries[0]['@self']);
		$this->assertFalse($store->queries[0]['rbac']);

		$store->saves = [];
		$store->publications[2] = ['id' => 'p3', 'caseReference' => 'Z-3', 'sourceIdentifiers' => [['system' => 'case', 'identifier' => 'Z-3']]];
		$this->step($store)->run($output);
		$this->assertSame([], $store->saves);
		$this->assertSame(['Case reference added to the source identifiers of 2 publication(s)', 'Case reference added to the source identifiers of 0 publication(s)'], $messages);
	}//end testEveryCaseReferenceIsMirroredOnce()

	/**
	 * Without configuration or OpenRegister nothing is written.
	 *
	 * @return void
	 */
	public function testNothingIsWrittenWithoutConfigurationOrOpenRegister(): void {
		$store = self::store([['id' => 'p1', 'caseReference' => 'Z-1']]);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('No publication register and schema configured; no source identifiers written');
		$this->step($store, configured: false)->run($output);
		$this->assertSame([], $store->saves);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('OpenRegister is not available; no source identifiers written');
		$this->step(null)->run($output);
	}//end testNothingIsWrittenWithoutConfigurationOrOpenRegister()

	/**
	 * The step is registered post-migration, right after InitializeSettings.
	 *
	 * @return void
	 */
	public function testTheStepIsRegisteredPostMigration(): void {
		$info = simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml');
		$steps = array_map('strval', $info->xpath('/info/repair-steps/post-migration/step'));
		$index = array_search(MirrorCaseReferenceIntoSourceIdentifiers::class, $steps, true);

		$this->assertNotFalse($index);
		$this->assertSame('OCA\OpenCatalogi\Repair\InitializeSettings', $steps[$index - 1]);
		$this->assertSame('Add the case reference of every publication to its source identifiers', $this->step(null)->getName());
	}//end testTheStepIsRegisteredPostMigration()
}//end class
