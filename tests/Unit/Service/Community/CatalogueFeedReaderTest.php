<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Community;

use OCA\OpenCatalogi\Service\Community\CatalogueFeedReader;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The catalogue feed reads what an anonymous reader may read under the
 * schemas' own read rules, inside the catalogue, for every caller
 * (REQ-PCS-105, decision 138).
 *
 * @covers \OCA\OpenCatalogi\Service\Community\CatalogueFeedReader
 * @uses   \OCA\OpenCatalogi\Service\PublicationQueryService
 * @uses   \OCA\OpenCatalogi\Service\CallerScope
 * @uses   \OCA\OpenCatalogi\Service\CatalogScopeIds
 */
class CatalogueFeedReaderTest extends TestCase {

	/**
	 * Whether the double's anonymous scope is open; the session answers no user while it is.
	 */
	private bool $inAnonymousScope = false;

	/**
	 * A reader over the real query service, with a session that is signed in
	 * outside the anonymous scope when `$signedIn` is true.
	 *
	 * @param array<int, array<string, mixed>|null> $authorizationById Schema id => authorization block.
	 * @param bool $signedIn Whether the caller is signed in.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return CatalogueFeedReader
	 */
	private function reader(array $authorizationById = [], bool $signedIn = false, ?LoggerInterface $logger = null): CatalogueFeedReader {
		$user = $this->createMock(IUser::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			fn (): ?IUser => ($signedIn === true && $this->inAnonymousScope === false) ? $user : null
		);

		$schemaMapper = new class ($authorizationById) {
			/**
			 * @param array<int, array<string, mixed>|null> $authorizations Per schema id.
			 */
			public function __construct(private array $authorizations) {
			}

			public function find(int $id): object {
				$authorization = array_key_exists($id, $this->authorizations)
					? $this->authorizations[$id]
					: ['read' => [['group' => 'public', 'match' => ['publicationDate' => ['$lte' => '$now']]]]];
				return new class ($authorization) {
					public function __construct(private ?array $authorization) {
					}

					public function getAuthorization(): ?array {
						return $this->authorization;
					}

					public function getSlug(): string {
						return 'publication';
					}
				};
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\\OpenRegister\\Db\\SchemaMapper' => $schemaMapper,
				default => throw new \RuntimeException('not available: '.$id),
			}
		);

		return new CatalogueFeedReader(
			queryService: new PublicationQueryService(container: $container, userSession: $session),
			logger: ($logger ?? $this->createMock(LoggerInterface::class))
		);

	}//end reader()

	/**
	 * An OpenRegister double that records every search with its flags and
	 * whether it ran inside the anonymous scope.
	 *
	 * @param bool $withAnonymousScope Whether it offers runAsAnonymous().
	 *
	 * @return object
	 */
	private function objectService(bool $withAnonymousScope = true): object {
		$base = new class () {
			/** @var array<int, array<string, mixed>> */
			public array $calls = [];

			/** @var \Closure(): bool */
			public \Closure $scopeOpen;

			public function buildSearchQuery(array $params): array {
				return $params;
			}

			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls[] = ['query' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy, 'anonymous' => ($this->scopeOpen)()];
				return ['results' => [['id' => 'p1', 'title' => 'Een']], 'total' => 1];
			}
		};
		$base->scopeOpen = fn (): bool => $this->inAnonymousScope;

		if ($withAnonymousScope === false) {
			return $base;
		}

		$test = $this;
		return new class ($base, $test) {
			/** @var array<int, array<string, mixed>> */
			public array $calls = [];

			public function __construct(private object $base, private CatalogueFeedReaderTest $test) {
			}

			public function runAsAnonymous(callable $operation): mixed {
				$this->test->openScope(open: true);
				try {
					return $operation();
				} finally {
					$this->test->openScope(open: false);
					$this->calls = $this->base->calls;
				}
			}

			public function buildSearchQuery(array $params): array {
				return $this->base->buildSearchQuery($params);
			}

			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->base->searchObjectsPaginated($query, $_rbac, $_multitenancy);
			}
		};

	}//end objectService()

	/**
	 * Open or close the double's anonymous scope.
	 *
	 * @param bool $open Whether the scope is open.
	 *
	 * @return void
	 */
	public function openScope(bool $open): void {
		$this->inAnonymousScope = $open;

	}//end openScope()

	/**
	 * The read runs inside the anonymous scope, with the schema read rules as
	 * the filter, over the catalogue's own registers and schemas.
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
	 */
	public function testTheFeedReadsAsAnAnonymousReaderInsideTheCatalogue(): void {
		$or = $this->objectService();

		$result = $this->reader()->read(catalog: ['registers' => [1], 'schemas' => [1]], limit: 50, objectService: $or);

		$this->assertSame([['id' => 'p1', 'title' => 'Een']], $result['results']);
		$this->assertCount(1, $or->calls);
		$call = $or->calls[0];
		$this->assertTrue($call['anonymous'], 'the feed read must run inside runAsAnonymous()');
		$this->assertTrue($call['rbac'], 'the public read rules are the filter, so RBAC stays on');
		$this->assertFalse($call['multitenancy']);
		$this->assertSame([1], $call['query']['_schemas']);
		$this->assertSame(1, $call['query']['_register']);
		$this->assertSame(50, $call['query']['_limit']);
		$this->assertSame(['@self.updated' => 'desc'], $call['query']['_order']);

	}//end testTheFeedReadsAsAnAnonymousReaderInsideTheCatalogue()

	/**
	 * A schema without read rules leaves the feed for a SIGNED-IN caller too:
	 * the feed is public, so it carries what an anonymous reader may read.
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
	 */
	public function testTheFeedDropsASchemaWithoutReadRulesEvenForASignedInCaller(): void {
		$or = $this->objectService();

		$this->reader(authorizationById: [2 => []], signedIn: true)->read(
			catalog: ['registers' => [1], 'schemas' => [1, 2]],
			limit: 50,
			objectService: $or
		);

		$this->assertSame([1], $or->calls[0]['query']['_schemas']);

	}//end testTheFeedDropsASchemaWithoutReadRulesEvenForASignedInCaller()

	/**
	 * A catalogue none of whose schemas carries read rules has an empty feed,
	 * and OpenRegister is not asked: an empty `_schemas` would search every table.
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
	 */
	public function testAFeedWithNoReadableSchemaIsEmptyAndReadsNothing(): void {
		$or = $this->objectService();

		$result = $this->reader(authorizationById: [1 => null])->read(
			catalog: ['registers' => [1], 'schemas' => [1]],
			limit: 50,
			objectService: $or
		);

		$this->assertSame(['results' => [], 'total' => 0], $result);
		$this->assertSame([], $or->calls);

	}//end testAFeedWithNoReadableSchemaIsEmptyAndReadsNothing()

	/**
	 * Without OpenRegister's anonymous scope a signed-in caller gets an empty
	 * feed and the operator is told why, rather than a feed read with the
	 * caller's own rights.
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
	 */
	public function testWithoutTheAnonymousScopeASignedInCallerGetsAnEmptyFeed(): void {
		$or = $this->objectService(withAnonymousScope: false);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($this->stringContains('runAsAnonymous'));

		$result = $this->reader(signedIn: true, logger: $logger)->read(
			catalog: ['registers' => [1], 'schemas' => [1]],
			limit: 50,
			objectService: $or
		);

		$this->assertSame(['results' => [], 'total' => 0], $result);
		$this->assertSame([], $or->calls);

	}//end testWithoutTheAnonymousScopeASignedInCallerGetsAnEmptyFeed()

	/**
	 * Without OpenRegister's anonymous scope an anonymous caller is still read
	 * for, under the same read-rule guard.
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
	 */
	public function testWithoutTheAnonymousScopeAnAnonymousCallerIsStillRead(): void {
		$or = $this->objectService(withAnonymousScope: false);

		$result = $this->reader(authorizationById: [2 => []])->read(
			catalog: ['registers' => [1], 'schemas' => [1, 2]],
			limit: 50,
			objectService: $or
		);

		$this->assertSame(1, $result['total']);
		$this->assertSame([1], $or->calls[0]['query']['_schemas']);

	}//end testWithoutTheAnonymousScopeAnAnonymousCallerIsStillRead()

	/**
	 * A draft never reaches the feed because the publication schema's own
	 * public read rules require a publication date that has passed. Pinned on
	 * the schema this app ships, not on a fake.
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
	 */
	public function testThePublicationSchemaKeepsADraftFromAnAnonymousReader(): void {
		$register = json_decode((string) file_get_contents(__DIR__.'/../../../../lib/Settings/publication_register.json'), true);
		$read = $register['components']['schemas']['publication']['authorization']['read'];

		$publicRules = array_values(array_filter($read, static fn ($rule): bool => is_array($rule) && ($rule['group'] ?? '') === 'public'));
		$this->assertNotEmpty($publicRules);
		foreach ($publicRules as $rule) {
			$this->assertSame(['$lte' => '$now'], $rule['match']['publicationDate'], 'every public rule needs a publication date in the past');
		}

		$this->assertNotContains('public', $read, 'no unconditional public read on the publication schema');

	}//end testThePublicationSchemaKeepsADraftFromAnAnonymousReader()
}//end class
