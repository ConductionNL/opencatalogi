<?php

/**
 * Unit tests for SubjectPublications.
 *
 * Covers the anonymous read of the publications under a subject: drafts,
 * scheduled and withdrawn publications are never listed or counted, the list
 * is newest first and paginated, and the read fails closed without
 * OpenRegister's anonymous scope.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service\Subject;

require_once __DIR__ . '/FakeSubjectRegister.php';

use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Service\Subject\SubjectPublications;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SubjectPublications.
 */
#[CoversClass(SubjectPublications::class)]
class SubjectPublicationsTest extends TestCase {

	private const SUBJECT = '11111111-1111-4111-8111-111111111111';

	/**
	 * The service over a fake register.
	 *
	 * @param object $objectService The fake ObjectService.
	 *
	 * @return SubjectPublications
	 */
	private function service(object $objectService): SubjectPublications {
		$registers = $this->createMock(OpenRegisterLookupInterface::class);
		$registers->method('installedObjectService')->willReturn($objectService);
		$registers->method('registerConfiguration')->with('publication_register', 'publication_schema')
			->willReturn(['register' => '2', 'schema' => FakeSubjectRegister::PUBLICATION_SCHEMA]);
		return new SubjectPublications($registers);
	}

	/**
	 * Publications under the subject: three public, one draft, one scheduled, one withdrawn, one elsewhere.
	 *
	 * @return FakeSubjectRegister
	 */
	private function register(): FakeSubjectRegister {
		return new FakeSubjectRegister(
			[],
			[
				['id' => 'p1', 'themes' => [self::SUBJECT], 'publicationDate' => '2026-01-01T00:00:00+00:00'],
				['id' => 'p2', 'themes' => [self::SUBJECT], 'publicationDate' => '2026-03-01T00:00:00+00:00'],
				['id' => 'p3', 'themes' => ['other', self::SUBJECT], 'publicationDate' => '2026-02-01T00:00:00+00:00'],
				['id' => 'draft', 'themes' => [self::SUBJECT]],
				['id' => 'scheduled', 'themes' => [self::SUBJECT], 'publicationDate' => '2999-01-01T00:00:00+00:00'],
				['id' => 'withdrawn', 'themes' => [self::SUBJECT], 'publicationDate' => '2025-01-01T00:00:00+00:00', 'depublicationDate' => '2025-06-01T00:00:00+00:00'],
				['id' => 'elsewhere', 'themes' => ['other'], 'publicationDate' => '2026-01-01T00:00:00+00:00'],
			]
		);
	}

	public function testOnlyPublicPublicationsAreListedNewestFirst(): void {
		$register = $this->register();

		$page = $this->service($register)->listPublic(self::SUBJECT, 20, 1);

		$this->assertSame(['p2', 'p3', 'p1'], array_column($page['results'], 'id'));
		$this->assertSame(['total' => 3, 'limit' => 20, 'offset' => 0, 'page' => 1, 'pages' => 1], array_diff_key($page, ['results' => true]));
		$this->assertTrue($register->searches[0]['anonymous']);
		$this->assertSame(['register' => '2', 'schema' => FakeSubjectRegister::PUBLICATION_SCHEMA], $register->searches[0]['query']['@self']);
		$this->assertSame(['publicationDate' => 'DESC'], $register->searches[0]['query']['_order']);
	}

	public function testTheListIsPaginatedAndTheLimitBounded(): void {
		$register = $this->register();
		$service = $this->service($register);

		$page = $service->listPublic(self::SUBJECT, 2, 2);
		$this->assertSame(['p1'], array_column($page['results'], 'id'));
		$this->assertSame(2, $page['offset']);
		$this->assertSame(2, $page['pages']);

		$service->listPublic(self::SUBJECT, 5000, 0);
		$this->assertSame(SubjectPublications::MAX_LIMIT, $register->searches[1]['query']['_limit']);
		$this->assertSame(1, $register->searches[1]['query']['_page']);
	}

	public function testTheCountOnlyCountsPublicPublications(): void {
		$this->assertSame(3, $this->service($this->register())->countPublic(self::SUBJECT));
	}

	public function testWithoutTheAnonymousScopeNothingIsListed(): void {
		$withoutScope = new class {
			public bool $searched = false;

			public function searchObjectsPaginated(array $query = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->searched = true;
				return ['results' => [['id' => 'draft']], 'total' => 1];
			}
		};

		$service = $this->service($withoutScope);

		$this->assertSame(0, $service->countPublic(self::SUBJECT));
		$this->assertSame([], $service->listPublic(self::SUBJECT, 20, 1)['results']);
		$this->assertFalse($withoutScope->searched);
	}

	public function testASubjectWithoutAnIdListsNothing(): void {
		$register = $this->register();

		$this->assertSame(0, $this->service($register)->countPublic(''));
		$this->assertSame([], $register->searches);
	}
}
