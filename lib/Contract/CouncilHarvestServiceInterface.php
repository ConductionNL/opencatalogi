<?php

/**
 * Contract of CouncilHarvestService, for callers in other components.
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
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

use RuntimeException;

/**
 * The council documents harvest an administrator sets up, switches and runs.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 *
 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
 */
interface CouncilHarvestServiceInterface {
	/**
	 * Everything the admin section shows: the chosen source, publish on arrival, the flow and its last run.
	 *
	 * @return array<string, mixed> The status.
	 *
	 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
	 */
	public function status(): array;

	/**
	 * Write the integriq synchronization and fetch-file rule for one council source.
	 *
	 * @param string $sourceSlug The integriq source, `notubiz-ris-v1` or `ibabs-ris-v1`.
	 *
	 * @return array<string, mixed> What was written, and the status after it.
	 *
	 * @throws RuntimeException Naming the missing piece: integriq, OpenRegister, the source or a setting it needs.
	 *
	 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
	 */
	public function setUp(string $sourceSlug): array;

	/**
	 * Switch the harvest of the set-up source on or off.
	 *
	 * @param bool $enabled Whether it should run.
	 *
	 * @return array<string, mixed> The status after the switch.
	 *
	 * @throws RuntimeException When the harvest is not set up or its flow is not imported.
	 *
	 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
	 */
	public function setEnabled(bool $enabled): array;

	/**
	 * Whether harvested documents are public on arrival instead of concepts.
	 *
	 * @param bool $publish True to keep the source's publication date.
	 *
	 * @return array<string, mixed> The status after the change.
	 *
	 * @throws RuntimeException When the harvest is not set up or its flow is not imported.
	 *
	 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-each-public-council-document-becomes-a-publication-with-its-file-req-cdh-002
	 */
	public function setPublishOnArrival(bool $publish): array;

	/**
	 * Start a run now; the flow engine's worker advances it.
	 *
	 * @return array<string, mixed> The queued run.
	 *
	 * @throws RuntimeException When the harvest is not set up and switched on.
	 *
	 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
	 */
	public function runNow(): array;
}//end interface
