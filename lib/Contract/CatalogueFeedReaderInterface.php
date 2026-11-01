<?php

/**
 * Contract of CatalogueFeedReader, for callers in other components.
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
 * Contract of CatalogueFeedReader, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface CatalogueFeedReaderInterface {
	/**
	 * The newest records of one catalogue that an anonymous reader may read.
	 *
	 * @param array<string, mixed> $catalog       The catalogue (keys: schemas, registers).
	 * @param int                  $limit         How many records, newest first.
	 * @param object               $objectService OpenRegister ObjectService.
	 *
	 * @return array<string, mixed> The OpenRegister result envelope (`results`, `total`).
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
	 */
	public function read(array $catalog, int $limit, object $objectService): array;
}//end interface
