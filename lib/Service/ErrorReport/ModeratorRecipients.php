<?php

/**
 * The members of an error report's moderator groups, for its notification rule.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\ErrorReport
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
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-catalogue-may-take-error-reports-from-anyone-throttled-and-moderated-req-pdp-005
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\ErrorReport;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Service\Notification\RecipientResolverInterface;
use OCP\IGroupManager;

/**
 * Resolves the `expression` recipients of the `errorReport` notification rule.
 *
 * The rule cannot name the groups itself: each catalogue names its own. The
 * report carries the catalogue's moderator groups from the moment it came in,
 * and this resolver answers their members. A group that no longer exists
 * addresses nobody.
 *
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-catalogue-may-take-error-reports-from-anyone-throttled-and-moderated-req-pdp-005
 */
class ModeratorRecipients implements RecipientResolverInterface {

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groups Resolves a group's members.
	 */
	public function __construct(
		private readonly IGroupManager $groups,
	) {

	}//end __construct()

	/**
	 * The user ids of the members of the report's moderator groups.
	 *
	 * @param ObjectEntity         $object  The error report.
	 * @param array<string, mixed> $context The notification context (unused).
	 *
	 * @return array<int, string> The user ids, each once.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface hands every resolver the context.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#5.1
	 */
	public function resolve(ObjectEntity $object, array $context): array {
		$data = $object->getObject();
		$uids = [];
		foreach ((array) ($data['moderators'] ?? []) as $gid) {
			if (is_string($gid) === false || $gid === '') {
				continue;
			}

			$group = $this->groups->get($gid);
			if ($group === null) {
				continue;
			}

			foreach ($group->getUsers() as $user) {
				$uids[] = $user->getUID();
			}
		}

		return array_values(array_unique($uids));

	}//end resolve()
}//end class
