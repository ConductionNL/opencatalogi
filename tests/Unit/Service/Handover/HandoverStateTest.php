<?php

/**
 * Unit tests for HandoverState.
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

namespace Unit\Service\Handover;

use OCA\OpenCatalogi\Service\Handover\HandoverState;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The hand-over counts and the read-only flag, as stored in app config.
 *
 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/tasks.md#task-3.2
 */
#[CoversClass(HandoverState::class)]
class HandoverStateTest extends TestCase {

	/**
	 * The stored values, by key.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * The state over an in-memory app config.
	 *
	 * @param bool $dossiq Whether dossiq is enabled.
	 *
	 * @return HandoverState The state.
	 */
	private function state(bool $dossiq = true): HandoverState {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->stored[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->assertSame('opencatalogi', $app);
				$this->stored[$key] = $value;
				return true;
			}
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->with('dossiq')->willReturn($dossiq);

		return new HandoverState(config: $config, appManager: $apps);
	}//end state()

	/**
	 * Before any run nothing is counted and the schema is writable.
	 *
	 * @return void
	 */
	public function testBeforeAnyRunNothingIsCounted(): void {
		$this->assertSame(
			['unmigrated' => null, 'failed' => null, 'checkedAt' => '', 'readOnly' => false, 'dossiqInstalled' => false],
			$this->state(dossiq: false)->report()
		);
	}//end testBeforeAnyRunNothingIsCounted()

	/**
	 * A run with requests left stores the counts and keeps the schema writable.
	 *
	 * @return void
	 */
	public function testARunWithRequestsLeftIsReported(): void {
		$state = $this->state();
		$state->record(unmigrated: 3, failed: 2, checkedAt: '2026-11-15T10:00:00+00:00');

		$this->assertSame(
			['unmigrated' => 3, 'failed' => 2, 'checkedAt' => '2026-11-15T10:00:00+00:00', 'readOnly' => false, 'dossiqInstalled' => true],
			$state->report()
		);
	}//end testARunWithRequestsLeftIsReported()

	/**
	 * Nothing left makes the schema read-only, and a later count does not undo it.
	 *
	 * @return void
	 */
	public function testNothingLeftMakesItReadOnlyForGood(): void {
		$state = $this->state();
		$state->record(unmigrated: 0, failed: 0, checkedAt: '2026-11-15T10:00:00+00:00');
		$this->assertTrue($state->isReadOnly());

		$state->record(unmigrated: 1, failed: 1, checkedAt: '2026-11-16T10:00:00+00:00');
		$this->assertTrue($state->isReadOnly());
		$this->assertSame(1, $state->report()['unmigrated']);
	}//end testNothingLeftMakesItReadOnlyForGood()

	/**
	 * A negative count is stored as zero, and a stored value that is not a count reads as none.
	 *
	 * @return void
	 */
	public function testCountsAreNeverNegativeAndJunkReadsAsNone(): void {
		$state = $this->state();
		$state->record(unmigrated: -1, failed: -4, checkedAt: 'x');
		$this->assertSame(0, $state->report()['unmigrated']);
		$this->assertFalse($state->isReadOnly());

		$this->stored['woo_requests_failed'] = 'many';
		$this->assertNull($state->report()['failed']);
	}//end testCountsAreNeverNegativeAndJunkReadsAsNone()
}//end class
