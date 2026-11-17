<?php

/**
 * OpenCatalogi Apply Feed Read Rule repair step.
 *
 * Writes who may read the OOAPI course, program and offering objects onto
 * their schemas once per upgrade, after the register import, which resets a
 * schema's authorization to the bundled one.
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
 * @spec openspec/changes/integration-feed-consumer-credentials/tasks.md#3-read-rule-and-screen
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Repair;

use OCA\OpenCatalogi\Service\FeedReadRule;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Writes the OOAPI feed read rule onto the configured OOAPI schemas.
 *
 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-a-key-reads-only-its-catalogues-protected-feeds-req-fcc-002
 */
class ApplyFeedReadRule implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param IAppManager  $appManager Tells whether OpenRegister is enabled.
	 * @param FeedReadRule $rule       Writes the rule.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly FeedReadRule $rule,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-a-key-reads-only-its-catalogues-protected-feeds-req-fcc-002
	 */
	public function getName(): string {
		return 'Write who may read the OOAPI feeds onto their schemas';
	}//end getName()

	/**
	 * Write the rule.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-a-key-reads-only-its-catalogues-protected-feeds-req-fcc-002
	 */
	public function run(IOutput $output): void {
		if ($this->appManager->isEnabledForAnyone('openregister') === false) {
			$output->warning('OpenRegister is not enabled; the OOAPI feed read rule was not written');
			return;
		}

		$output->info('OOAPI feed read rule written onto '.$this->rule->apply().' schema(s)');
	}//end run()
}//end class
