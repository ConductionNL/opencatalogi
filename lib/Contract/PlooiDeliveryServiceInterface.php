<?php

/**
 * Contract of PlooiDeliveryService, for callers in other components.
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
 * Contract of PlooiDeliveryService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface PlooiDeliveryServiceInterface {
	/**
	 * Deliver one publication to PLOOI when a catalogue that holds it asks for it.
	 *
	 * @param string $uuid The publication.
	 * @param string $register Its register id.
	 * @param string $schema Its schema id.
	 *
	 * @return array<string, string>|null The PLOOI fields stored, or null when no catalogue asks for PLOOI.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-publication-that-turns-public-is-delivered-to-plooi-when-the-catalogue-asks-for-it-req-wnd-003
	 */
	public function deliver(string $uuid, string $register, string $schema): ?array;
}//end interface
