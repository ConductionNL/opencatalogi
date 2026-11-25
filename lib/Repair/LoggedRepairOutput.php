<?php

/**
 * OpenCatalogi Logged Repair Output.
 *
 * @category Repair
 * @package  OCA\OpenCatalogi\Repair
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
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Repair;

use OCA\OpenCatalogi\Contract\AppIdentity;
use OCP\Migration\IOutput;
use Psr\Log\LoggerInterface;

/**
 * The output of a repair step run outside `occ`: what it reports goes to the log.
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
 */
class LoggedRepairOutput implements IOutput {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Receives the step's lines.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * A debug line.
	 *
	 * @param string $message The line.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
	 */
	public function debug(string $message): void {
		$this->logger->debug($message, ['app' => AppIdentity::APP_ID]);

	}//end debug()

	/**
	 * An info line.
	 *
	 * @param string $message The line.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
	 */
	public function info($message): void {
		$this->logger->info((string)$message, ['app' => AppIdentity::APP_ID]);

	}//end info()

	/**
	 * A warning.
	 *
	 * @param string $message The warning.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
	 */
	public function warning($message): void {
		$this->logger->warning((string)$message, ['app' => AppIdentity::APP_ID]);

	}//end warning()

	/**
	 * Progress is not reported outside `occ`.
	 *
	 * @param integer $max The number of steps.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) IOutput fixes the signature.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
	 */
	public function startProgress($max=0): void {

	}//end startProgress()

	/**
	 * Progress is not reported outside `occ`.
	 *
	 * @param integer $step        The steps done.
	 * @param string  $description What was done.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) IOutput fixes the signature.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
	 */
	public function advance($step=1, $description=''): void {

	}//end advance()

	/**
	 * Progress is not reported outside `occ`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
	 */
	public function finishProgress(): void {

	}//end finishProgress()
}//end class
