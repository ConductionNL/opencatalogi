<?php

/**
 * OpenCatalogi DCAT licence rule: a licence comes from the EU licences authority list.
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
 * Says whether a licence names a member of the EU licences authority list (by
 * IRI, code or label), and turns the licence an administrator chose into the
 * stored `dcat_default_license`: that member's authority IRI, or an empty
 * setting, so the feed carries no default licence.
 *
 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-licence-comes-from-a-controlled-list-bind-or-omit-req-dcc-001
 */
final class DcatLicenceRule {

	/**
	 * The config key this setting is stored under.
	 */
	public const KEY = 'dcat_default_license';

	/**
	 * Constructor.
	 *
	 * @param DcatVocabularyService $vocabulary Resolves the licence against the bundled list.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly DcatVocabularyService $vocabulary,
	) {

	}//end __construct()

	/**
	 * Whether a licence is a member of the list.
	 *
	 * @param mixed $value The licence.
	 *
	 * @return bool True when it resolves to an authority IRI.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-licence-comes-from-a-controlled-list-bind-or-omit-req-dcc-001
	 */
	public function isListed(mixed $value): bool {
		return is_string($value) === true && $this->vocabulary->resolveLicence(value: $value) !== null;

	}//end isListed()

	/**
	 * The value to store for what the administrator chose.
	 *
	 * @param mixed $value The chosen licence.
	 *
	 * @return string The authority IRI, or an empty string.
	 *
	 * @throws InvalidArgumentException When the value is not a licence of the list.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-licence-comes-from-a-controlled-list-bind-or-omit-req-dcc-001
	 */
	public function storable(mixed $value): string {
		if ($value === null || (is_string($value) === true && trim($value) === '')) {
			return '';
		}

		$iri = null;
		if (is_string($value) === true) {
			$iri = $this->vocabulary->resolveLicence(value: $value);
		}

		if ($iri === null) {
			throw new InvalidArgumentException('Setting ' . self::KEY . ' must be a licence of the EU licences authority list');
		}

		return $iri;

	}//end storable()
}//end class
