<?php

/**
 * Unit tests for HandWooRequestsToDossiq.
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

use OCA\OpenCatalogi\Contract\InformationRequestStoreInterface;
use OCA\OpenCatalogi\Repair\HandWooRequestsToDossiq;
use OCA\OpenCatalogi\Service\Woo\StatutoryTerm;
use OCA\OpenCatalogi\Service\Handover\HandoverState;
use OCA\OpenRegister\Kernel\Contracts\TimerChange;
use OCA\OpenRegister\Kernel\Contracts\TimerSubjectRef;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The step cancels the own timer of every request dossiq moved, and of no other.
 *
 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md
 */
#[CoversClass(HandWooRequestsToDossiq::class)]
#[UsesClass(HandoverState::class)]
class HandWooRequestsToDossiqTest extends TestCase {

	/**
	 * Moved and joined requests have their timer cancelled; a failed one keeps it.
	 *
	 * @return void
	 */
	public function testMigratedRequestsHaveTheirOwnTimerCancelledAndFailedOnesKeepIt(): void {
		$answer = ['caseType' => 'ct-1', 'imports' => [[
			'key' => 'opencatalogi-woo-requests',
			'imported' => 2,
			'alreadyImported' => 1,
			'migrated' => [
				['requestId' => 'r-1', 'reference' => 'WOO-1', 'caseId' => 'c-1'],
				['requestId' => 'r-2', 'reference' => 'WOO-2', 'caseId' => 'c-2'],
			],
			'duplicates' => [['requestId' => 'r-3', 'reference' => 'WOO-2', 'caseId' => 'c-2']],
			'failed' => [['requestId' => 'r-4', 'reference' => 'WOO-4', 'reason' => 'no date']],
		]]];
		$run = $this->runStep(enabled: true, answer: $answer);

		$this->assertSame(
			expected: [
				['object', 'r-1', 'Handed over to dossiq case c-1'],
				['object', 'r-2', 'Handed over to dossiq case c-2'],
				['object', 'r-3', 'Handed over to dossiq case c-2'],
			],
			actual: $run['cancelled']
		);
		$this->assertSame(expected: ['woo-verzoek', 'opencatalogi-woo-requests'], actual: $run['imported']);
		$this->assertSame(
			expected: ['info: Woo requests handed to dossiq: 2 moved, 1 already moved, 1 not moved; 3 timers cancelled.'],
			actual: $run['output']
		);
	}//end testMigratedRequestsHaveTheirOwnTimerCancelledAndFailedOnesKeepIt()

	/**
	 * An answer in a shape the step does not know cancels nothing.
	 *
	 * @return void
	 */
	public function testAnUnexpectedAnswerCancelsNothing(): void {
		$answers = [
			null,
			['imports' => 'x'],
			['imports' => [['key' => 'other', 'migrated' => [['requestId' => 'r-1']], 'failed' => []]]],
			['imports' => [['key' => 'opencatalogi-woo-requests', 'migrated' => [['requestId' => 'r-1']]]]],
		];
		$warning = 'warning: Dossiq answered the Woo request import in an unexpected shape; no timer was cancelled.';
		foreach ($answers as $answer) {
			$run = $this->runStep(enabled: true, answer: $answer);
			$this->assertSame(expected: [], actual: $run['cancelled']);
			$this->assertSame(expected: [$warning], actual: $run['output']);
		}

		$failing = $this->runStep(enabled: true, answer: new RuntimeException('down'));
		$this->assertSame(expected: [], actual: $failing['cancelled']);
		$this->assertSame(expected: ['warning: Dossiq could not import the Woo requests: down'], actual: $failing['output']);
	}//end testAnUnexpectedAnswerCancelsNothing()

	/**
	 * Without dossiq the step imports nothing and cancels nothing.
	 *
	 * @return void
	 */
	public function testWithoutDossiqTheStepDoesNothing(): void {
		$run = $this->runStep(enabled: false, answer: ['imports' => []]);

		$this->assertSame(expected: [], actual: $run['imported']);
		$this->assertSame(expected: [], actual: $run['cancelled']);
		$this->assertSame(expected: ['info: Dossiq is not enabled: the Woo requests stay in OpenCatalogi.'], actual: $run['output']);
	}//end testWithoutDossiqTheStepDoesNothing()

	/**
	 * The step runs post-migration, after InitializeSettings.
	 *
	 * @return void
	 */
	public function testTheStepIsRegisteredPostMigration(): void {
		$xml = (string)file_get_contents(filename: __DIR__ . '/../../../appinfo/info.xml');
		$this->assertSame(expected: 1, actual: preg_match(pattern: '#<post-migration>(.*?)</post-migration>#s', subject: $xml, matches: $block));
		$block = preg_replace(pattern: '#<!--.*?-->#s', replacement: '', subject: $block[1]);
		preg_match_all(pattern: '#<step>\\s*([^<]+?)\\s*</step>#', subject: $block, matches: $found);
		$steps = $found[1];

		$this->assertContains(needle: HandWooRequestsToDossiq::class, haystack: $steps);
		$this->assertGreaterThan(
			expected: array_search(needle: 'OCA\OpenCatalogi\Repair\InitializeSettings', haystack: $steps, strict: true),
			actual: array_search(needle: HandWooRequestsToDossiq::class, haystack: $steps, strict: true)
		);
	}//end testTheStepIsRegisteredPostMigration()

	/**
	 * The step cancels timers under the subject type the statutory term stores them with.
	 *
	 * @return void
	 */
	public function testTheTimerSubjectTypeIsTheStatutoryTermsOwn(): void {
		$this->assertSame(expected: StatutoryTerm::SUBJECT_TYPE, actual: HandWooRequestsToDossiq::TIMER_SUBJECT_TYPE);
	}//end testTheTimerSubjectTypeIsTheStatutoryTermsOwn()

	/**
	 * A request dossiq moved on its own upgrade answers as already imported; its stamp stops its timer.
	 *
	 * @return void
	 */
	public function testARequestDossiqMovedEarlierHasItsTimerCancelledByItsStamp(): void {
		$answer = ['imports' => [[
			'key' => 'opencatalogi-woo-requests',
			'alreadyImported' => 2,
			'unmigrated' => 1,
			'migrated' => [['requestId' => 'r-1', 'caseId' => 'c-1']],
			'failed' => [['requestId' => 'r-4', 'reason' => 'no date']],
		]]];
		$stored = [
			['id' => 'r-1', 'migratedTo' => 'c-1'],
			['id' => 'r-5', 'migratedTo' => 'c-5', 'migratedAt' => '2026-11-01T10:00:00+00:00'],
			['id' => 'r-4'],
			['id' => '', 'migratedTo' => 'c-9'],
		];

		$run = $this->runStep(enabled: true, answer: $answer, stored: $stored);

		$this->assertSame(
			expected: [
				['object', 'r-1', 'Handed over to dossiq case c-1'],
				['object', 'r-5', 'Handed over to dossiq case c-5'],
			],
			actual: $run['cancelled']
		);
		$this->assertSame(
			expected: ['info: Woo requests handed to dossiq: 1 moved, 2 already moved, 1 not moved; 2 timers cancelled.'],
			actual: $run['output']
		);
	}//end testARequestDossiqMovedEarlierHasItsTimerCancelledByItsStamp()

	/**
	 * The counts are stored with the moment; with one left the schema stays writable.
	 *
	 * @return void
	 */
	public function testTheCountsAreStoredAndAnUnmovedRequestKeepsTheSchemaWritable(): void {
		$answer = ['imports' => [[
			'key' => 'opencatalogi-woo-requests',
			'unmigrated' => 1,
			'migrated' => [],
			'failed' => [['requestId' => 'r-4', 'reason' => 'no date']],
		]]];

		$config = $this->runStep(enabled: true, answer: $answer)['config'];

		$this->assertSame('1', $config['opencatalogi:woo_requests_unmigrated']);
		$this->assertSame('1', $config['opencatalogi:woo_requests_failed']);
		$this->assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $config['opencatalogi:woo_requests_migration_checked_at']));
		$this->assertArrayNotHasKey('opencatalogi:woo_requests_read_only', $config);
	}//end testTheCountsAreStoredAndAnUnmovedRequestKeepsTheSchemaWritable()

	/**
	 * With nothing left to move the schema becomes read-only; an unusable answer stores nothing.
	 *
	 * @return void
	 */
	public function testNothingLeftMakesTheSchemaReadOnly(): void {
		$answer = ['imports' => [['key' => 'opencatalogi-woo-requests', 'unmigrated' => 0, 'migrated' => [], 'failed' => []]]];

		$config = $this->runStep(enabled: true, answer: $answer)['config'];
		$this->assertSame('0', $config['opencatalogi:woo_requests_unmigrated']);
		$this->assertSame('1', $config['opencatalogi:woo_requests_read_only']);

		$this->assertSame([], $this->runStep(enabled: true, answer: ['imports' => 'x'])['config']);
		$this->assertSame([], $this->runStep(enabled: false, answer: $answer)['config']);
	}//end testNothingLeftMakesTheSchemaReadOnly()

	/**
	 * A store that cannot be read keeps the import's own cancellations and says why the rest waited.
	 *
	 * @return void
	 */
	public function testAnUnreadableStoreKeepsTheImportsCancellations(): void {
		$answer = ['imports' => [['key' => 'opencatalogi-woo-requests', 'migrated' => [['requestId' => 'r-1', 'caseId' => 'c-1']], 'failed' => []]]];

		$run = $this->runStep(enabled: true, answer: $answer, stored: new RuntimeException('register down'));

		$this->assertSame([['object', 'r-1', 'Handed over to dossiq case c-1']], $run['cancelled']);
		$this->assertSame(
			[
				'warning: The stored Woo requests could not be read to stop the timers of requests dossiq moved earlier: register down',
				'info: Woo requests handed to dossiq: 1 moved, 0 already moved, 0 not moved; 1 timers cancelled.',
			],
			$run['output']
		);
		$this->assertSame('0', $run['config']['opencatalogi:woo_requests_unmigrated']);
	}//end testAnUnreadableStoreKeepsTheImportsCancellations()

	/**
	 * Run the step over doubles of dossiq's import and OpenRegister's timers.
	 *
	 * @param bool $enabled Whether dossiq is enabled.
	 * @param mixed $answer The import's answer, or a Throwable it throws.
	 * @param array<int, array<string, mixed>>|\Throwable $stored The stored requests, or what reading them throws.
	 *
	 * @return array{imported: array, cancelled: array, output: array, config: array<string, string>}
	 */
	private function runStep(bool $enabled, mixed $answer, array|\Throwable $stored = []): array {
		$seen = (object)['imported' => [], 'cancelled' => [], 'output' => [], 'config' => []];

		$import = new class($answer, $seen) {
			/**
			 * Constructor.
			 *
			 * @param mixed $answer The answer, or a Throwable to throw.
			 * @param object $seen Records the call.
			 */
			public function __construct(private mixed $answer, private object $seen) {
			}

			/**
			 * Dossiq's CaseRecordImportInterface::run().
			 *
			 * @param string $caseType The case type.
			 * @param string $importKey The import.
			 *
			 * @return mixed
			 */
			public function run(string $caseType, string $importKey = ''): mixed {
				$this->seen->imported = [$caseType, $importKey];
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};

		$timers = new class($seen) {
			/**
			 * Constructor.
			 *
			 * @param object $seen Records the calls.
			 */
			public function __construct(private object $seen) {
			}

			/**
			 * OpenRegister's FlowTimerService::cancelForSubject().
			 *
			 * @param TimerSubjectRef $subject The subject.
			 * @param TimerChange $change Why and who.
			 *
			 * @return int
			 */
			public function cancelForSubject(TimerSubjectRef $subject, TimerChange $change): int {
				$this->seen->cancelled[] = [$subject->type, $subject->uuid, $change->reason];
				return 1;
			}
		};

		$store = $this->createMock(originalClassName: InformationRequestStoreInterface::class);
		if ($stored instanceof \Throwable) {
			$store->method('all')->willThrowException($stored);
		} else {
			$store->method('all')->willReturn($stored);
		}

		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('isEnabledForAnyone')->with('dossiq')->willReturn($enabled);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				HandWooRequestsToDossiq::IMPORT_CLASS => $import,
				HandWooRequestsToDossiq::TIMER_CLASS => $timers,
				InformationRequestStoreInterface::class => $store,
			}
		);
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('setValueString')->willReturnCallback(static function (string $app, string $key, string $value) use ($seen): bool {
			$seen->config[$app . ':' . $key] = $value;
			return true;
		});
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->method('info')->willReturnCallback(static function (string $message) use ($seen): void {
			$seen->output[] = 'info: ' . $message;
		});
		$output->method('warning')->willReturnCallback(static function (string $message) use ($seen): void {
			$seen->output[] = 'warning: ' . $message;
		});

		$state = new HandoverState(config: $config, appManager: $apps);
		(new HandWooRequestsToDossiq(appManager: $apps, container: $container, state: $state))->run(output: $output);

		return ['imported' => $seen->imported, 'cancelled' => $seen->cancelled, 'output' => $seen->output, 'config' => $seen->config];
	}//end runStep()
}//end class
