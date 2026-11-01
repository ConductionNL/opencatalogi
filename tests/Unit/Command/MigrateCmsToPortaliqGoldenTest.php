<?php

/**
 * Golden test for the opencatalogi:cms:migrate-to-portaliq command.
 *
 * Every case runs the command over recording doubles and compares the exit
 * code, every output line and every call, in order, to the recording in
 * fixtures/migrate-cms-to-portaliq-golden.json.
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

namespace Unit\Command;

use OCA\OpenCatalogi\Command\MigrateCmsToPortaliqCommand;
use OCA\OpenCatalogi\Contract\CmsMigrationServiceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Golden test for the CMS migration command.
 *
 * @covers \OCA\OpenCatalogi\Command\MigrateCmsToPortaliqCommand
 */
class MigrateCmsToPortaliqGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/migrate-cms-to-portaliq-golden.json';

	/**
	 * Everything asked, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * The recorded cases: options, source rows per schema, and what fails.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$rows = [
			'page' => [['title' => 'Home', 'slug' => 'home'], ['title' => 'Blocks', 'unmapped' => ['carousel']], ['slug' => 'nameless', 'dropped' => ['faq']]],
			'menu' => [['title' => 'Main', 'dropped' => ['icon']], ['title' => 'Footer']],
		];

		return [
			'dry run' => [['options' => ['--portal' => ' zuid '], 'rows' => $rows]],
			'apply' => [['options' => ['--portal' => 'zuid', '--apply' => true], 'rows' => $rows]],
			'apply, a save fails' => [['options' => ['--portal' => 'zuid', '--apply' => true], 'rows' => $rows, 'failSave' => 'Footer']],
			'apply, nothing to move' => [['options' => ['--portal' => 'zuid', '--apply' => true], 'rows' => ['page' => [], 'menu' => []]]],
			'no portal' => [['options' => ['--portal' => '  '], 'rows' => $rows]],
			'portal missing' => [['options' => [], 'rows' => $rows]],
			'openregister missing' => [['options' => ['--portal' => 'zuid'], 'openregister' => false]],
			'pages unreadable' => [['options' => ['--portal' => 'zuid', '--apply' => true], 'rows' => $rows, 'failRead' => 'page']],
			'menus unreadable' => [['options' => ['--portal' => 'zuid', '--apply' => true], 'rows' => $rows, 'failRead' => 'menu']],
			'rows not a list' => [['options' => ['--portal' => 'zuid', '--apply' => true], 'rows' => ['page' => 'x', 'menu' => [['title' => 'Main']]]]],
		];

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testRunMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$tester = new CommandTester(new MigrateCmsToPortaliqCommand(migration: $this->migration(case: $case)));
		$exit = $tester->execute($case['options']);
		$actual = ['exit' => $exit, 'display' => explode("\n", $tester->getDisplay()), 'calls' => $this->calls];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($actual), true));

	}//end testRunMatchesTheRecording()

	/**
	 * A migration-service double over the case's rows, with a recording object store.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return CmsMigrationServiceInterface The double.
	 */
	private function migration(array $case): CmsMigrationServiceInterface {
		$calls = &$this->calls;
		$store = new class($calls, $case) {
			public function __construct(
				private array &$calls,
				private array $case,
			) {
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters = [], bool $rbac = true, bool $multitenancy = true): mixed {
				$this->calls[] = ['read' => [$register, $schema, $filters, $rbac, $multitenancy]];
				if (($this->case['failRead'] ?? null) === $schema) {
					throw new RuntimeException($schema . ' unreadable');
				}

				return ($this->case['rows'][$schema] ?? []);
			}

			public function saveObject(array $object, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls[] = ['save' => [$object, $register, $schema, $_rbac, $_multitenancy]];
				if (($this->case['failSave'] ?? null) === ($object['title'] ?? null)) {
					throw new RuntimeException('save broke');
				}

				return $object;
			}
		};

		$migration = $this->createMock(CmsMigrationServiceInterface::class);
		$migration->method('openRegister')->willReturnCallback(
			function (string $id) use ($case, $store): ?object {
				$this->calls[] = ['openRegister' => $id];
				return (($case['openregister'] ?? true) === true) ? $store : null;
			}
		);
		$migration->method('toFields')->willReturnCallback(static fn (mixed $row): array => (array)$row);
		$migration->method('pageFor')->willReturnCallback(
			static fn (array $page, string $portal): array => [
				'page' => ['title' => ($page['title'] ?? null), 'route' => '/' . ($page['slug'] ?? 'x'), 'portal' => $portal],
				'unmapped' => ($page['unmapped'] ?? []),
				'dropped' => ($page['dropped'] ?? []),
			]
		);
		$migration->method('menuFor')->willReturnCallback(
			static fn (array $menu, string $portal): array => [
				'menu' => ['title' => ($menu['title'] ?? null), 'portal' => $portal],
				'dropped' => ($menu['dropped'] ?? []),
			]
		);

		return $migration;
	}//end migration()
}//end class
