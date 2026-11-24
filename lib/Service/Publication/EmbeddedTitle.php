<?php

/**
 * The facts written into a published file: its official title and the publication identifier.
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
 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-the-published-file-carries-its-official-title-req-pff-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

/**
 * The official title and the publication identifier a file is given, and the
 * publisher's name and RSIN when they are known (REQ-PRO-002).
 *
 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-the-published-file-carries-its-official-title-req-pff-002
 */
final class EmbeddedTitle {

	/**
	 * Constructor.
	 *
	 * @param string      $title      The official title.
	 * @param string      $identifier The publication identifier.
	 * @param string|null $publisher  The publishing organisation's name.
	 * @param string|null $rsin       The publisher's RSIN, only one that passed the 11-proef.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2c
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $identifier,
		public readonly ?string $publisher=null,
		public readonly ?string $rsin=null,
	) {
	}//end __construct()
}//end class
