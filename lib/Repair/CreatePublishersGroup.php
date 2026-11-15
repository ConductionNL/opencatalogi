<?php

/**
 * OpenCatalogi repair step: create the publishers group.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Repair
 * @package  OCA\OpenCatalogi\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/operations-publish-rights/specs/publications/spec.md#requirement-the-group-exists-and-the-administrator-can-see-it-req-opr-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Repair;

use OCA\OpenCatalogi\Service\Publication\PublishRight;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates the group `opencatalogi-publishers` when it is missing, and adds no
 * one to it.
 *
 * An existing instance keeps working: until the group has a member, only
 * administrators can publish. Adding members implicitly would hand the publish
 * right to people nobody chose, so the step never does.
 *
 * Idempotent: a group that exists is left alone, so a second run changes
 * nothing. Never raises: a missing group is reported, and the publish right
 * still falls back to administrators.
 *
 * @spec openspec/changes/operations-publish-rights/specs/publications/spec.md#requirement-the-group-exists-and-the-administrator-can-see-it-req-opr-004
 */
class CreatePublishersGroup implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager Nextcloud's groups.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The step's name, as shown by `occ upgrade`.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/operations-publish-rights/specs/publications/spec.md#requirement-the-group-exists-and-the-administrator-can-see-it-req-opr-004
	 */
	public function getName(): string {
		return 'Create the OpenCatalogi publishers group';
	}//end getName()

	/**
	 * Create the group when it is missing.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operations-publish-rights/specs/publications/spec.md#requirement-the-group-exists-and-the-administrator-can-see-it-req-opr-004
	 */
	public function run(IOutput $output): void {
		try {
			if ($this->groupManager->groupExists(PublishRight::GROUP) === true) {
				$output->info('The group ' . PublishRight::GROUP . ' exists.');
				return;
			}

			if ($this->groupManager->createGroup(PublishRight::GROUP) === null) {
				$output->warning('The group ' . PublishRight::GROUP . ' could not be created.');
				return;
			}

			$output->info('Created the group ' . PublishRight::GROUP . '.');
		} catch (Throwable $e) {
			$this->logger->warning(
				'[CreatePublishersGroup] Could not create ' . PublishRight::GROUP . ': ' . $e->getMessage(),
				['app' => 'opencatalogi', 'exception' => $e]
			);
			$output->warning('Could not create the group ' . PublishRight::GROUP . ': ' . $e->getMessage());
		}

	}//end run()
}//end class
