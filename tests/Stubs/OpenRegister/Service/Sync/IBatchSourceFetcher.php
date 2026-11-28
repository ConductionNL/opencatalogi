<?php

/**
 * Stub for OCA\OpenRegister\Service\Sync\IBatchSourceFetcher.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sync;

use OCA\OpenRegister\Db\Source;

/**
 * Stub of the whole-document fetcher contract; mirrors the real signature.
 */
interface IBatchSourceFetcher extends SourceFetcherInterface {
	/**
	 * Read the source and return its items whole.
	 *
	 * @param Source            $source The source.
	 * @param string|null       $since  The checkpoint.
	 * @param HarvestHttpClient $http   The guarded client.
	 *
	 * @return HarvestBatch
	 */
	public function gatherItems(Source $source, ?string $since, HarvestHttpClient $http): HarvestBatch;
}//end interface
