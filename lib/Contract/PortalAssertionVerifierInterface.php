<?php

/**
 * Contract of PortalAssertionVerifier, for callers in other components.
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
 * Contract of PortalAssertionVerifier, for callers in other components.
 *
 * Callers outside Portal/ depend on this interface instead of on the class.
 */
interface PortalAssertionVerifierInterface {
	/**
	 * The header portaliq attaches the assertion to.
	 */
	public const HEADER = 'X-Portal-Subject';

	/**
	 * The verified claims of an assertion, or null.
	 *
	 * @param string $jwt The raw header value.
	 *
	 * @return array<string, mixed>|null The claims when every check passes.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
	 */
	public function verify(string $jwt): ?array;

	/**
	 * The subject reference of a verified assertion, or null.
	 *
	 * @param string|null $header The raw `X-Portal-Subject` header value.
	 *
	 * @return string|null The `sub` claim.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
	 */
	public function subjectOf(?string $header): ?string;
}//end interface
