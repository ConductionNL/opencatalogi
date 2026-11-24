<?php

/**
 * The named arguments that narrow an OpenRegister paginated search to given object ids.
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

/**
 * `ObjectService::searchObjectsPaginated()` takes its id selection in two shapes:
 * a released OpenRegister takes `ids:` as its own argument, a newer one takes
 * `options: new SearchOptions(selection: new ObjectSelection(ids: ...))`. Spread
 * what {@see SearchSelection::byIds()} returns into the call and it works on both.
 *
 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-withdrawn-record-is-reported-not-shown-oai-007
 */
final class SearchSelection {

	/**
	 * OpenRegister's search options, named by string so the app loads without OpenRegister.
	 */
	public const OPTIONS_CLASS = 'OCA\OpenRegister\Contract\SearchOptions';

	/**
	 * OpenRegister's id selection, named by string for the same reason.
	 */
	public const SELECTION_CLASS = 'OCA\OpenRegister\Contract\ObjectSelection';

	/**
	 * The named arguments that select these ids, in the shape the installed OpenRegister takes.
	 *
	 * @param array<int, string> $ids            The object ids.
	 * @param string             $optionsClass   The search options class to look for.
	 * @param string             $selectionClass The id selection class to look for.
	 *
	 * @return array<string, mixed> `['options' => SearchOptions]` when OpenRegister has it, else `['ids' => $ids]`.
	 *
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-withdrawn-record-is-reported-not-shown-oai-007
	 */
	public static function byIds(
		array $ids,
		string $optionsClass=self::OPTIONS_CLASS,
		string $selectionClass=self::SELECTION_CLASS
	): array {
		if (class_exists($optionsClass) === true && class_exists($selectionClass) === true) {
			return ['options' => new $optionsClass(selection: new $selectionClass(ids: $ids))];
		}

		return ['ids' => $ids];
	}//end byIds()
}//end class
