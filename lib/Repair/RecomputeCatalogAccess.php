<?php

/**
 * OpenCatalogi Recompute Catalog Access repair step.
 *
 * Writes the staff access of every catalogue onto the schemas it covers once
 * per upgrade, after the register import and the read-rule backfill, either of
 * which may have reset a schema's authorization to the bundled one.
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
 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-staff-access-becomes-openregister-rules-on-the-covered-schemas-req-csa-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Repair;

use OCA\OpenCatalogi\Contract\CatalogAccessServiceInterface;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Recomputes the rules of every schema a catalogue covers.
 *
 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-staff-access-becomes-openregister-rules-on-the-covered-schemas-req-csa-002
 */
class RecomputeCatalogAccess implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param IAppManager          $appManager The app manager.
	 * @param CatalogAccessServiceInterface $access     Writes the rules.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly CatalogAccessServiceInterface $access,
	) {

	}//end __construct()

	/**
	 * The name of this repair step.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-staff-access-becomes-openregister-rules-on-the-covered-schemas-req-csa-002
	 */
	public function getName(): string {
		return 'Write the staff access of the catalogues onto the schemas they cover';

	}//end getName()

	/**
	 * Recompute every covered schema.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/catalogue-staff-access/specs/catalogs/spec.md#requirement-staff-access-becomes-openregister-rules-on-the-covered-schemas-req-csa-002
	 */
	public function run(IOutput $output): void {
		if ($this->appManager->isEnabledForAnyone('openregister') === false) {
			$output->warning('OpenRegister is not enabled; catalogue staff access was not written');
			return;
		}

		$result = $this->access->recomputeMany(schemaIds: $this->access->coveredSchemaIds());

		$output->info('Catalogue staff access written onto '.$result['written'].' schema(s)');
		if ($result['failed'] !== []) {
			$output->warning(
				'Catalogue staff access could not be written onto schema(s) '.implode(', ', $result['failed'])
				.'; they keep their previous rules. Run `occ maintenance:repair` again once OpenRegister answers.'
			);
		}

	}//end run()
}//end class
