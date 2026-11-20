<?php

/**
 * OpenCatalogi PublicationValidationService.
 *
 * The rules a publication must meet before it is stored that are about the
 * publication itself, not about one channel. Today one: a Woo publication is
 * public by law, so a publication with a Woo information category cannot be
 * limited to signed-in readers (publications-for-signed-in-readers, REQ-PSR-004).
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
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
 * @spec openspec/changes/publications-for-signed-in-readers/specs/publications/spec.md#requirement-a-woo-publication-stays-public-req-psr-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

/**
 * Checks a publication's own rules before it is saved.
 *
 * @spec openspec/changes/publications-for-signed-in-readers/specs/publications/spec.md#requirement-a-woo-publication-stays-public-req-psr-004
 */
class PublicationValidationService {

	/**
	 * The audience of a publication for signed-in readers only.
	 */
	public const SIGNED_IN = 'signedIn';

	/**
	 * The refusal of a Woo publication limited to signed-in readers, as an l10n source string.
	 */
	public const WOO_STAYS_PUBLIC = 'A Woo publication is public by law. Remove the Woo category or make it readable for everyone.';

	/**
	 * Why a publication cannot be saved, or null when it can.
	 *
	 * @param array<string, mixed> $publication The publication as it would be stored.
	 *
	 * @return array{message: string, property: string}|null The refusal: the source string and the property it is about.
	 *
	 * @spec openspec/changes/publications-for-signed-in-readers/specs/publications/spec.md#requirement-a-woo-publication-stays-public-req-psr-004
	 */
	public function refusal(array $publication): ?array {
		if (($publication['audience'] ?? null) !== self::SIGNED_IN) {
			return null;
		}

		$category = $publication['wooCategory'] ?? null;
		if (is_string($category) === false || trim($category) === '') {
			return null;
		}

		return ['message' => self::WOO_STAYS_PUBLIC, 'property' => 'audience'];
	}//end refusal()
}//end class
