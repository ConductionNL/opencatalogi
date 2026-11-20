<?php

/**
 * Contract of PublicationRights, for callers in other components.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

/**
 * Contract of PublicationRights, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface PublicationRightsInterface {
	/**
	 * Whether the current user may publish and withdraw: a publisher or an
	 * administrator. Fails closed when the publish right is not wired.
	 *
	 * @return boolean
	 *
	 * @spec openspec/changes/operations-publish-rights/specs/publications/spec.md#requirement-the-publish-endpoints-refuse-a-caller-without-the-right-req-opr-003
	 */
	public function mayPublish(): bool;

	/**
	 * Whether the current user may update this publication; any failure to ask
	 * answers no.
	 *
	 * @param object $publication The publication as OpenRegister returned it.
	 *
	 * @return boolean True only when OpenRegister grants update on it.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 */
	public function mayUpdate(object $publication): bool;
}//end interface
