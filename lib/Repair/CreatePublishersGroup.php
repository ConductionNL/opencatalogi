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
 * @spec openspec/changes/archive/2026-11-24-operations-publish-rights/specs/publications/spec.md#requirement-the-group-exists-and-the-administrator-can-see-it-req-opr-004
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
 * Creates the groups `opencatalogi-publishers` and `opencatalogi-editors` when
 * they are missing, and adds no one to them. Editors may create and edit
 * publications; publishers may also publish and withdraw (decision 25 Nov 2026
 * 21:50 CET).
 *
 * An existing instance keeps working: until the group has a member, only
 * administrators can publish. Adding members implicitly would hand the publish
 * right to people nobody chose, so the step never does.
 *
 * Idempotent: a group that exists is left alone, so a second run changes
 * nothing. Never raises: a missing group is reported, and the publish right
 * still falls back to administrators.
 *
 * @spec openspec/changes/archive/2026-11-24-operations-publish-rights/specs/publications/spec.md#requirement-the-group-exists-and-the-administrator-can-see-it-req-opr-004
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
	 * @spec openspec/changes/archive/2026-11-24-operations-publish-rights/specs/publications/spec.md#requirement-the-group-exists-and-the-administrator-can-see-it-req-opr-004
	 */
	public function getName(): string {
		return 'Create the OpenCatalogi publishers and editors groups';
	}//end getName()

	/**
	 * Create the group when it is missing.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-operations-publish-rights/specs/publications/spec.md#requirement-the-group-exists-and-the-administrator-can-see-it-req-opr-004
	 */
	public function run(IOutput $output): void {
		foreach (PublishRight::GROUPS as $gid) {
			$this->ensure(gid: $gid, output: $output);
		}

	}//end run()

	/**
	 * Create one group when it is missing.
	 *
	 * @param string  $gid    The group id.
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-operations-publish-rights/specs/publications/spec.md#requirement-the-group-exists-and-the-administrator-can-see-it-req-opr-004
	 */
	private function ensure(string $gid, IOutput $output): void {
		try {
			if ($this->groupManager->groupExists($gid) === true) {
				$output->info('The group ' . $gid . ' exists.');
				return;
			}

			if ($this->groupManager->createGroup($gid) === null) {
				$output->warning('The group ' . $gid . ' could not be created.');
				return;
			}

			$output->info('Created the group ' . $gid . '.');
		} catch (Throwable $e) {
			$this->logger->warning(
				'[CreatePublishersGroup] Could not create ' . $gid . ': ' . $e->getMessage(),
				['app' => 'opencatalogi', 'exception' => $e]
			);
			$output->warning('Could not create the group ' . $gid . ': ' . $e->getMessage());
		}

	}//end ensure()
}//end class
