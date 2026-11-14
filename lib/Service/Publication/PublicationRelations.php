<?php

/**
 * The relations a reader of a public publication may see, read from both sides.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-the-public-response-carries-the-relations-a-reader-may-see-req-prs-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCP\IURLGenerator;
use Throwable;

/**
 * Builds `relations: [{type, label, direction, id, title, url}]` for one publication.
 *
 * The rows come from OpenRegister's `uses` (outgoing) and `used` (incoming)
 * lookups, which carry a `relation` block with the property, the direction
 * and the label for that direction. Only the four publication relation
 * properties count. A related publication is kept only when the public read
 * path would return it: inside the catalogue's registers and schemas, past
 * the read-rule guard, and published now for an anonymous caller. Anything
 * that fails gives no relations rather than a partial or unguarded list.
 *
 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-the-public-response-carries-the-relations-a-reader-may-see-req-prs-002
 */
final class PublicationRelations {

	/**
	 * How many related objects one direction reads.
	 */
	private const LIMIT = 100;

	/**
	 * Constructor.
	 *
	 * @param PublicationQueryServiceInterface $queryService The read-rule guard and the published predicate.
	 * @param IURLGenerator $urlGenerator Makes the absolute link to a related publication.
	 */
	public function __construct(
		private readonly PublicationQueryServiceInterface $queryService,
		private readonly IURLGenerator $urlGenerator,
	) {

	}//end __construct()

	/**
	 * The relations of a publication a reader may see, outgoing first.
	 *
	 * @param object $objectService OpenRegister's ObjectService, pinned to the publication's register and schema.
	 * @param string $publicationId The publication's uuid.
	 * @param array<int, string> $registers The catalogue's registers.
	 * @param array<int, string> $schemas The catalogue's schemas.
	 * @param string $catalogSlug The catalogue the links point into.
	 *
	 * @return array<int, array{type: string, label: mixed, direction: string, id: string, title: string, url: string}> The relations.
	 *
	 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-the-public-response-carries-the-relations-a-reader-may-see-req-prs-002
	 */
	public function forPublication(
		object $objectService,
		string $publicationId,
		array $registers,
		array $schemas,
		string $catalogSlug
	): array {
		try {
			$query = ['_limit' => self::LIMIT, '_offset' => 0];
			$rows = array_merge(
				$this->guardedRows(result: $objectService->getObjectUses(objectId: $publicationId, query: $query, _rbac: true, _multitenancy: true)),
				$this->guardedRows(result: $objectService->getObjectUsedBy(objectId: $publicationId, query: $query, _rbac: true, _multitenancy: true))
			);
		} catch (Throwable $e) {
			return [];
		}

		$scope = ['registers' => array_map('strval', $registers), 'schemas' => array_map('strval', $schemas)];
		$anonymous = $this->queryService->isAnonymous();
		$relations = [];
		foreach ($rows as $row) {
			$relation = $this->relationOf(row: $row, scope: $scope, anonymous: $anonymous, catalogSlug: $catalogSlug);
			if ($relation !== null && $relation['id'] !== $publicationId) {
				$relations[] = $relation;
			}
		}

		return $relations;
	}//end forPublication()

	/**
	 * The rows of a lookup answer after the read-rule guard.
	 *
	 * @param mixed $result The lookup answer.
	 *
	 * @return array<int, mixed> The rows.
	 */
	private function guardedRows(mixed $result): array {
		if (is_array($result) === false) {
			return [];
		}

		$rows = ($this->queryService->applyReadRuleGuardToRows(result: $result)['results'] ?? []);
		if (is_array($rows) === false) {
			return [];
		}

		return array_values($rows);
	}//end guardedRows()

	/**
	 * One row as a relation, or null when the reader may not see it or it is not a publication relation.
	 *
	 * @param mixed $row The related object with its `relation` block.
	 * @param array{registers: array<int, string>, schemas: array<int, string>} $scope The catalogue scope.
	 * @param bool $anonymous Whether the caller has no session.
	 * @param string $catalogSlug The catalogue the link points into.
	 *
	 * @return array{type: string, label: mixed, direction: string, id: string, title: string, url: string}|null The relation.
	 */
	private function relationOf(mixed $row, array $scope, bool $anonymous, string $catalogSlug): ?array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false || is_array($row['relation'] ?? null) === false) {
			return null;
		}

		$type = (string)($row['relation']['property'] ?? '');
		if (in_array($type, PublicationLinkRules::RELATION_PROPERTIES, true) === false) {
			return null;
		}

		$self = (array)($row['@self'] ?? []);
		$id = (string)($self['id'] ?? $row['id'] ?? '');
		if ($id === '' || $this->inScope(self: $self, scope: $scope) === false) {
			return null;
		}

		if ($anonymous === true && $this->queryService->isObjectPublic(objectData: $row) === false) {
			return null;
		}

		return [
			'type' => $type,
			'label' => ($row['relation']['displayLabel'] ?? null),
			'direction' => (string)($row['relation']['direction'] ?? ''),
			'id' => $id,
			'title' => (string)($row['title'] ?? $self['name'] ?? ''),
			'url' => $this->urlGenerator->getAbsoluteURL('/index.php/apps/opencatalogi/api/' . rawurlencode($catalogSlug) . '/' . rawurlencode($id)),
		];
	}//end relationOf()

	/**
	 * Whether a row's register and schema are in the catalogue scope.
	 *
	 * @param array<string, mixed> $self The row's `@self`.
	 * @param array{registers: array<int, string>, schemas: array<int, string>} $scope The catalogue scope.
	 *
	 * @return bool True inside the scope.
	 */
	private function inScope(array $self, array $scope): bool {
		$register = (string)($self['register'] ?? '');
		$schema = (string)($self['schema'] ?? '');

		return $register !== '' && $schema !== ''
			&& in_array($register, $scope['registers'], true) === true
			&& in_array($schema, $scope['schemas'], true) === true;
	}//end inScope()
}//end class
