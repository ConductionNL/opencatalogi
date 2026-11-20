<?php

/**
 * What the signed-in user can read and is not yet public, for the dashboard widgets.
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

use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Contract\UnpublishedOverviewServiceInterface;

/**
 * Lists unpublished publications and documents across the catalogues a user can read.
 *
 * Both lists read the most recently updated publications of every catalogue
 * scope with OpenRegister RBAC on, so a catalogue the user cannot read never
 * contributes a row or a count. The read is bounded (ADR-058): when more
 * publications exist than the window, the answer says so with `scope`.
 *
 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md
 */
class UnpublishedOverviewService implements UnpublishedOverviewServiceInterface {

	/**
	 * The number of most recently updated publications read per request.
	 */
	public const WINDOW = UnpublishedOverviewReader::WINDOW;

	/**
	 * The scope marker returned when the window cut the read.
	 */
	public const SCOPE_RECENT = UnpublishedOverviewReader::SCOPE_RECENT;

	/**
	 * The list length when the caller asks for none.
	 */
	public const DEFAULT_LIMIT = 10;

	/**
	 * The longest list a caller can ask for.
	 */
	public const MAX_LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param UnpublishedOverviewReader $reader The bounded OpenRegister reads.
	 * @param PublicationQueryServiceInterface $publications The rule for "public".
	 * @param UnpublishedOverviewRows $rows Shapes the list rows.
	 */
	public function __construct(
		private readonly UnpublishedOverviewReader $reader,
		private readonly PublicationQueryServiceInterface $publications,
		private readonly UnpublishedOverviewRows $rows,
	) {
	}//end __construct()

	/**
	 * Clamp a requested list length to 1..MAX_LIMIT.
	 *
	 * @param int|null $limit The requested length, or null for the default.
	 *
	 * @return int The length to use.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	public static function clampLimit(?int $limit): int {
		if ($limit === null || $limit < 1) {
			return self::DEFAULT_LIMIT;
		}

		return min($limit, self::MAX_LIMIT);
	}//end clampLimit()

	/**
	 * The publications the user can read that are not yet public and not withdrawn.
	 *
	 * @param int|null $limit The list length, clamped to 1..MAX_LIMIT.
	 *
	 * @return array{items: list<array<string, mixed>>, total: int, scope: string|null} The list.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	public function publications(?int $limit): array {
		$read = $this->reader->recentPublications();

		$items = [];
		foreach ($read['publications'] as $publication) {
			// The overview asks about the publication window only: a live publication
			// for signed-in readers is published, not waiting (REQ-PSR-002).
			if ($this->publications->isObjectPublic(objectData: ['audience' => 'public'] + $publication) === true
				|| $this->rows->isLive(moment: ($publication['depublicationDate'] ?? null)) === true
			) {
				continue;
			}

			$items[] = $this->rows->publicationRow(publication: $publication, catalogs: $read['catalogs']);
		}

		return $this->page(items: $items, limit: $limit, scope: $read['scope']);
	}//end publications()

	/**
	 * The files of readable publications whose published time is empty or in the future.
	 *
	 * @param int|null $limit The list length, clamped to 1..MAX_LIMIT.
	 *
	 * @return array{items: list<array<string, mixed>>, total: int, scope: string|null} The list.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	public function documents(?int $limit): array {
		$read = $this->reader->recentPublications();

		$byUuid = $this->byUuid(publications: $read['publications']);
		if ($byUuid === []) {
			return $this->page(items: [], limit: $limit, scope: $read['scope']);
		}

		$items = $this->unpublishedFileRows(byUuid: $byUuid, catalogs: $read['catalogs']);
		usort($items, static fn (array $left, array $right): int => strcmp((string)$right['updated'], (string)$left['updated']));

		return $this->page(items: $items, limit: $limit, scope: $read['scope']);
	}//end documents()

	/**
	 * The publications keyed by uuid; a publication without one is left out.
	 *
	 * @param list<array<string, mixed>> $publications The publications.
	 *
	 * @return array<string, array<string, mixed>> The publications by uuid.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	private function byUuid(array $publications): array {
		$byUuid = [];
		foreach ($publications as $publication) {
			$uuid = (string)($publication['@self']['uuid'] ?? ($publication['id'] ?? ''));
			if ($uuid !== '') {
				$byUuid[$uuid] = $publication;
			}
		}

		return $byUuid;
	}//end byUuid()

	/**
	 * The rows of the files of these publications whose published time is empty or in the future.
	 *
	 * @param array<string, array<string, mixed>> $byUuid The publications by uuid.
	 * @param list<array<string, mixed>> $catalogs The catalogues read.
	 *
	 * @return list<array<string, mixed>> The rows, unsorted.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	private function unpublishedFileRows(array $byUuid, array $catalogs): array {
		$items = [];
		foreach ($this->reader->filesOf(uuids: array_keys($byUuid)) as $found) {
			if ($this->rows->isLive(moment: ($found['file']['published'] ?? null)) === false) {
				$items[] = $this->rows->documentRow(file: $found['file'], publication: $byUuid[$found['uuid']], catalogs: $catalogs);
			}
		}

		return $items;
	}//end unpublishedFileRows()

	/**
	 * Cut a list to its length and attach the total and scope.
	 *
	 * @param list<array<string, mixed>> $items The full list, in order.
	 * @param int|null $limit The list length, clamped to 1..MAX_LIMIT.
	 * @param string|null $scope The scope marker.
	 *
	 * @return array{items: list<array<string, mixed>>, total: int, scope: string|null} The answer.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	private function page(array $items, ?int $limit, ?string $scope): array {
		return [
			'items' => array_slice($items, 0, self::clampLimit(limit: $limit)),
			'total' => count($items),
			'scope' => $scope,
		];
	}//end page()

}//end class
