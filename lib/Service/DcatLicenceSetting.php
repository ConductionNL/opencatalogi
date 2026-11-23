<?php

/**
 * OpenCatalogi default DCAT licence setting as the administrator enters it.
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
 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use InvalidArgumentException;

/**
 * Turns the licence an administrator chose into the stored `dcat_default_license`.
 *
 * The value must name a member of the EU licences authority list (by IRI, code
 * or label); it is stored as that member's authority IRI. An empty value stores
 * an empty setting, so the feed carries no default licence.
 *
 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-licence-comes-from-a-controlled-list-bind-or-omit-req-dcc-001
 */
final class DcatLicenceSetting {

	/**
	 * The config key this setting is stored under.
	 */
	public const KEY = 'dcat_default_license';

	/**
	 * The value to store for what the administrator chose.
	 *
	 * @param mixed $value The chosen licence.
	 * @param DcatVocabularyService $vocabulary Resolves the licence against the bundled list.
	 *
	 * @return string The authority IRI, or an empty string.
	 *
	 * @throws InvalidArgumentException When the value is not a licence of the list.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-licence-comes-from-a-controlled-list-bind-or-omit-req-dcc-001
	 */
	public static function storable(mixed $value, DcatVocabularyService $vocabulary): string {
		if ($value === null || (is_string($value) === true && trim($value) === '')) {
			return '';
		}

		$iri = null;
		if (is_string($value) === true) {
			$iri = $vocabulary->resolveLicence(value: $value);
		}

		if ($iri === null) {
			throw new InvalidArgumentException('Setting ' . self::KEY . ' must be a licence of the EU licences authority list');
		}

		return $iri;

	}//end storable()
}//end class
