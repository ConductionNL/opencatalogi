<?php

/**
 * Contract of DonlRegistrationService, for callers in other components.
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

use DateTimeInterface;
use InvalidArgumentException;

/**
 * Contract of DonlRegistrationService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface DonlRegistrationServiceInterface {
	/**
	 * The registration, the evidence and whether that evidence says harvested.
	 *
	 * @return array<string, mixed> The stored values, the operator's settings and `harvested`.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002
	 */
	public function status(): array;

	/**
	 * Record the operator's registration status and settings.
	 *
	 * @param array<string, mixed>   $input `status` and any of `apiUrl`, `source`, `harvesterPattern`.
	 * @param DateTimeInterface|null $now   The moment, now when omitted.
	 *
	 * @return array<string, mixed> The status after recording.
	 *
	 * @throws InvalidArgumentException On an unknown status, an API URL that is not https, or a pattern that does not compile.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002
	 */
	public function record(array $input, ?DateTimeInterface $now = null): array;

	/**
	 * Record a fetch of a DCAT feed when the user agent is the DONL harvester.
	 *
	 * @param string|null            $userAgent The request's user agent.
	 * @param DateTimeInterface|null $now       The moment, now when omitted.
	 *
	 * @return bool True when the fetch was recorded.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002
	 */
	public function recordFetch(?string $userAgent, ?DateTimeInterface $now = null): bool;

	/**
	 * Ask the DONL API how many datasets it holds from our source, and store the answer.
	 *
	 * @param int|null               $feedDatasetCount The number of datasets our feed carries, when known.
	 * @param DateTimeInterface|null $now              The moment, now when omitted.
	 *
	 * @return array<string, mixed> The status after the check.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002
	 */
	public function check(?int $feedDatasetCount, ?DateTimeInterface $now = null): array;
}//end interface
