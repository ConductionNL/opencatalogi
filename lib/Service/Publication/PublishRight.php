<?php

/**
 * OpenCatalogi Publish Right.
 *
 * Publishing and withdrawing are a separate right from editing: only members of
 * the Nextcloud group `opencatalogi-publishers` and administrators hold it. The
 * publication schema enforces the same group on `publicationDate` and
 * `depublicationDate` through OpenRegister's property authorization; this class
 * answers the question for the screen and the publish endpoints before anything
 * is saved.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
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
 * @spec openspec/changes/operations-publish-rights/specs/publications/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Whether the current user may publish, and the state of the publishers group.
 *
 * @spec openspec/changes/operations-publish-rights/specs/publications/spec.md
 */
class PublishRight {

	/**
	 * The Nextcloud group whose members may publish and withdraw.
	 *
	 * The same literal is the `authorization.update` group on `publicationDate`
	 * and `depublicationDate` in lib/Settings/publication_register.json.
	 */
	public const GROUP = 'opencatalogi-publishers';

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession The current session.
	 * @param IGroupManager $groupManager Nextcloud's groups.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {

	}//end __construct()

	/**
	 * Whether the current user may publish and withdraw: a publisher or an administrator.
	 *
	 * @return boolean False without a session.
	 *
	 * @spec openspec/changes/operations-publish-rights/specs/publications/spec.md#requirement-the-screen-offers-publish-actions-only-to-publishers-req-opr-002
	 */
	public function mayPublish(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		$uid = $user->getUID();
		if ($this->groupManager->isAdmin($uid) === true) {
			return true;
		}

		return $this->groupManager->isInGroup($uid, self::GROUP);

	}//end mayPublish()

	/**
	 * The publishers group as the administrator sees it: its name, whether it
	 * exists and how many members it has.
	 *
	 * @return array{group: string, exists: bool, members: int} The group's state.
	 *
	 * @spec openspec/changes/operations-publish-rights/specs/publications/spec.md#requirement-the-group-exists-and-the-administrator-can-see-it-req-opr-004
	 */
	public function groupStatus(): array {
		$group = $this->groupManager->get(self::GROUP);
		if ($group === null) {
			return ['group' => self::GROUP, 'exists' => false, 'members' => 0];
		}

		return ['group' => self::GROUP, 'exists' => true, 'members' => (int)$group->count()];

	}//end groupStatus()
}//end class
