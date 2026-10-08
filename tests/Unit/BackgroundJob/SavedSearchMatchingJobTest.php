<?php

/**
 * Tests for the saved-search matching job.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-notice-reaches-the-resident-through-portaliq-and-a-crash-sends-late-not-never-req-ssa-004
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\OpenCatalogi\BackgroundJob\SavedSearchMatchingJob;
use OCA\OpenCatalogi\Service\Portal\SavedSearchMatcher;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Tests for SavedSearchMatchingJob.
 */
class SavedSearchMatchingJobTest extends TestCase {

	/**
	 * Run the job's protected run().
	 *
	 * @param SavedSearchMatcher $matcher   The matcher.
	 * @param bool               $installed Whether portaliq is installed.
	 *
	 * @return void
	 */
	private function runJob(SavedSearchMatcher $matcher, bool $installed): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->with('portaliq')->willReturn($installed);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturn('Europe/Amsterdam');
		$job = new SavedSearchMatchingJob($this->createMock(ITimeFactory::class), $matcher, $apps, $config, new NullLogger());
		$run = new ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);
	}

	public function testWithoutPortaliqNothingRuns(): void {
		$matcher = $this->createMock(SavedSearchMatcher::class);
		$matcher->expects($this->never())->method('run');
		$this->runJob($matcher, false);
	}

	public function testWithPortaliqItRunsInTheInstancesTimeZone(): void {
		$matcher = $this->createMock(SavedSearchMatcher::class);
		$matcher->expects($this->once())->method('run')
			->with($this->callback(static fn (DateTimeImmutable $now): bool => $now->getTimezone()->getName() === 'Europe/Amsterdam'))
			->willReturn(['handled' => 0, 'notices' => 0, 'baselined' => 0, 'failed' => 0]);
		$this->runJob($matcher, true);
	}

	public function testTheJobIsRegistered(): void {
		$info = (string)file_get_contents(dirname(__DIR__, 3).'/appinfo/info.xml');
		$this->assertStringContainsString('<job>OCA\OpenCatalogi\BackgroundJob\SavedSearchMatchingJob</job>', $info);
	}
}
