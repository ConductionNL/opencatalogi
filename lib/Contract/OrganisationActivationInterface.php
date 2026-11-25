<?php

/**
 * Contract of OrganisationActivation, for callers in other components.
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
 * The organisations an installation publishes for, activated out of the TOOI registers.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
 */
interface OrganisationActivationInterface {
	/**
	 * The organisations whose name or code holds the query, activated ones first; an empty query answers the activated ones.
	 *
	 * @param string $query What the name or code holds.
	 *
	 * @return array{results: list<array{uri: string, label: string, notation: string, active: bool, deprecated: bool}>, total: int}
	 *
	 * @throws \RuntimeException When the registers or the choices cannot be read.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
	 */
	public function search(string $query): array;

	/**
	 * Activate an organisation, linking or creating its OpenRegister organisation.
	 *
	 * @param string $uri The TOOI identifier.
	 *
	 * @return array{uri: string, label: string, organisation: string, created: bool}
	 *
	 * @throws \InvalidArgumentException When it is not a current organisation of the registers.
	 * @throws \RuntimeException         When OpenRegister cannot be read or written.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
	 */
	public function activate(string $uri): array;

	/**
	 * Deactivate an organisation; its OpenRegister organisation stays.
	 *
	 * @param string $uri The TOOI identifier.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the choices cannot be written.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
	 */
	public function deactivate(string $uri): void;

	/**
	 * The TOOI identifiers of the activated organisations; empty while nothing is activated.
	 *
	 * @return list<string>
	 *
	 * @throws \RuntimeException When the choices cannot be read.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
	 */
	public function activeEntries(): array;
}//end interface
