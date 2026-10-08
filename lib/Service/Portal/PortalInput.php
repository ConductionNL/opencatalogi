<?php

/**
 * OpenCatalogi Portal Input.
 *
 * Checks the text and file ids a resident sends to their dossier (hydra
 * `woo-citizen-journey`, C1) before anything is written.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Portal
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-resident-adds-a-public-publication-or-one-of-its-documents-to-a-dossier-req-ccol-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Portal;

use OCA\OpenCatalogi\Exception\PortalInputException;

/**
 * Checks a resident's text and file ids.
 *
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-resident-adds-a-public-publication-or-one-of-its-documents-to-a-dossier-req-ccol-002
 */
class PortalInput {

	/**
	 * A trimmed string within a length, or a refusal.
	 *
	 * @param mixed  $value The value.
	 * @param int    $max   The most characters.
	 * @param string $field The field, for the message.
	 *
	 * @return string
	 *
	 * @throws PortalInputException When it is not a string or too long.
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-resident-adds-a-public-publication-or-one-of-its-documents-to-a-dossier-req-ccol-002
	 */
	public function text(mixed $value, int $max, string $field): string {
		if ($value === null) {
			return '';
		}

		if (is_string($value) === false) {
			throw new PortalInputException(message: $field.' must be text');
		}

		$value = trim($value);
		if (mb_strlen($value) > $max) {
			throw new PortalInputException(message: $field.' is too long');
		}

		return $value;

	}//end text()

	/**
	 * A Nextcloud file id, or null for the whole publication.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 *
	 * @throws PortalInputException When it is not a file id.
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-resident-adds-a-public-publication-or-one-of-its-documents-to-a-dossier-req-ccol-002
	 */
	public function attachment(mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}

		if (is_int($value) === true) {
			$value = (string)$value;
		}

		if (is_string($value) === false) {
			$value = '';
		}

		if (preg_match('/^\d{1,20}$/', $value) !== 1) {
			throw new PortalInputException(message: 'attachment must be a file id');
		}

		return $value;

	}//end attachment()
}//end class
