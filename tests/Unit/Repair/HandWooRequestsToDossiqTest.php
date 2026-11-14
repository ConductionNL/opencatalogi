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

use OCA\OpenCatalogi\Repair\HandWooRequestsToDossiq;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The step cancels the own timer of every request dossiq moved, and of no other.
 *
 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md
 */
#[CoversClass(HandWooRequestsToDossiq::class)]
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
	 * Run the step over doubles of dossiq's import and OpenRegister's timers.
	 *
	 * @param bool $enabled Whether dossiq is enabled.
	 * @param mixed $answer The import's answer, or a Throwable it throws.
	 *
	 * @return array{imported: array, cancelled: array, output: array}
	 */
	private function runStep(bool $enabled, mixed $answer): array {
		$seen = (object)['imported' => [], 'cancelled' => [], 'output' => []];

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
			 * @param string $subjectType The subject type.
			 * @param string $subjectUuid The subject.
			 * @param string $reason Why.
			 * @param string|null $actor Who.
			 *
			 * @return int
			 */
			public function cancelForSubject(string $subjectType, string $subjectUuid, string $reason, ?string $actor): int {
				$this->seen->cancelled[] = [$subjectType, $subjectUuid, $reason];
				return 1;
			}
		};

		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('isEnabledForAnyone')->with('dossiq')->willReturn($enabled);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				HandWooRequestsToDossiq::IMPORT_CLASS => $import,
				HandWooRequestsToDossiq::TIMER_CLASS => $timers,
			}
		);
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->method('info')->willReturnCallback(static function (string $message) use ($seen): void {
			$seen->output[] = 'info: ' . $message;
		});
		$output->method('warning')->willReturnCallback(static function (string $message) use ($seen): void {
			$seen->output[] = 'warning: ' . $message;
		});

		(new HandWooRequestsToDossiq(appManager: $apps, container: $container))->run(output: $output);

		return ['imported' => $seen->imported, 'cancelled' => $seen->cancelled, 'output' => $seen->output];
	}//end runStep()
}//end class
