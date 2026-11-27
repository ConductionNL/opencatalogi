<?php

/**
 * The information category a publication made from a Woo request is filed under.
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
 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-publication-stores-the-woo-information-category-it-belongs-to-req-wpc-001
 */

namespace OCA\OpenCatalogi\Service;

/**
 * The one information category this app files under by itself.
 *
 * The categories are data: the value-list members come from OpenRegister's
 * vocabulary register through {@see TooiVocabularyService}, and the set a
 * catalogue publishes under, operator-added categories included, is
 * {@see Woo\WooCategoryRegistry}. Ask the registry whether a code exists.
 *
 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-publication-stores-the-woo-information-category-it-belongs-to-req-wpc-001
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/tasks.md#task-2.3
 */
final class WooCategory {

	/**
	 * The category a publication made from a Woo request is filed under.
	 */
	public const WOO_REQUEST = 'infocat014';
}//end class
