<?php

/**
 * ValueListChoiceStore test: the stored choices of one list, keyed by entry URI.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Woo
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-value-list-curation/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service\Woo;

use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Service\Woo\ValueListChoiceStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * OpenRegister's object service is a recording double.
 *
 * @covers \OCA\OpenCatalogi\Service\Woo\ValueListChoiceStore
 */
class ValueListChoiceStoreTest extends TestCase {

	/**
	 * The queries sent to OpenRegister.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The store over a configured register and an object service answering the rows.
	 *
	 * @param list<mixed> $rows      The stored rows.
	 * @param bool        $installed Whether OpenRegister is installed.
	 * @param string      $schema    The configured schema id.
	 *
	 * @return ValueListChoiceStore
	 */
	private function store(array $rows, bool $installed = true, string $schema = '41'): ValueListChoiceStore {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'publication_register' => '7',
				ValueListChoiceStore::SCHEMA_KEY => $schema,
				default => $default,
			}
		);

		$test = $this;
		$objects = new class($rows, $test) {
			/**
			 * @param list<mixed>            $rows The rows.
			 * @param ValueListChoiceStoreTest $test The test, which records the queries.
			 */
			public function __construct(private array $rows, private ValueListChoiceStoreTest $test) {
			}

			/**
			 * @param array<string, mixed> $query The query.
			 * @param bool $_rbac Whether RBAC applies.
			 * @param bool $_multitenancy Whether multitenancy applies.
			 * @return array<string, mixed>
			 */
			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->test->record(query: $query);
				return ['results' => $this->rows];
			}
		};

		$openRegister = $this->createMock(originalClassName: OpenRegisterLookupInterface::class);
		$openRegister->method('isInstalled')->willReturn($installed);
		$openRegister->method('objectService')->willReturn($objects);

		return new ValueListChoiceStore(config: $config, openRegister: $openRegister);
	}//end store()

	/**
	 * Record one query.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return void
	 */
	public function record(array $query): void {
		$this->queries[] = $query;
	}//end record()

	/**
	 * The choices of a list are keyed by entry, in a fixed shape; rows of another list are left out.
	 *
	 * @return void
	 */
	public function testTheChoicesOfAListAreKeyedByEntry(): void {
		$choices = $this->store(rows: [
			['scheme' => 'informatiecategorieen', 'entry' => 'https://example.org/c1', 'order' => '2', 'localLabel' => ' Eigen naam ', 'explanation' => 'Uitleg', 'hidden' => true],
			['scheme' => 'documentsoorten', 'entry' => 'https://example.org/d1', 'order' => 1],
			['scheme' => 'informatiecategorieen', 'entry' => ''],
		])->forScheme(scheme: 'informatiecategorieen');

		$this->assertSame(
			expected: ['https://example.org/c1' => ['order' => 2, 'localLabel' => 'Eigen naam', 'explanation' => 'Uitleg', 'hidden' => true, 'active' => false]],
			actual: $choices
		);
		$this->assertSame(expected: ['register' => '7', 'schema' => '41'], actual: $this->queries[0]['@self']);
		$this->assertSame(expected: 'informatiecategorieen', actual: $this->queries[0]['scheme']);
	}//end testTheChoicesOfAListAreKeyedByEntry()

	/**
	 * Without OpenRegister the read fails loudly, so the view can say the choices are unavailable.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheReadFails(): void {
		$this->expectException(RuntimeException::class);
		$this->store(rows: [], installed: false)->forScheme(scheme: 'informatiecategorieen');
	}//end testWithoutOpenRegisterTheReadFails()

	/**
	 * Without the schema configured the read fails loudly.
	 *
	 * @return void
	 */
	public function testWithoutTheSchemaConfiguredTheReadFails(): void {
		$this->expectException(RuntimeException::class);
		$this->store(rows: [], schema: '')->forScheme(scheme: 'informatiecategorieen');
	}//end testWithoutTheSchemaConfiguredTheReadFails()
}//end class
