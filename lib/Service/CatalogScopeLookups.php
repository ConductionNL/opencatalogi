<?php

/**
 * The OpenRegister lookups a catalogue scope resolves its slugs with.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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
 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-002-a-schema-slug-resolves-only-inside-the-catalogues-own-registers
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use Closure;

/**
 * Finds a register by slug, lists a register's schemas, and names a schema's slug.
 *
 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-002-a-schema-slug-resolves-only-inside-the-catalogues-own-registers
 */
final class CatalogScopeLookups {

	/**
	 * Constructor.
	 *
	 * @param Closure(string): (int|string|null) $findRegisterId Looks a register slug up.
	 * @param Closure(int): array<int, mixed> $schemaIdsOfRegister The schema ids a register lists.
	 * @param Closure(int): (string|null) $schemaSlugOf The slug of a schema id.
	 */
	public function __construct(
		public readonly Closure $findRegisterId,
		public readonly Closure $schemaIdsOfRegister,
		public readonly Closure $schemaSlugOf,
	) {

	}//end __construct()
}//end class
