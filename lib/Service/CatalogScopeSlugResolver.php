<?php

/**
 * Turns the register and schema slugs of a seeded catalogue into ids.
 *
 * A catalogue scope holds numeric ids: `PublicationService::isObjectInCatalogScope()`
 * runs every entry through `intval`, so a slug there reads as 0 and matches
 * nothing. A catalogue seeded from a register fragment cannot know those ids,
 * because they only exist after the import. So a seed names its register and
 * schema by slug, and this resolves them once the import has run.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-007-harvested-components-are-found-in-search-and-in-the-public-api
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use Throwable;

/**
 * Resolves slug entries in a catalogue scope to ids.
 */
class CatalogScopeSlugResolver {

	/**
	 * Replace every slug in a scope list with the id it names.
	 *
	 * Numeric entries are kept as they are. A slug that resolves to nothing is
	 * kept too, so the scope keeps saying what the seed meant and the next import
	 * can try again (for example once the schema it names has been imported).
	 *
	 * @param array<int, mixed> $entries The scope list as stored.
	 * @param callable(string): (int|string|null) $resolve Looks one slug up; null when unknown.
	 *
	 * @return array{0: array<int, mixed>, 1: bool} The resolved list, and whether anything changed.
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-007-harvested-components-are-found-in-search-and-in-the-public-api
	 */
	public static function resolve(array $entries, callable $resolve): array {
		$changed = false;
		$resolved = [];
		foreach ($entries as $entry) {
			if (is_string($entry) === false || $entry === '' || is_numeric($entry) === true) {
				$resolved[] = $entry;
				continue;
			}

			try {
				$id = $resolve($entry);
			} catch (Throwable) {
				$id = null;
			}

			if ($id === null || (string)$id === '') {
				$resolved[] = $entry;
				continue;
			}

			$resolved[] = (string)$id;
			$changed = true;
		}//end foreach

		return [$resolved, $changed];
	}//end resolve()
}//end class
