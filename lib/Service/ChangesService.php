<?php

/**
 * OpenCatalogi changed-since read.
 *
 * Tells a re-user what changed in a catalogue since a moment, and what was
 * withdrawn, so a mirror can stay current without re-reading the whole feed.
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
 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenCatalogi\Contract\DcatServiceInterface;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Exception\MalformedChangesParameterException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The changed and withdrawn publications of a catalogue since a moment.
 *
 * `changed` is read inside OpenRegister's anonymous scope, so it holds only
 * what an anonymous visitor may read. Without that scope the read is refused
 * rather than run with the caller's session. `withdrawn` names a publication
 * by id and date only, and only when a depublication record shows it was
 * public once.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Parsing, window, cursor and the two reads are small steps of one answer.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) OpenRegister, the DCAT mapping, the clock and the URL generator, nothing else.
 * @SuppressWarnings(PHPMD.StaticAccess) CatalogScopeIds, CallerScope and DateTimeImmutable::getLastErrors() are pure.
 *
 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md
 */
class ChangesService {

	/**
	 * Most entries on one page.
	 *
	 * @var integer
	 */
	public const PAGE_SIZE = 100;

	/**
	 * Most depublication records read for one answer.
	 *
	 * @var integer
	 */
	private const WITHDRAWN_LIMIT = 1000;

	/**
	 * An ISO 8601 date, or date and time, with an optional offset.
	 *
	 * @var string
	 */
	private const ISO_8601 = '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/';

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterLookupInterface $openRegister The guarded OpenRegister services.
	 * @param DcatServiceInterface $dcat The DCAT mapping the catalogue feed uses.
	 * @param ITimeFactory $time The clock.
	 * @param IURLGenerator $urls Builds the next-page URL.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly OpenRegisterLookupInterface $openRegister,
		private readonly DcatServiceInterface $dcat,
		private readonly ITimeFactory $time,
		private readonly IURLGenerator $urls,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Parse the `since` parameter as an ISO 8601 moment in UTC.
	 *
	 * @param mixed $since The parameter as the caller sent it.
	 *
	 * @return DateTimeImmutable The moment, in UTC.
	 *
	 * @throws MalformedChangesParameterException When it is missing or not ISO 8601.
	 *
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-re-user-asks-what-changed-since-a-moment-oai-006
	 */
	public function parseSince(mixed $since): DateTimeImmutable {
		$moment = self::strictMoment(value: $since);
		if ($moment === null) {
			throw new MalformedChangesParameterException(parameter: 'since');
		}

		return $moment;
	}//end parseSince()

	/**
	 * A strict ISO 8601 moment in UTC, or null when the value is not one.
	 *
	 * @param mixed $value The value as the caller sent it.
	 *
	 * @return DateTimeImmutable|null The moment, in UTC, or null.
	 *
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-re-user-asks-what-changed-since-a-moment-oai-006
	 */
	private static function strictMoment(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || preg_match(self::ISO_8601, trim($value)) !== 1) {
			return null;
		}

		$moment = self::moment(value: trim($value));
		if ($moment === null || self::parsedWithWarnings() === true) {
			return null;
		}

		return $moment;
	}//end strictMoment()

	/**
	 * Whether the last date parse reported a warning or an error, such as a day that does not exist.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-re-user-asks-what-changed-since-a-moment-oai-006
	 */
	private static function parsedWithWarnings(): bool {
		$errors = DateTimeImmutable::getLastErrors();

		return $errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
	}//end parsedWithWarnings()

	/**
	 * What changed and what was withdrawn in a catalogue since a moment.
	 *
	 * The catalogue must already be guarded for anonymous reading
	 * ({@see PublicationQueryService::applyCatalogReadRuleGuard()}).
	 *
	 * @param array<string, mixed> $catalog The guarded catalogue.
	 * @param string $catalogSlug The catalogue slug.
	 * @param DateTimeImmutable $since The moment, in UTC.
	 * @param string|null $cursor The cursor of a next page, or null for the first.
	 *
	 * @return array<string, mixed> `changed` (DCAT dataset nodes), `withdrawn` (`{id, withdrawnAt}`),
	 *                              `since`, `until` (ISO 8601, UTC) and `next` (URL or null).
	 *
	 * @throws MalformedChangesParameterException When the cursor is not one this read issued.
	 * @throws RuntimeException When OpenRegister, or its anonymous scope, is not available.
	 *
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-re-user-asks-what-changed-since-a-moment-oai-006
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-withdrawn-record-is-reported-not-shown-oai-007
	 */
	public function changesSince(array $catalog, string $catalogSlug, DateTimeImmutable $since, ?string $cursor = null): array {
		$window = $this->window(cursor: $cursor);
		$objectService = $this->anonymousObjectService();
		$scope = CatalogScopeIds::forCatalog(catalog: $catalog);
		$page = $objectService->runAsAnonymous(
			fn (): array => $this->changedPage(
				objectService: $objectService,
				catalog: $catalog + ['slug' => $catalogSlug],
				scope: $scope,
				window: ['since' => $since, 'until' => $window['until'], 'page' => $window['page']]
			)
		);

		$withdrawn = [];
		if ($window['page'] === 1) {
			$withdrawn = $this->withdrawnSince(objectService: $objectService, scope: $scope, since: $since);
		}

		return [
			'changed' => $page['changed'],
			'withdrawn' => $withdrawn,
			'since' => self::iso(moment: $since),
			'until' => self::iso(moment: $window['until']),
			'next' => $this->nextUrl(catalogSlug: $catalogSlug, since: $since, window: $window, hasMore: $page['hasMore']),
		];
	}//end changesSince()

	/**
	 * OpenRegister's object service, provided it can evaluate a read as an anonymous caller.
	 *
	 * @return object The object service.
	 *
	 * @throws RuntimeException When OpenRegister, or its anonymous scope, is not available.
	 *
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-re-user-asks-what-changed-since-a-moment-oai-006
	 */
	private function anonymousObjectService(): object {
		$objectService = $this->openRegister->installedObjectService();
		if (method_exists($objectService, 'runAsAnonymous') === false) {
			// Fail closed: without the anonymous scope a signed-in caller would see
			// drafts and restricted publications here.
			throw new RuntimeException('OpenRegister has no anonymous evaluation scope.');
		}

		return $objectService;
	}//end anonymousObjectService()

	/**
	 * The moment the read is pinned to, and the page, from the cursor or fresh.
	 *
	 * @param string|null $cursor The cursor, or null for the first page.
	 *
	 * @return array{until: DateTimeImmutable, page: int}
	 *
	 * @throws MalformedChangesParameterException When the cursor cannot be read.
	 */
	private function window(?string $cursor): array {
		if ($cursor === null || $cursor === '') {
			return [
				'until' => $this->time->now()->setTimezone(new DateTimeZone('UTC')),
				'page' => 1,
			];
		}

		$decoded = self::decodedCursor(cursor: $cursor);
		$until   = self::strictMoment(value: $decoded['until']);
		if ($until === null) {
			throw new MalformedChangesParameterException(parameter: 'cursor');
		}

		return ['until' => $until, 'page' => $decoded['page']];
	}//end window()

	/**
	 * The page and moment a cursor this read issued carries.
	 *
	 * @param string $cursor The cursor as the caller sent it.
	 *
	 * @return array{until: string, page: int}
	 *
	 * @throws MalformedChangesParameterException When the cursor cannot be read.
	 *
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-re-user-asks-what-changed-since-a-moment-oai-006
	 */
	private static function decodedCursor(string $cursor): array {
		$decoded = json_decode((string)base64_decode(strtr($cursor, '-_', '+/'), true), true);
		if (is_array($decoded) === false
			|| self::isFollowingPage(page: ($decoded['page'] ?? null)) === false
			|| is_string($decoded['until'] ?? null) === false
		) {
			throw new MalformedChangesParameterException(parameter: 'cursor');
		}

		return $decoded;
	}//end decodedCursor()

	/**
	 * Whether a cursor's page is a page after the first.
	 *
	 * @param mixed $page The page the cursor carries.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-re-user-asks-what-changed-since-a-moment-oai-006
	 */
	private static function isFollowingPage(mixed $page): bool {
		return is_int($page) === true && $page >= 2;
	}//end isFollowingPage()

	/**
	 * One page of publications changed in the window, as DCAT dataset nodes.
	 *
	 * Runs inside the anonymous scope. An empty scope reads nothing, never the
	 * whole register.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed> $catalog The guarded catalogue, with its slug.
	 * @param array{registers: array<int, int>, schemas: array<int, int>} $scope The catalogue scope.
	 * @param array{since: DateTimeImmutable, until: DateTimeImmutable, page: int} $window The window and page.
	 *
	 * @return array{changed: array<int, array<string, mixed>>, hasMore: bool}
	 */
	private function changedPage(object $objectService, array $catalog, array $scope, array $window): array {
		if (self::isEmptyScope(scope: $scope) === true) {
			return ['changed' => [], 'hasMore' => false];
		}

		$query  = self::changedQuery(scope: $scope, window: $window);
		$result = $objectService->searchObjectsPaginated(query: $query, _rbac: true, _multitenancy: false, deleted: false);
		$rows   = self::changedRows(result: $result, window: $window);

		return [
			'changed' => $this->dcat->mapDatasets(catalog: $catalog, catalogSlug: (string)$catalog['slug'], publications: $rows),
			'hasMore' => self::hasMore(result: $result, page: $window['page']),
		];
	}//end changedPage()

	/**
	 * Whether a catalog scope names no register or no schema, so it matches nothing.
	 *
	 * @param array{registers: array<int, int|string>, schemas: array<int, int|string>} $scope The catalog's scope.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-re-user-asks-what-changed-since-a-moment-oai-006
	 */
	private static function isEmptyScope(array $scope): bool {
		return $scope['registers'] === [] || $scope['schemas'] === [];
	}//end isEmptyScope()

	/**
	 * The search for one page of publications in a scope updated inside a window, oldest first.
	 *
	 * @param array{registers: array<int, int|string>, schemas: array<int, int|string>} $scope  The catalog's scope.
	 * @param array{since: DateTimeImmutable, until: DateTimeImmutable, page: int}       $window The window and page.
	 *
	 * @return array<string, mixed> The search query.
	 *
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-re-user-asks-what-changed-since-a-moment-oai-006
	 */
	private static function changedQuery(array $scope, array $window): array {
		$query = [
			'_limit' => self::PAGE_SIZE,
			'_page' => $window['page'],
			'_order' => ['updated' => 'asc', 'uuid' => 'asc'],
			'@self' => [
				'updated' => [
					'gte' => $window['since']->format('Y-m-d H:i:s'),
					'lte' => $window['until']->format('Y-m-d H:i:s'),
				],
			],
		];

		return CallerScope::writeScope(query: $query, registers: $scope['registers'], schemas: $scope['schemas']);
	}//end changedQuery()

	/**
	 * The rows of a search that changed inside the window, ordered by update moment and then id.
	 *
	 * @param array<string, mixed>                                                 $result The search result.
	 * @param array{since: DateTimeImmutable, until: DateTimeImmutable, page: int} $window The window.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-re-user-asks-what-changed-since-a-moment-oai-006
	 */
	private static function changedRows(array $result, array $window): array {
		$rows = array_values(
			array_filter(
				array_map(self::toArray(...), (array)($result['results'] ?? [])),
				static fn (array $row): bool => self::changedIn(row: $row, window: $window)
			)
		);
		usort($rows, self::compareChanged(...));

		return $rows;
	}//end changedRows()

	/**
	 * Whether a row's last change falls inside the window.
	 *
	 * The search already filters on it; this keeps the contract when an older
	 * OpenRegister ignores the metadata filter.
	 *
	 * @param array<string, mixed> $row The publication.
	 * @param array{since: DateTimeImmutable, until: DateTimeImmutable, page: int} $window The window.
	 *
	 * @return bool
	 */
	private static function changedIn(array $row, array $window): bool {
		$updated = self::updatedAt(row: $row);
		if ($updated === null) {
			return false;
		}

		return $updated >= $window['since'] && $updated <= $window['until'];
	}//end changedIn()

	/**
	 * Order by last change, then id.
	 *
	 * @param array<string, mixed> $left One publication.
	 * @param array<string, mixed> $right The other.
	 *
	 * @return int
	 */
	private static function compareChanged(array $left, array $right): int {
		$byTime = (self::updatedAt(row: $left) <=> self::updatedAt(row: $right));
		if ($byTime !== 0) {
			return $byTime;
		}

		return strcmp(self::rowId(row: $left), self::rowId(row: $right));
	}//end compareChanged()

	/**
	 * Whether a further page exists.
	 *
	 * @param array<string, mixed> $result The paginated search result.
	 * @param int $page The page that was read.
	 *
	 * @return bool
	 */
	private static function hasMore(array $result, int $page): bool {
		if (($result['next'] ?? null) !== null) {
			return true;
		}

		return isset($result['pages']) === true && $page < (int)$result['pages'];
	}//end hasMore()

	/**
	 * Publications of the catalogue withdrawn at or after the moment, by id and date only.
	 *
	 * A publication is listed only when a depublication record names it, so a
	 * draft that was never public is never named. The records and the
	 * catalogue check are system reads, and nothing but the id and the date
	 * leaves this method. A record about a single document, or about a
	 * publication outside this catalogue or no longer stored, is skipped.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param array{registers: array<int, int>, schemas: array<int, int>} $scope The catalogue scope.
	 * @param DateTimeImmutable $since The moment.
	 *
	 * @return array<int, array{id: string, withdrawnAt: string}>
	 *
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-withdrawn-record-is-reported-not-shown-oai-007
	 */
	private function withdrawnSince(object $objectService, array $scope, DateTimeImmutable $since): array {
		if (self::isEmptyScope(scope: $scope) === true) {
			return [];
		}

		$latest = $this->depublishedSince(objectService: $objectService, since: $since);
		if ($latest === []) {
			return [];
		}

		$inCatalog = $this->publicationsInScope(objectService: $objectService, scope: $scope, ids: array_keys($latest));

		return self::withdrawnEntries(latest: $latest, inCatalog: $inCatalog);
	}//end withdrawnSince()

	/**
	 * The withdrawal entries of the depublished publications that are in the catalog, oldest first.
	 *
	 * @param array<string, DateTimeImmutable> $latest    The latest depublication per publication id.
	 * @param array<string, true>              $inCatalog The publication ids in the catalog's scope.
	 *
	 * @return array<int, array{id: string, withdrawnAt: string}>
	 *
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-withdrawn-record-is-reported-not-shown-oai-007
	 */
	private static function withdrawnEntries(array $latest, array $inCatalog): array {
		$withdrawn = [];
		foreach ($latest as $id => $moment) {
			if (isset($inCatalog[$id]) === true) {
				$withdrawn[] = ['id' => (string)$id, 'withdrawnAt' => self::iso(moment: $moment)];
			}
		}

		usort(
			$withdrawn,
			static fn (array $left, array $right): int => [$left['withdrawnAt'], $left['id']] <=> [$right['withdrawnAt'], $right['id']]
		);

		return $withdrawn;
	}//end withdrawnEntries()

	/**
	 * The latest depublication moment per publication, at or after the moment.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param DateTimeImmutable $since The moment.
	 *
	 * @return array<string, DateTimeImmutable> Publication id to its latest depublication.
	 */
	private function depublishedSince(object $objectService, DateTimeImmutable $since): array {
		try {
			$config = $this->openRegister->registerConfiguration(registerKey: 'publication_register', schemaKey: 'depublication_schema');
		} catch (\Throwable $e) {
			// No depublication schema means no depublication was ever recorded.
			$this->logger->debug('[ChangesService] No depublication schema configured', ['error' => $e->getMessage()]);
			return [];
		}

		$result = $objectService->searchObjectsPaginated(
			query: [
				'_limit' => self::WITHDRAWN_LIMIT,
				'@self' => ['register' => $config['register'], 'schema' => $config['schema']],
				'depublishedAt' => ['gte' => self::iso(moment: $since)],
			],
			_rbac: false,
			_multitenancy: false,
			deleted: false
		);

		return self::latestDepublications(records: (array)($result['results'] ?? []), since: $since);
	}//end depublishedSince()

	/**
	 * The latest depublication moment per publication, of whole-publication depublications since a moment.
	 *
	 * @param array<int, mixed> $records The depublication records.
	 * @param DateTimeImmutable $since   The moment to look from.
	 *
	 * @return array<string, DateTimeImmutable> Publication id => latest depublication moment.
	 *
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-withdrawn-record-is-reported-not-shown-oai-007
	 */
	private static function latestDepublications(array $records, DateTimeImmutable $since): array {
		$latest = [];
		foreach (array_map(self::toArray(...), $records) as $record) {
			$publication = trim((string)($record['publication'] ?? ''));
			$moment      = self::depublicationMoment(record: $record, since: $since);
			if ($publication === '' || $moment === null) {
				continue;
			}

			$latest[$publication] = max($moment, ($latest[$publication] ?? $moment));
		}

		return $latest;
	}//end latestDepublications()

	/**
	 * The moment a record depublished its whole publication since a moment, or null.
	 *
	 * A record that names a file depublished that file only, not the publication.
	 *
	 * @param array<string, mixed> $record The depublication record.
	 * @param DateTimeImmutable    $since  The moment to look from.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-withdrawn-record-is-reported-not-shown-oai-007
	 */
	private static function depublicationMoment(array $record, DateTimeImmutable $since): ?DateTimeImmutable {
		$moment = self::moment(value: ($record['depublishedAt'] ?? null));
		if ($moment === null || $moment < $since || trim((string)($record['file'] ?? '')) !== '') {
			return null;
		}

		return $moment;
	}//end depublicationMoment()

	/**
	 * Which of the given publications are stored in the catalogue's scope.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param array{registers: array<int, int>, schemas: array<int, int>} $scope The catalogue scope.
	 * @param array<int, string|int> $ids The publication ids.
	 *
	 * @return array<string, true> The ids found, as keys.
	 */
	private function publicationsInScope(object $objectService, array $scope, array $ids): array {
		$ids = array_map('strval', $ids);
		$query = CallerScope::writeScope(query: ['_limit' => count($ids)], registers: $scope['registers'], schemas: $scope['schemas']);

		$result = $objectService->searchObjectsPaginated(query: $query, _rbac: false, _multitenancy: false, deleted: false, ids: $ids);

		$found = [];
		foreach (array_map(self::toArray(...), (array)($result['results'] ?? [])) as $row) {
			$id = self::rowId(row: $row);
			if (in_array($id, $ids, true) === true) {
				$found[$id] = true;
			}
		}

		return $found;
	}//end publicationsInScope()

	/**
	 * The URL of the next page, or null on the last.
	 *
	 * @param string $catalogSlug The catalogue slug.
	 * @param DateTimeImmutable $since The moment.
	 * @param array{until: DateTimeImmutable, page: int} $window The pinned moment and the current page.
	 * @param bool $hasMore Whether a further page exists.
	 *
	 * @return string|null
	 */
	private function nextUrl(string $catalogSlug, DateTimeImmutable $since, array $window, bool $hasMore): ?string {
		if ($hasMore === false) {
			return null;
		}

		return $this->urls->linkToRouteAbsolute(
			'opencatalogi.changes.changes',
			['catalogSlug' => $catalogSlug, 'since' => self::iso(moment: $since), 'cursor' => self::nextCursor(window: $window)]
		);
	}//end nextUrl()

	/**
	 * The cursor of the page after a window's page, pinned to the window's upper bound.
	 *
	 * @param array{until: DateTimeImmutable, page: int} $window The current window.
	 *
	 * @return string The url-safe cursor.
	 *
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-re-user-asks-what-changed-since-a-moment-oai-006
	 */
	private static function nextCursor(array $window): string {
		return rtrim(
			strtr(base64_encode((string)json_encode(['until' => self::iso(moment: $window['until']), 'page' => ($window['page'] + 1)])), '+/', '-_'),
			'='
		);
	}//end nextCursor()

	/**
	 * A row's last change, or null when it has none.
	 *
	 * @param array<string, mixed> $row The publication.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function updatedAt(array $row): ?DateTimeImmutable {
		return self::moment(value: ($row['@self']['updated'] ?? null));
	}//end updatedAt()

	/**
	 * A row's id: its uuid, else its id.
	 *
	 * @param array<string, mixed> $row The object.
	 *
	 * @return string
	 */
	private static function rowId(array $row): string {
		return (string)($row['@self']['uuid'] ?? $row['id'] ?? '');
	}//end rowId()

	/**
	 * A stored date as a UTC moment, or null when it is not a date.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function moment(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
		} catch (\Exception $e) {
			return null;
		}
	}//end moment()

	/**
	 * A moment as ISO 8601 in UTC.
	 *
	 * @param DateTimeImmutable $moment The moment.
	 *
	 * @return string
	 */
	private static function iso(DateTimeImmutable $moment): string {
		return $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
	}//end iso()

	/**
	 * An object as an array.
	 *
	 * @param mixed $item An array or an entity with jsonSerialize().
	 *
	 * @return array<string, mixed>
	 */
	private static function toArray(mixed $item): array {
		if (is_array($item) === true) {
			return $item;
		}

		if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
			return (array)$item->jsonSerialize();
		}

		return [];
	}//end toArray()
}//end class
