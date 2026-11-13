<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Command;

use DateTime;
use InvalidArgumentException;
use OCA\OpenCatalogi\Command\DirectorySyncCommand;
use OCA\OpenCatalogi\Contract\DirectoryServiceInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ opencatalogi:directory:sync` calls the cron sync, or one directory with --url (REQ-IMC-001).
 *
 * @covers \OCA\OpenCatalogi\Command\DirectorySyncCommand
 * @covers \OCA\OpenCatalogi\Command\MaintenanceCommand
 *
 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
 */
class DirectorySyncCommandTest extends TestCase {

	/**
	 * Without --url every directory is synced through the cron sync, and a failed one exits 1.
	 *
	 * @return void
	 */
	public function testSyncsEveryDirectoryAndExitsOneOnAFailure(): void {
		$service = $this->createMock(DirectoryServiceInterface::class);
		$service->expects($this->once())->method('doCronSync')->willReturn([
			'total_directories' => 2,
			'synced_directories' => 1,
			'failed_directories' => 1,
			'errors' => [['directory' => 'https://b.example/api/directory', 'error' => 'timeout']],
		]);
		$service->expects($this->never())->method('syncDirectory');

		$tester = new CommandTester(new DirectorySyncCommand($service));
		$exit = $tester->execute([]);

		$this->assertSame(1, $exit);
		$this->assertSame(
			"FAILED https://b.example/api/directory: timeout\nSynced 1 of 2 directories, 1 failed.\n",
			$tester->getDisplay()
		);
	}//end testSyncsEveryDirectoryAndExitsOneOnAFailure()

	/**
	 * With --url only that directory is synced; each listing added or updated is printed and it exits 0.
	 *
	 * @return void
	 */
	public function testSyncsOneDirectoryAndPrintsTheListingsItAddedOrUpdated(): void {
		$url = 'https://catalogus.voorbeeldgemeente.nl/index.php/apps/opencatalogi/api/directory';
		$service = $this->createMock(DirectoryServiceInterface::class);
		$service->expects($this->never())->method('doCronSync');
		$service->expects($this->once())->method('syncDirectory')->with($url)->willReturn($this->oneResult());

		$tester = new CommandTester(new DirectorySyncCommand($service));
		$exit = $tester->execute(['--url' => $url]);

		$this->assertSame(0, $exit);
		$this->assertSame(
			"CREATED Woo catalogus\nUPDATED Open data\n" . $url . ": 1 created, 1 updated, 1 unchanged, 0 skipped, 0 failed.\n",
			$tester->getDisplay()
		);
	}//end testSyncsOneDirectoryAndPrintsTheListingsItAddedOrUpdated()

	/**
	 * --output=json prints the service's result, with the sync time as text.
	 *
	 * @return void
	 */
	public function testJsonOutputIsTheResult(): void {
		$service = $this->createMock(DirectoryServiceInterface::class);
		$service->method('syncDirectory')->willReturn($this->oneResult());

		$tester = new CommandTester(new DirectorySyncCommand($service));
		$tester->execute(['--url' => 'https://a.example/api/directory', '--output' => 'json']);

		$data = json_decode($tester->getDisplay(), true);
		$this->assertSame('2026-11-13T10:00:00+00:00', $data['sync_time']);
		$this->assertSame(1, $data['listings_created']);
		$this->assertCount(3, $data['listing_details']);
	}//end testJsonOutputIsTheResult()

	/**
	 * A failing listing exits 1, a sync that throws exits 1 with the reason, a refused URL exits 2.
	 *
	 * @return void
	 */
	public function testFailuresAndRefusals(): void {
		$service = $this->createMock(DirectoryServiceInterface::class);
		$service->method('syncDirectory')->willReturnCallback(
			static function (string $url): array {
				return match ($url) {
					'https://failed.example' => ['listings_failed' => 1],
					'https://down.example' => throw new RuntimeException('unreachable'),
					default => throw new InvalidArgumentException('not a directory URL'),
				};
			}
		);
		$command = new DirectorySyncCommand($service);

		$tester = new CommandTester($command);
		$this->assertSame(1, $tester->execute(['--url' => 'https://failed.example']));
		$this->assertSame(1, $tester->execute(['--url' => 'https://down.example']));
		$this->assertSame("FAILED https://down.example: unreachable\n", $tester->getDisplay());
		$this->assertSame(2, $tester->execute(['--url' => 'ftp://nope']));
		$this->assertSame("Error: not a directory URL\n", $tester->getDisplay());
	}//end testFailuresAndRefusals()

	/**
	 * An unknown output format is wrong usage: exit 2, and nothing is synced.
	 *
	 * @return void
	 */
	public function testAnUnknownOutputFormatIsWrongUsage(): void {
		$service = $this->createMock(DirectoryServiceInterface::class);
		$service->expects($this->never())->method('doCronSync');

		$tester = new CommandTester(new DirectorySyncCommand($service));

		$this->assertSame(2, $tester->execute(['--output' => 'xml']));
		$this->assertSame("Error: --output must be plain or json, not xml.\n", $tester->getDisplay());
	}//end testAnUnknownOutputFormatIsWrongUsage()

	/**
	 * A one-directory result with a created, an updated and an unchanged listing.
	 *
	 * @return array<string, mixed>
	 */
	private function oneResult(): array {
		return [
			'directory_url' => 'https://a.example/api/directory',
			'sync_time' => new DateTime('2026-11-13T10:00:00+00:00'),
			'listings_created' => 1,
			'listings_updated' => 1,
			'listings_unchanged' => 1,
			'listings_skipped' => 0,
			'listings_failed' => 0,
			'listing_details' => [
				['listing_id' => 'l1', 'listing_title' => 'Woo catalogus', 'action' => 'created', 'success' => true],
				['listing_id' => 'l2', 'listing_title' => 'Open data', 'action' => 'updated', 'success' => true],
				['listing_id' => 'l3', 'listing_title' => 'Same', 'action' => 'unchanged', 'success' => true],
			],
		];
	}//end oneResult()

}//end class
