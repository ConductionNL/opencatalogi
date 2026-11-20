<?php

/**
 * Contract of ChangesService, for callers in other components.
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
 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

use DateTimeImmutable;
use OCA\OpenCatalogi\Exception\MalformedChangesParameterException;
use RuntimeException;

/**
 * Contract of ChangesService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 *
 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md
 */
interface ChangesServiceInterface {
	/**
	 * Parse the `since` parameter as an ISO 8601 moment in UTC.
	 *
	 * @param mixed $since The parameter as the caller sent it.
	 *
	 * @return DateTimeImmutable The moment, in UTC.
	 *
	 * @throws MalformedChangesParameterException When it is missing or not ISO 8601.
	 *
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-re-user-asks-what-changed-since-a-moment-oai-006
	 */
	public function parseSince(mixed $since): DateTimeImmutable;

	/**
	 * A catalogue by slug, guarded for anonymous reading, with its slug set.
	 *
	 * @param string $catalogSlug The catalogue slug.
	 *
	 * @return array<string, mixed>|null The guarded catalogue, or null when there is none.
	 *
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-re-user-asks-what-changed-since-a-moment-oai-006
	 */
	public function guardedCatalog(string $catalogSlug): ?array;

	/**
	 * What changed and what was withdrawn in a catalogue since a moment.
	 *
	 * The catalogue must already be guarded for anonymous reading
	 * ({@see guardedCatalog()}).
	 *
	 * @param array<string, mixed> $catalog The guarded catalogue, with its `slug`.
	 * @param array<string, mixed> $request `since` (the moment, in UTC) and `cursor` (of a next page, or null for the first).
	 *
	 * @return array<string, mixed> `changed` (DCAT dataset nodes), `withdrawn` (`{id, withdrawnAt}`),
	 *                              `since`, `until` (ISO 8601, UTC) and `next` (URL or null).
	 *
	 * @throws MalformedChangesParameterException When the cursor is not one this read issued.
	 * @throws RuntimeException When OpenRegister, or its anonymous scope, is not available.
	 *
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-re-user-asks-what-changed-since-a-moment-oai-006
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-withdrawn-record-is-reported-not-shown-oai-007
	 */
	public function changesSince(array $catalog, array $request): array;
}//end interface
