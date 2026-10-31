<?php

/**
 * One view or download of a publication, to be counted.
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
 *
 * @spec openspec/specs/publication-usage-analytics/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

/**
 * The publication, the kind of use and the catalog it was used through.
 *
 * Unvalidated: UsageCounterServiceInterface::increment() skips what it cannot count.
 *
 * @spec openspec/specs/publication-usage-analytics/spec.md
 */
final class PublicationUsage {

	/**
	 * Constructor.
	 *
	 * @param string $publicationId The publication UUID being counted.
	 * @param string $kind UsageCounterServiceInterface::KIND_VIEW or KIND_DOWNLOAD.
	 * @param string|null $catalog Optional catalog slug for roll-up labelling.
	 */
	public function __construct(
		public readonly string $publicationId,
		public readonly string $kind,
		public readonly ?string $catalog = null,
	) {

	}//end __construct()
}//end class
