<?php

/**
 * OpenCatalogi Document Reference Check.
 *
 * Once a day, asks the source system of every document reference inside its
 * window whether the document still answers, and records when and what.
 *
 * @category BackgroundJob
 * @package  OCA\OpenCatalogi\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-broken-references-are-shown-before-readers-find-them-req-pbr-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\BackgroundJob;

use OCA\OpenCatalogi\Service\DocumentReferenceProbe;
use OCA\OpenCatalogi\Service\DocumentReferences;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * The daily reachability check of document references (design D4).
 *
 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-broken-references-are-shown-before-readers-find-them-req-pbr-003
 */
class DocumentReferenceCheck extends TimedJob {

	/**
	 * The most references one run checks.
	 */
	public const PER_RUN_CAP = 500;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The clock.
	 * @param DocumentReferences $references Reads the references and records the result.
	 * @param DocumentReferenceProbe $probe Asks the source system.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly DocumentReferences $references,
		private readonly DocumentReferenceProbe $probe,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Check every reference inside its window, up to the cap.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-broken-references-are-shown-before-readers-find-them-req-pbr-003
	 */
	protected function run($argument): void {
		$this->checkAll();
	}//end run()

	/**
	 * Check every reference inside its window, up to the cap.
	 *
	 * @return array{checked: int, unreachable: int} What the run did.
	 *
	 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-broken-references-are-shown-before-readers-find-them-req-pbr-003
	 */
	public function checkAll(): array {
		$checkedAt = $this->time->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
		$checked = 0;
		$unreachable = 0;

		foreach ($this->references->inWindow(cap: self::PER_RUN_CAP) as $reference) {
			$status = $this->probe->status(url: (string)($reference['url'] ?? ''));
			$reachable = DocumentReferenceProbe::isReachable(status: $status);
			$this->references->recordCheck(reference: $reference, reachable: $reachable, status: $status, checkedAt: $checkedAt);
			$checked++;
			if ($reachable === false) {
				$unreachable++;
			}
		}

		if ($checked > 0) {
			$this->logger->info('[DocumentReferenceCheck] checked ' . $checked . ' references, ' . $unreachable . ' unreachable');
		}

		return ['checked' => $checked, 'unreachable' => $unreachable];
	}//end checkAll()
}//end class
