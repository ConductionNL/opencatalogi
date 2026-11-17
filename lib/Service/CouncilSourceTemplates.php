<?php

/**
 * Reads the council source templates the harvest set-up writes.
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
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use RuntimeException;

/**
 * The templates per council system, read from one JSON file of the app.
 *
 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
 */
final class CouncilSourceTemplates {

	/**
	 * Constructor.
	 *
	 * @param string $appRoot The app's root directory.
	 * @param string $file    The template file, relative to the app root.
	 */
	public function __construct(
		private readonly string $appRoot,
		private readonly string $file,
	) {

	}//end __construct()

	/**
	 * The templates, keyed by council system.
	 *
	 * @return array<string, array<string, mixed>> The templates.
	 *
	 * @throws RuntimeException When the template file is missing or broken.
	 *
	 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
	 */
	public function all(): array {
		$path = $this->appRoot . $this->file;
		$raw = '';
		if (is_readable($path) === true) {
			$raw = (string)file_get_contents($path);
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded['kinds'] ?? null) === false) {
			throw new RuntimeException('The council source file ' . $this->file . ' is missing or not valid.');
		}

		return $decoded['kinds'];
	}//end all()

	/**
	 * The system an integriq source belongs to.
	 *
	 * @param string $sourceSlug The integriq source.
	 *
	 * @return string The system.
	 *
	 * @throws RuntimeException When the source is no council system this harvest knows.
	 *
	 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
	 */
	public function kindOf(string $sourceSlug): string {
		foreach ($this->all() as $kind => $template) {
			if ($template['source'] === $sourceSlug) {
				return (string)$kind;
			}
		}

		throw new RuntimeException('Choose a Notubiz or iBabs source: "' . $sourceSlug . '" is not one.');
	}//end kindOf()
}//end class
