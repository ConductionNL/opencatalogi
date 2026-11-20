<?php

/**
 * Tests for RefusalGrounds: dossiq's list, or the vendored snapshot without dossiq.
 *
 * @category Test
 * @package  Unit\Service\Woo
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service\Woo;

use OCA\OpenCatalogi\Service\Woo\RefusalGrounds;
use OCA\OpenCatalogi\Service\Woo\RefusalGroundsUnavailableException;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The double of dossiq's `WooRefusalGrounds` answers rows with REQ-WRG-007's
 * exact keys: id, code, article, paragraph, letter, label, description,
 * parent, status, legalSource (plus `kind` and `citable`, which its list adds).
 *
 * @covers \OCA\OpenCatalogi\Service\Woo\RefusalGrounds
 */
class RefusalGroundsTest extends TestCase {

	/**
	 * A ground in dossiq's shape.
	 *
	 * @param string $code    The code.
	 * @param string $status  active or retired.
	 * @param bool   $citable Whether it can be cited.
	 *
	 * @return array<string, mixed>
	 */
	private function ground(string $code, string $status = 'active', bool $citable = true): array {
		return [
			'id' => 'woo-ground-' . str_replace('.', '-', $code),
			'code' => $code,
			'article' => '5.1',
			'paragraph' => '2',
			'letter' => 'e',
			'label' => 'Ground ' . $code,
			'description' => 'Woo artikel ' . $code,
			'parent' => '5.1.2',
			'status' => $status,
			'legalSource' => 'https://wetten.overheid.nl/jci1.3:c:BWBR0045754',
			'kind' => 'relative',
			'citable' => $citable,
		];
	}//end ground()

	/**
	 * A service with dossiq enabled, whose list answers or throws.
	 *
	 * @param array<int, array<string, mixed>>|null $answer The list, or null to throw.
	 * @param int                                   $calls  How often the list is asked.
	 *
	 * @return RefusalGrounds
	 */
	private function withDossiq(?array $answer, int $calls = 1): RefusalGrounds {
		$dossiq = new class ($answer) {
			/**
			 * @param array<int, array<string, mixed>>|null $answer The list, or null to throw.
			 */
			public function __construct(private readonly ?array $answer) {
			}

			/**
			 * @return array<int, array<string, mixed>>
			 */
			public function list(): array {
				if ($this->answer === null) {
					throw new RuntimeException('The Woo refusal grounds cannot be read: OpenRegister is not available.');
				}

				return $this->answer;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->exactly($calls))->method('get')
			->with(RefusalGrounds::DOSSIQ_CLASS)
			->willReturn($dossiq);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->with('dossiq')->willReturn(true);

		return new RefusalGrounds(container: $container, appManager: $apps, snapshotPath: '/nonexistent/snapshot.json');
	}//end withDossiq()

	/**
	 * A service without dossiq.
	 *
	 * @param string $snapshotPath The snapshot to read.
	 *
	 * @return RefusalGrounds
	 */
	private function withoutDossiq(string $snapshotPath = RefusalGrounds::SNAPSHOT_PATH): RefusalGrounds {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->with('dossiq')->willReturn(false);

		return new RefusalGrounds(container: $container, appManager: $apps, snapshotPath: $snapshotPath);
	}//end withoutDossiq()

	/**
	 * With dossiq, its list is the list: 5.1.2.e is offered, the heading and a retired one are not.
	 *
	 * @return void
	 */
	public function testWithDossiqTheListIsDossiqs(): void {
		$service = $this->withDossiq(
			answer: [$this->ground('5.1.2', 'active', false), $this->ground('5.1.2.e'), $this->ground('5.1.2.x', 'retired')],
			calls: 2
		);

		$this->assertTrue($service->fromDossiq());
		$this->assertSame(['5.1.2.e'], $service->citableCodes());

		$listed = $service->list();
		$this->assertCount(3, $listed);
		$this->assertSame('dossiq', $listed[1]['source']);
		$this->assertSame($this->ground('5.1.2.e') + ['source' => 'dossiq'], $listed[1]);
	}//end testWithDossiqTheListIsDossiqs()

	/**
	 * dossiq installed but unreadable: the failure propagates and the snapshot is not read.
	 *
	 * @return void
	 */
	public function testAnUnreadableDossiqRefusesTheSaveAndDoesNotUseTheSnapshot(): void {
		// The snapshot path does not exist: reading it would throw a different message.
		$service = $this->withDossiq(answer: null);

		$this->expectException(RefusalGroundsUnavailableException::class);
		$this->expectExceptionMessage('OpenRegister is not available');

		$service->citableCodes();
	}//end testAnUnreadableDossiqRefusesTheSaveAndDoesNotUseTheSnapshot()

	/**
	 * Without dossiq, the vendored snapshot answers, every row marked `source: snapshot`.
	 *
	 * @return void
	 */
	public function testWithoutDossiqTheSnapshotIsUsedForRedactionOnly(): void {
		$service = $this->withoutDossiq();

		$this->assertFalse($service->fromDossiq());
		$codes = $service->citableCodes();
		$this->assertContains('5.1.2.e', $codes);
		$this->assertNotContains('5.1.2', $codes, 'an article heading is not citable');

		foreach ($service->list() as $ground) {
			$this->assertSame('snapshot', $ground['source']);
			$this->assertSame('active', $ground['status']);
		}
	}//end testWithoutDossiqTheSnapshotIsUsedForRedactionOnly()

	/**
	 * A Woo request path has no fallback: without dossiq it is refused (decision D12).
	 *
	 * @return void
	 */
	public function testTheSnapshotIsNeverOfferedToARequestPath(): void {
		$this->expectException(RefusalGroundsUnavailableException::class);
		$this->expectExceptionMessage('dossiq, which is not installed');

		$this->withoutDossiq()->forRequestPath();
	}//end testTheSnapshotIsNeverOfferedToARequestPath()

	/**
	 * With dossiq, a request path gets dossiq's citable grounds.
	 *
	 * @return void
	 */
	public function testARequestPathReadsDossiqWhenItIsInstalled(): void {
		$service = $this->withDossiq(answer: [$this->ground('5.1.2.e'), $this->ground('5.1.1', 'active', false)]);

		$this->assertSame(['5.1.2.e'], array_column($service->forRequestPath(), 'code'));
	}//end testARequestPathReadsDossiqWhenItIsInstalled()

	/**
	 * An unreadable snapshot is a failure, not an empty list.
	 *
	 * @return void
	 */
	public function testAnUnreadableSnapshotIsNotAnEmptyList(): void {
		$this->expectException(RefusalGroundsUnavailableException::class);

		$this->withoutDossiq(snapshotPath: '/nonexistent/snapshot.json')->list();
	}//end testAnUnreadableSnapshotIsNotAnEmptyList()

	/**
	 * The vendored snapshot carries a version and rows in dossiq's shape; when
	 * dossiq is checked out beside the clone its version is not newer than ours.
	 *
	 * @return void
	 */
	public function testTheVendoredSnapshotMatchesDossiqsShape(): void {
		$snapshot = json_decode((string)file_get_contents(RefusalGrounds::SNAPSHOT_PATH), true);

		$this->assertIsArray($snapshot);
		$this->assertNotSame('', (string)($snapshot['version'] ?? ''));
		$keys = ['id', 'code', 'article', 'paragraph', 'letter', 'label', 'description', 'parent', 'status', 'legalSource'];
		foreach ($snapshot['grounds'] as $ground) {
			foreach ($keys as $key) {
				$this->assertArrayHasKey($key, $ground, 'snapshot row ' . ($ground['code'] ?? '?') . ' lacks ' . $key);
			}
		}

		$this->assertSame((string)$snapshot['version'], $this->withoutDossiq()->snapshotVersion());

		$dossiq = dirname(__DIR__, 5) . '/dossiq/lib/Settings/woo-refusal-grounds.snapshot.json';
		if (is_readable($dossiq) === false) {
			$this->addToAssertionCount(1);
			return;
		}

		$theirs = json_decode((string)file_get_contents($dossiq), true);
		$this->assertTrue(
			version_compare((string)$snapshot['version'], (string)($theirs['version'] ?? '0'), '>='),
			'the vendored refusal grounds snapshot is older than the one dossiq ships: copy it again'
		);
	}//end testTheVendoredSnapshotMatchesDossiqsShape()
}//end class
