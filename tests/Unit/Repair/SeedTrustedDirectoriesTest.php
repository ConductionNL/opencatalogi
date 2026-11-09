<?php

/**
 * Tests for the one-time seed of the trusted directories (pp-f2, pp-f5).
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Repair;

use InvalidArgumentException;
use OCA\OpenCatalogi\Contract\DirectoryServiceInterface;
use OCA\OpenCatalogi\Repair\SeedTrustedDirectories;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

class SeedTrustedDirectoriesTest extends TestCase {

	/** @var array<string, string> */
	private array $stored = [];

	private IAppConfig $config;

	/** Whether the code currently runs inside runAsSystem(). */
	private bool $inSystemContext = false;

	protected function setUp(): void {
		$this->config = $this->createMock(IAppConfig::class);
		$this->config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->stored[$key] ?? $default)
		);
		$this->config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$key] = $value;
				return true;
			}
		);
	}

	/**
	 * The step over a directory service, with OpenRegister installed and offering runAsSystem().
	 *
	 * @param DirectoryServiceInterface $service The directory service.
	 * @param LoggerInterface|null      $logger  The logger.
	 *
	 * @return SeedTrustedDirectories
	 */
	private function step(DirectoryServiceInterface $service, ?LoggerInterface $logger = null): SeedTrustedDirectories {
		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('runAsSystem')->willReturnCallback(
			function (callable $operation) {
				$this->inSystemContext = true;
				try {
					return $operation();
				} finally {
					$this->inSystemContext = false;
				}
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		return new SeedTrustedDirectories($service, $this->config, ($logger ?? $this->createMock(LoggerInterface::class)), $container, $appManager);
	}

	/**
	 * Every directory the sync crawled before is trusted; one that fails its
	 * checks is parked for one retry; the lookup runs in the system context;
	 * a second run does nothing.
	 */
	public function testSeedsThePreviouslyCrawledDirectoriesOnce(): void {
		$trusted = [];
		$lookupInSystemContext = null;
		$service = $this->createMock(DirectoryServiceInterface::class);
		$service->expects($this->once())->method('readAllKnownDirectoryUrls')->willReturnCallback(
			function () use (&$lookupInSystemContext): array {
				$lookupInSystemContext = $this->inSystemContext;
				return ['https://a.example.org/api/directory', 'https://unresolved.example.org/api/directory', 'https://b.example.org/api/directory'];
			}
		);
		$service->method('trustDirectory')->willReturnCallback(
			static function (string $url) use (&$trusted): void {
				if (str_contains($url, 'unresolved') === true) {
					throw new InvalidArgumentException('Directory URL host could not be resolved');
				}

				$trusted[] = $url;
			}
		);

		$step = $this->step($service);
		$step->run($this->createMock(IOutput::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertTrue($lookupInSystemContext);
		$this->assertSame(['https://a.example.org/api/directory', 'https://b.example.org/api/directory'], $trusted);
		$this->assertSame('["https:\/\/unresolved.example.org\/api\/directory"]', $this->stored[DirectoryServiceInterface::CONFIG_PENDING_TRUST]);
		$this->assertSame('1', $this->stored[SeedTrustedDirectories::DONE_FLAG]);
	}

	/**
	 * pp-f5: a failed lookup is not "nothing to seed": the flag stays unset, a
	 * warning is logged, and the next run tries again.
	 */
	public function testAFailedLookupLeavesTheFlagUnsetAndWarns(): void {
		$service = $this->createMock(DirectoryServiceInterface::class);
		$service->method('readAllKnownDirectoryUrls')->willThrowException(new RuntimeException('OpenRegister schema not migrated yet'));
		$service->expects($this->never())->method('trustDirectory');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$this->step($service, $logger)->run($this->createMock(IOutput::class));

		$this->assertArrayNotHasKey(SeedTrustedDirectories::DONE_FLAG, $this->stored);
	}
}
