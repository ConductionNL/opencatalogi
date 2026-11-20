<?php

/**
 * OpenCatalogi repair step: seed the processing activity the Woo schemas attribute their reads to.
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
 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-reads-of-non-public-woo-records-are-logged-req-wab-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Repair;

use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;

/**
 * Seeds `opencatalogi-woo-records` in OpenRegister's processing register (REQ-WAB-001).
 *
 * The five Woo schemas declare `x-openregister-processing` with this code as
 * their attribution. Without the activity, OpenRegister still logs every read,
 * but under its unclassified fallback activity. An existing activity with the
 * code is left as it is, so an administrator's edits survive upgrades.
 */
class SeedWooProcessingActivity implements IRepairStep {

	/**
	 * The activity's code, as the schemas name it.
	 */
	public const CODE = 'opencatalogi-woo-records';

	/**
	 * OpenRegister's processing activity entity and mapper.
	 */
	private const ENTITY = 'OCA\OpenRegister\Db\Verwerkingsactiviteit';
	private const MAPPER = 'OCA\OpenRegister\Db\VerwerkingsactiviteitMapper';

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Tells whether OpenRegister is enabled.
	 * @param ContainerInterface $container  For OpenRegister's processing activity mapper.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
	) {

	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 */
	public function getName(): string {
		return 'Seed the processing activity for reads of Woo records';
	}//end getName()

	/**
	 * Insert the activity when no activity carries its code.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-reads-of-non-public-woo-records-are-logged-req-wab-001
	 */
	public function run(IOutput $output): void {
		if ($this->appManager->isEnabledForAnyone('openregister') === false || class_exists(self::ENTITY) === false) {
			$output->warning('OpenRegister is not enabled; the Woo processing activity was not seeded');
			return;
		}

		try {
			$mapper = $this->container->get(self::MAPPER);
			if ($mapper->findByCode(code: self::CODE) !== null) {
				$output->info('The Woo processing activity already exists');
				return;
			}

			$class    = self::ENTITY;
			$activity = new $class();
			$activity->setCode(self::CODE);
			$activity->setName('Uitvoering van de Wet open overheid');
			$activity->setDescription('Reads of Woo assessments, batches, depublications, requests and publications in OpenCatalogi.');
			$activity->setPurpose('Carrying out the Woo: assessing, preparing and publishing government information.');
			$activity->setLegalBasis('legal_obligation');
			$mapper->insert(entity: $activity);
			$output->info('The Woo processing activity was seeded');
		} catch (\Throwable $e) {
			$output->warning('The Woo processing activity could not be seeded: '.$e->getMessage());
		}

	}//end run()
}//end class
