<?php

/**
 * A publish that the lifecycle does not allow from the stored state (REQ-PLC-003).
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
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-publication-is-public-only-in-the-published-state-and-every-publish-path-moves-the-lifecycle-req-plc-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use RuntimeException;

/**
 * Names the refused transition and the state it was refused from.
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-publication-is-public-only-in-the-published-state-and-every-publish-path-moves-the-lifecycle-req-plc-003
 */
class PublishRefusedException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $state The stored state the publish was refused from.
	 */
	public function __construct(public readonly string $state) {
		parent::__construct(message: sprintf('The transition publish is not allowed from the state %s.', $state));
	}//end __construct()
}//end class
