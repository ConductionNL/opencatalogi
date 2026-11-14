<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Command;

use OCA\OpenCatalogi\Command\CatalogCacheCommand;
use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ opencatalogi:catalog:cache` rebuilds or clears one catalogue's cache or all of them (REQ-IMC-001).
 *
 * @covers \OCA\OpenCatalogi\Command\CatalogCacheCommand
 * @covers \OCA\OpenCatalogi\Command\MaintenanceCommand
 *
 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
 */
class CatalogCacheCommandTest extends TestCase {

	/**
	 * `rebuild --all --output=json` warms every catalogue and prints one entry per catalogue.
	 *
	 * @return void
	 */
	public function testRebuildAllAnswersOneJsonEntryPerCatalogue(): void {
		$service = $this->createMock(CatalogiServiceInterface::class);
		$service->method('getCatalogSlugs')->willReturn(['woo', 'open-data']);
		$warmed = [];
		$service->method('warmupCatalogCache')->willReturnCallback(
			static function (string $slug) use (&$warmed): void {
				$warmed[] = $slug;
			}
		);
		$service->expects($this->never())->method('invalidateCatalogCache');

		$tester = new CommandTester(new CatalogCacheCommand($service));

		$this->assertSame(0, $tester->execute(['action' => 'rebuild', '--all' => true, '--output' => 'json']));
		$this->assertSame(['woo', 'open-data'], $warmed);
		$this->assertSame(
			[
				['slug' => 'woo', 'action' => 'rebuild', 'success' => true, 'error' => null],
				['slug' => 'open-data', 'action' => 'rebuild', 'success' => true, 'error' => null],
			],
			json_decode($tester->getDisplay(), true)
		);
	}//end testRebuildAllAnswersOneJsonEntryPerCatalogue()

	/**
	 * `clear <slug>` clears only that catalogue.
	 *
	 * @return void
	 */
	public function testClearOneCatalogue(): void {
		$service = $this->createMock(CatalogiServiceInterface::class);
		$service->expects($this->once())->method('invalidateCatalogCache')->with('woo');
		$service->expects($this->never())->method('getCatalogSlugs');

		$tester = new CommandTester(new CatalogCacheCommand($service));

		$this->assertSame(0, $tester->execute(['action' => 'clear', 'slug' => 'woo']));
		$this->assertSame("OK woo\nCleared 1 catalogues, 0 failed.\n", $tester->getDisplay());
	}//end testClearOneCatalogue()

	/**
	 * One failing catalogue is named, the rest still run, and the command exits 1.
	 *
	 * @return void
	 */
	public function testAFailingCatalogueExitsOne(): void {
		$service = $this->createMock(CatalogiServiceInterface::class);
		$service->method('getCatalogSlugs')->willReturn(['a', 'b']);
		$service->method('warmupCatalogCache')->willReturnCallback(
			static function (string $slug): void {
				if ($slug === 'a') {
					throw new RuntimeException('register gone');
				}
			}
		);

		$tester = new CommandTester(new CatalogCacheCommand($service));

		$this->assertSame(1, $tester->execute(['action' => 'rebuild', '--all' => true]));
		$this->assertSame("FAILED a: register gone\nOK b\nRebuilt 2 catalogues, 1 failed.\n", $tester->getDisplay());
	}//end testAFailingCatalogueExitsOne()

	/**
	 * An unknown action, both a slug and --all, or neither, is wrong usage and touches no cache.
	 *
	 * @return void
	 */
	public function testWrongUsageExitsTwo(): void {
		$service = $this->createMock(CatalogiServiceInterface::class);
		$service->expects($this->never())->method('warmupCatalogCache');
		$service->expects($this->never())->method('invalidateCatalogCache');
		$tester = new CommandTester(new CatalogCacheCommand($service));

		$this->assertSame(2, $tester->execute(['action' => 'purge', 'slug' => 'woo']));
		$this->assertSame("Error: The action must be rebuild or clear, not purge.\n", $tester->getDisplay());
		$this->assertSame(2, $tester->execute(['action' => 'clear', 'slug' => 'woo', '--all' => true]));
		$this->assertSame(2, $tester->execute(['action' => 'clear']));
		$this->assertSame("Error: Give one catalogue slug or --all, not both and not neither.\n", $tester->getDisplay());
	}//end testWrongUsageExitsTwo()

}//end class
