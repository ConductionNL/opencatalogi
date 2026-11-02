<?php

/**
 * The catalog and register/schema pair an OOAPI resource read is scoped to.
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
 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-per-catalog-ooapi-5-0-resource-endpoints-ooapi-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

/**
 * A catalog and the register/schema pair its OOAPI resources are stored in.
 *
 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-per-catalog-ooapi-5-0-resource-endpoints-ooapi-001
 */
final class OoapiScope {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $catalog The catalog object.
	 * @param string $register The resolved register id.
	 * @param string $schema The resolved schema id.
	 */
	public function __construct(
		public readonly array $catalog,
		public readonly string $register,
		public readonly string $schema,
	) {

	}//end __construct()

	/**
	 * The catalog's own id, or an empty string when it has none.
	 *
	 * @return string The catalog id.
	 *
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-per-catalog-ooapi-5-0-resource-endpoints-ooapi-001
	 */
	public function catalogId(): string {
		return (string)($this->catalog['id'] ?? '');

	}//end catalogId()

	/**
	 * The id of the Organisation that owns the catalog, or null when it names none.
	 *
	 * @return string|null The organisation id.
	 *
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-organization-resource-renders-the-existing-organisation-object-live-ooapi-002
	 */
	public function organisationId(): ?string {
		$organisationId = ($this->catalog['organization'] ?? null);
		if (is_string($organisationId) === false || $organisationId === '') {
			return null;
		}

		return $organisationId;

	}//end organisationId()
}//end class
