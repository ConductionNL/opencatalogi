<?php

/**
 * Ready publications
 *
 * The publications in the stored state `approved` that the caller may read, so
 * a calling system can reconcile its own state against exactly the set that is
 * ready to publish (publication-lifecycle-on-or REQ-PLC-005). Read through
 * OpenRegister's object service with RBAC on: the caller's own read rights
 * decide what is listed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-caller-lists-exactly-the-publications-ready-to-publish-req-plc-005
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeImmutable;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCP\AppFramework\Db\Entity;

/**
 * Lists the approved publications a caller may read.
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-caller-lists-exactly-the-publications-ready-to-publish-req-plc-005
 */
class ReadyPublicationsService {

	/**
	 * The most approved publications one answer lists.
	 *
	 * @var int
	 */
	public const LIMIT = 1000;

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterLookupInterface $openRegister The object service and the publication register and schema.
	 */
	public function __construct(
		private readonly OpenRegisterLookupInterface $openRegister,
	) {
	}//end __construct()

	/**
	 * The approved publications, optionally since a moment of approval, for one case reference and for one source identifier.
	 *
	 * @param DateTimeImmutable|null                         $since            Only publications approved at or after it.
	 * @param string|null                                    $caseReference    Only publications carrying exactly this case reference.
	 * @param array{system: string, identifier: string}|null $sourceIdentifier Only publications carrying this entry in `sourceIdentifiers`, public or not.
	 *
	 * @return array{results: list<array<string, mixed>>, total: int, since: string|null}
	 *
	 * @throws \RuntimeException When the publication register is not configured or OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-caller-lists-exactly-the-publications-ready-to-publish-req-plc-005
	 * @spec openspec/changes/publication-relations-place-and-source-ids/tasks.md#task-2.3
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SourceIdentifierFilter is a pure function over the query and the row.
	 */
	public function ready(?DateTimeImmutable $since = null, ?string $caseReference = null, ?array $sourceIdentifier = null): array {
		$configuration = $this->openRegister->registerConfiguration(registerKey: 'publication_register', schemaKey: 'publication_schema');
		$query = [
			'@self' => ['register' => $configuration['register'], 'schema' => $configuration['schema']],
			'status' => 'approved',
			'_limit' => self::LIMIT,
		];
		if ($caseReference !== null && $caseReference !== '') {
			$query['caseReference'] = $caseReference;
		}

		if ($sourceIdentifier !== null) {
			$query = SourceIdentifierFilter::forInternalQuery(query: $query, pair: $sourceIdentifier);
		}

		$page = $this->openRegister->objectService()->searchObjectsPaginated(query: $query, _rbac: true);

		$results = [];
		foreach ((array)($page['results'] ?? []) as $row) {
			$publication = self::asArray(row: $row);
			if (self::isReady(publication: $publication, since: $since, caseReference: $caseReference) === true
				&& ($sourceIdentifier === null || SourceIdentifierFilter::holds(publication: $publication, pair: $sourceIdentifier) === true)
			) {
				$results[] = self::shown(publication: $publication);
			}
		}

		return [
			'results' => $results,
			'total' => count($results),
			'since' => $since?->format(DATE_ATOM),
		];
	}//end ready()

	/**
	 * Whether a row is approved, approved at or after `since`, and carries the asked case reference.
	 *
	 * The query already asks for these; the check is repeated so a backend that
	 * ignores a filter can never widen the answer.
	 *
	 * @param array<string, mixed>   $publication   The publication.
	 * @param DateTimeImmutable|null $since         The moment, or none.
	 * @param string|null            $caseReference The case reference, or none.
	 *
	 * @return bool
	 */
	private static function isReady(array $publication, ?DateTimeImmutable $since, ?string $caseReference): bool {
		if (($publication['status'] ?? null) !== 'approved') {
			return false;
		}

		if ($caseReference !== null && $caseReference !== '' && ($publication['caseReference'] ?? null) !== $caseReference) {
			return false;
		}

		if ($since === null) {
			return true;
		}

		$approvedAt = self::moment(value: ($publication['approvedAt'] ?? null));

		return $approvedAt !== null && $approvedAt >= $since;
	}//end isReady()

	/**
	 * One listed publication: id, title, catalog, case reference, moment of approval and last update.
	 *
	 * @param array<string, mixed> $publication The publication.
	 *
	 * @return array<string, mixed>
	 */
	private static function shown(array $publication): array {
		$self = (array)($publication['@self'] ?? []);

		return [
			'id' => (string)($publication['id'] ?? ($self['id'] ?? ($self['uuid'] ?? ''))),
			'title' => ($publication['title'] ?? null),
			'catalog' => ($publication['catalog'] ?? null),
			'caseReference' => ($publication['caseReference'] ?? null),
			'approvedAt' => ($publication['approvedAt'] ?? null),
			'updated' => ($self['updated'] ?? ($publication['updated'] ?? null)),
		];
	}//end shown()

	/**
	 * A row as an array: an entity serialised, an array as it is.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private static function asArray(mixed $row): array {
		if ($row instanceof Entity) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return [];
		}

		return $row;
	}//end asArray()

	/**
	 * A date-time, or null when it does not read as one.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function moment(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Exception $e) {
			return null;
		}
	}//end moment()
}//end class
