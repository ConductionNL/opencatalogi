<?php

/**
 * Tests for the one-time seed of the trusted directories (pp-f2).
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
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class SeedTrustedDirectoriesTest extends TestCase {

	/** @var array<string, string> */
	private array $stored = [];

	private IAppConfig $config;

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
	 * Every directory the sync crawled before is trusted, an unsafe one is
	 * skipped, the list is logged once, and the flag is set.
	 */
	public function testSeedsThePreviouslyCrawledDirectoriesOnce(): void {
		$trusted = [];
		$service = $this->createMock(DirectoryServiceInterface::class);
		$service->expects($this->once())->method('getKnownDirectoryUrls')->willReturn(
			['https://a.example.org/api/directory', 'http://10.0.0.1/api/directory', 'https://b.example.org/api/directory']
		);
		$service->method('trustDirectory')->willReturnCallback(
			static function (string $url) use (&$trusted): void {
				if (str_contains($url, '10.0.0.1') === true) {
					throw new InvalidArgumentException('Directory URL resolves to a disallowed (internal) address');
				}

				$trusted[] = $url;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			$this->anything(),
			$this->callback(
				static fn (array $context): bool => $context['seeded'] === ['https://a.example.org/api/directory', 'https://b.example.org/api/directory']
					&& array_keys($context['skipped']) === ['http://10.0.0.1/api/directory']
			)
		);

		$step = new SeedTrustedDirectories($service, $this->config, $logger);
		$step->run($this->createMock(IOutput::class));
		// A second run (a later upgrade) does nothing.
		$step->run($this->createMock(IOutput::class));

		$this->assertSame(['https://a.example.org/api/directory', 'https://b.example.org/api/directory'], $trusted);
		$this->assertSame('1', $this->stored[SeedTrustedDirectories::DONE_FLAG]);
	}

	/**
	 * Without OpenRegister nothing was crawled beyond the default: nothing is
	 * seeded and the flag is still set.
	 */
	public function testWithoutOpenRegisterSeedsNothingAndMarksDone(): void {
		$service = $this->createMock(DirectoryServiceInterface::class);
		$service->method('getKnownDirectoryUrls')->willThrowException(new RuntimeException('OpenRegister service is not available.'));
		$service->expects($this->never())->method('trustDirectory');

		(new SeedTrustedDirectories($service, $this->config, $this->createMock(LoggerInterface::class)))
			->run($this->createMock(IOutput::class));

		$this->assertSame('1', $this->stored[SeedTrustedDirectories::DONE_FLAG]);
	}
}
