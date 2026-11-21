<?php

/**
 * ReadyPublicationsControllerTest
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-lifecycle-on-or/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Controller;

use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Controller\ReadyPublicationsController;
use OCA\OpenCatalogi\Service\Publication\ReadyPublicationsService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `GET /api/publications/ready` lists exactly the approved publications the caller may read.
 *
 * @covers \OCA\OpenCatalogi\Controller\ReadyPublicationsController
 * @covers \OCA\OpenCatalogi\Service\Publication\ReadyPublicationsService
 * @covers \OCA\OpenCatalogi\Service\Publication\SourceIdentifierFilter
 */
final class ReadyPublicationsControllerTest extends TestCase {

	private IRequest&MockObject $request;
	private IUserSession&MockObject $session;

	/** @var list<array{query: array, rbac: bool}> */
	private array $queries = [];

	/** @var list<array<string, mixed>> */
	private array $rows = [];

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->rows = [
			['id' => 'p-draft', 'title' => 'Concept', 'status' => 'draft'],
			['id' => 'p-review', 'title' => 'In beoordeling', 'status' => 'in_review'],
			['id' => 'p-yesterday', 'title' => 'Besluit gisteren', 'status' => 'approved', 'approvedAt' => '2026-11-16T15:00:00+00:00', 'caseReference' => 'Z-2026-1', 'sourceIdentifiers' => [['system' => 'decos', 'identifier' => 'Z-2026-0412', 'public' => false]], '@self' => ['updated' => '2026-11-16T15:00:01+00:00']],
			['id' => 'p-today', 'title' => 'Besluit vandaag', 'status' => 'approved', 'approvedAt' => '2026-11-17T08:30:00+00:00', 'caseReference' => 'Z-2026-2', 'catalog' => 'woo', '@self' => ['updated' => '2026-11-17T08:30:01+00:00']],
			['id' => 'p-published', 'title' => 'Gepubliceerd', 'status' => 'published'],
		];

	}//end setUp()

	/**
	 * The controller over a lookup whose object service answers $this->rows and records the query.
	 *
	 * @param array<string, string> $params The request parameters.
	 *
	 * @return ReadyPublicationsController
	 */
	private function controller(array $params = []): ReadyPublicationsController {
		$this->request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$objects = new class($this) {
			public function __construct(private readonly ReadyPublicationsControllerTest $test) {
			}

			public function searchObjectsPaginated(array $query = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->test->answer(query: $query, rbac: $_rbac);
			}
		};
		$lookup = $this->createMock(OpenRegisterLookupInterface::class);
		$lookup->method('registerConfiguration')->with('publication_register', 'publication_schema')->willReturn(['register' => 'publication', 'schema' => 'publication']);
		$lookup->method('objectService')->willReturn($objects);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ReadyPublicationsController('opencatalogi', $this->request, new ReadyPublicationsService($lookup), $this->session, $l10n, new NullLogger());

	}//end controller()

	/**
	 * The object service double: records the query and answers every row.
	 *
	 * @param array $query The query.
	 * @param bool  $rbac  Whether RBAC was on.
	 *
	 * @return array
	 */
	public function answer(array $query, bool $rbac): array {
		$this->queries[] = ['query' => $query, 'rbac' => $rbac];
		return ['results' => $this->rows, 'total' => count($this->rows)];

	}//end answer()

	/**
	 * Only approved publications are listed, read with the caller's own rights.
	 *
	 * @return void
	 */
	public function testOnlyApprovedPublicationsAreListed(): void {
		$body = $this->controller()->index()->getData();

		self::assertSame(['p-yesterday', 'p-today'], array_column($body['results'], 'id'));
		self::assertSame(2, $body['total']);
		self::assertNull($body['since']);
		self::assertSame(
			['id' => 'p-today', 'title' => 'Besluit vandaag', 'catalog' => 'woo', 'caseReference' => 'Z-2026-2', 'approvedAt' => '2026-11-17T08:30:00+00:00', 'updated' => '2026-11-17T08:30:01+00:00'],
			$body['results'][1]
		);
		self::assertTrue($this->queries[0]['rbac']);
		self::assertSame('approved', $this->queries[0]['query']['status']);
		self::assertSame(['register' => 'publication', 'schema' => 'publication'], $this->queries[0]['query']['@self']);
	}//end testOnlyApprovedPublicationsAreListed()

	/**
	 * `since` keeps only the publications approved at or after it.
	 *
	 * @return void
	 */
	public function testSinceFiltersOnTheMomentOfApproval(): void {
		$body = $this->controller(params: ['since' => '2026-11-17T00:00:00+00:00'])->index()->getData();

		self::assertSame(['p-today'], array_column($body['results'], 'id'));
		self::assertSame(1, $body['total']);
		self::assertSame('2026-11-17T00:00:00+00:00', $body['since']);
	}//end testSinceFiltersOnTheMomentOfApproval()

	/**
	 * `caseReference` is passed on as a filter and matched exactly.
	 *
	 * @return void
	 */
	public function testACaseReferenceIsMatchedExactly(): void {
		$body = $this->controller(params: ['caseReference' => 'Z-2026-1'])->index()->getData();

		self::assertSame(['p-yesterday'], array_column($body['results'], 'id'));
		self::assertSame('Z-2026-1', $this->queries[0]['query']['caseReference']);
	}//end testACaseReferenceIsMatchedExactly()

	/**
	 * `sourceIdentifier` is forwarded as a match on system and identifier, a
	 * private entry included, and repeated on the rows, so a backend that
	 * ignores the operator cannot widen the list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-relations-place-and-source-ids/tasks.md#task-2.3
	 */
	public function testASourceIdentifierFiltersTheReadyList(): void {
		$body = $this->controller(params: ['sourceIdentifier' => 'decos:Z-2026-0412'])->index()->getData();

		self::assertSame(['p-yesterday'], array_column($body['results'], 'id'));
		self::assertSame(1, $body['total']);
		self::assertSame(['contains' => ['system' => 'decos', 'identifier' => 'Z-2026-0412']], $this->queries[0]['query']['sourceIdentifiers']);
	}//end testASourceIdentifierFiltersTheReadyList()

	/**
	 * A `sourceIdentifier` that is not `<system>:<identifier>` is a 400, and nothing is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-relations-place-and-source-ids/tasks.md#task-2.3
	 */
	public function testAMalformedSourceIdentifierIsRefused(): void {
		$response = $this->controller(params: ['sourceIdentifier' => 'Z-2026-0412'])->index();

		self::assertSame(400, $response->getStatus());
		self::assertSame([], $this->queries);
	}//end testAMalformedSourceIdentifierIsRefused()

	/**
	 * Without `sourceIdentifier` the query carries no filter on the property.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-relations-place-and-source-ids/tasks.md#task-2.3
	 */
	public function testWithoutASourceIdentifierNothingIsFiltered(): void {
		$this->controller(params: ['sourceIdentifier' => '  '])->index();

		self::assertArrayNotHasKey('sourceIdentifiers', $this->queries[0]['query']);
	}//end testWithoutASourceIdentifierNothingIsFiltered()

	/**
	 * An unreadable `since` is a 400, and nothing is read.
	 *
	 * @return void
	 */
	public function testAnUnreadableSinceIsRefused(): void {
		$response = $this->controller(params: ['since' => 'gisteren'])->index();

		self::assertSame(400, $response->getStatus());
		self::assertSame([], $this->queries);
	}//end testAnUnreadableSinceIsRefused()

	/**
	 * Without a session the answer is 401 and nothing is read.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn(null);

		$response = $this->controller()->index();

		self::assertSame(401, $response->getStatus());
		self::assertSame([], $this->queries);
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * The route is authenticated, not public, and declared before the catalogue wildcard.
	 *
	 * @return void
	 */
	public function testTheRouteIsAuthenticatedAndDeclaredBeforeTheWildcard(): void {
		$method = new \ReflectionMethod(ReadyPublicationsController::class, 'index');
		$attributes = array_map(static fn (\ReflectionAttribute $a): string => $a->getName(), $method->getAttributes());
		self::assertContains('OCP\AppFramework\Http\Attribute\NoAdminRequired', $attributes);
		self::assertNotContains('OCP\AppFramework\Http\Attribute\PublicPage', $attributes);

		$urls = array_map(static fn (array $r): string => $r['verb'] . ' ' . $r['url'], array_values((include dirname(__DIR__, 3) . '/appinfo/routes.php')['routes']));
		$ready = array_search('GET /api/publications/ready', $urls, true);
		self::assertNotFalse($ready);
		self::assertLessThan(array_search('GET /api/{catalogSlug}/{id}', $urls, true), $ready);
	}//end testTheRouteIsAuthenticatedAndDeclaredBeforeTheWildcard()
}//end class
