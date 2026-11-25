<?php

/**
 * OpenCatalogi TOOI scheme refresh: the daily job that refreshes the value lists.
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
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\BackgroundJob;

use OCA\OpenCatalogi\Contract\ValueListRefreshInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Once a day, each value list OpenCatalogi uses is read again from its national source.
 *
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
 */
class TooiSchemeRefresh extends TimedJob {

	/**
	 * Daily, without parallel runs: a list changes at most a few times a year.
	 *
	 * @param ITimeFactory     $time    The clock.
	 * @param ValueListRefreshInterface $refresh Downloads and imports the lists.
	 */
	public function __construct(ITimeFactory $time, private readonly ValueListRefreshInterface $refresh) {
		parent::__construct(time: $time);
		$this->setAllowParallelRuns(allow: false);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setInterval(seconds: 86400);
	}//end __construct()

	/**
	 * Refresh every list; the job takes no argument.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The TimedJob signature.
	 */
	protected function run($argument): void {
		$this->refresh->refreshAll();
	}//end run()
}//end class
