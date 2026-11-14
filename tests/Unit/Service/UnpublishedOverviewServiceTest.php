<?php

/**
 * Tests for UnpublishedOverviewService.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Service\InstanceSettings;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\UnpublishedOverviewService;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
 */
class UnpublishedOverviewServiceTest extends TestCase {

	/**
	 * The search calls the object service received.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $searches = [];

	/**
	 * A publication row as OpenRegister returns it.
	 *
	 * @param string $uuid The uuid.
	 * @param string $title The title.
	 * @param string|null $publicationDate The publication date.
	 * @param string|null $depublicationDate The depublication date.
	 * @param string $updated The update time.
	 * @param int $schema The schema id.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function publication(string $uuid, string $title, ?string $publicationDate, ?string $depublicationDate, string $updated, int $schema = 7): array {
		return [
			'title' => $title,
			'publicationDate' => $publicationDate,
			'depublicationDate' => $depublicationDate,
			'@self' => ['uuid' => $uuid, 'register' => 3, 'schema' => $schema, 'updated' => $updated],
		];
	}//end publication()

	/**
	 * The four publications of the scenario: a draft, a scheduled, a public and a withdrawn one.
	 *
	 * @return list<array<string, mixed>> The rows, newest first.
	 */
	private function scenario(): array {
		return [
			$this->publication('draft', 'Draft', null, null, '2026-11-10T10:00:00+00:00'),
			$this->publication('scheduled', 'Scheduled', '2099-01-01T00:00:00+00:00', null, '2026-11-09T10:00:00+00:00'),
			$this->publication('public', 'Public', '2020-01-01T00:00:00+00:00', null, '2026-11-08T10:00:00+00:00'),
			$this->publication('withdrawn', 'Withdrawn', '2020-01-01T00:00:00+00:00', '2021-01-01T00:00:00+00:00', '2026-11-07T10:00:00+00:00'),
		];
	}//end scenario()

	/**
	 * Build the service over doubles.
	 *
	 * @param list<array<string, mixed>> $publications What the RBAC search returns.
	 * @param int|null $total The search total, defaulting to the row count.
	 * @param array<string, list<int>> $fileIds Files per publication uuid.
	 * @param array<int, array<string, mixed>> $files File records by id.
	 * @param string $catalogRegister The configured catalogue register.
	 *
	 * @return UnpublishedOverviewService The service.
	 */
	private function service(array $publications, ?int $total = null, array $fileIds = [], array $files = [], string $catalogRegister = 'catalogs'): UnpublishedOverviewService {
		$catalogs = [
			['slug' => 'woo', 'registers' => [3], 'schemas' => [7]],
			['slug' => 'other', 'registers' => [4], 'schemas' => [8]],
		];
		$searches = &$this->searches;
		$objectService = new class($catalogs, $publications, $total, $searches) {
			/**
			 * @param list<array<string, mixed>> $catalogs Catalogue rows.
			 * @param list<array<string, mixed>> $publications Publication rows.
			 * @param int|null $total The publication total.
			 * @param list<array<string, mixed>> $searches The recorded calls.
			 */
			public function __construct(
				private array $catalogs,
				private array $publications,
				private ?int $total,
				private array &$searches,
			) {
			}

			/**
			 * @param array<string, mixed> $query The query.
			 * @param bool $_rbac RBAC switch.
			 * @param bool $_multitenancy Multitenancy switch.
			 * @param bool $deleted Deleted switch.
			 *
			 * @return array<string, mixed> The page.
			 */
			public function searchObjectsPaginated(array $query, bool $_rbac, bool $_multitenancy, bool $deleted): array {
				$this->searches[] = ['query' => $query, 'rbac' => $_rbac];
				if (($query['@self']['schema'] ?? null) === 'catalog') {
					return ['results' => $this->catalogs, 'total' => count($this->catalogs)];
				}

				return ['results' => $this->publications, 'total' => ($this->total ?? count($this->publications))];
			}
		};
		$fileMapper = new class($fileIds, $files) {
			/**
			 * @param array<string, list<int>> $fileIds Files per uuid.
			 * @param array<int, array<string, mixed>> $files Files by id.
			 */
			public function __construct(private array $fileIds, private array $files) {
			}

			/**
			 * @param list<string> $uuids The uuids.
			 *
			 * @return array<string, list<int>> Files per uuid.
			 */
			public function getFileIdsForObjects(array $uuids): array {
				return array_intersect_key($this->fileIds, array_flip($uuids));
			}

			/**
			 * @param list<int|string> $ids The ids.
			 *
			 * @return array<int|string, array<string, mixed>> Files by id.
			 */
			public function getFilesByIds(array $ids): array {
				return array_intersect_key($this->files, array_flip($ids));
			}
		};

		$lookup = $this->createMock(OpenRegisterLookup::class);
		$lookup->method('installedObjectService')->willReturn($objectService);
		$lookup->method('installedService')->willReturn($fileMapper);

		$instance = $this->createMock(InstanceSettings::class);
		$instance->method('stringValue')->willReturnMap(
			[
				['catalog_register', '', $catalogRegister],
				['catalog_schema', '', 'catalog'],
			]
		);

		$rule = $this->createMock(PublicationQueryServiceInterface::class);
		$rule->method('isObjectPublic')->willReturnCallback(
			static function (array $objectData): bool {
				$date = ($objectData['publicationDate'] ?? null);
				$withdrawn = ($objectData['depublicationDate'] ?? null);
				return $date !== null && strtotime($date) <= time() && ($withdrawn === null || strtotime($withdrawn) > time());
			}
		);

		return new UnpublishedOverviewService(openRegister: $lookup, instance: $instance, publications: $rule);
	}//end service()

	/**
	 * A draft and a scheduled publication are listed; a public and a withdrawn one are not.
	 *
	 * @return void
	 */
	public function testDraftAndScheduledAreListedButPublicAndWithdrawnAreNot(): void {
		$result = $this->service(publications: $this->scenario())->publications(limit: 10);

		$this->assertSame(2, $result['total']);
		$this->assertSame(['draft', 'scheduled'], array_column($result['items'], 'publicationId'));
		$this->assertSame(['woo', 'woo'], array_column($result['items'], 'catalogSlug'));
		$this->assertSame('Draft', $result['items'][0]['title']);
		$this->assertNull($result['scope']);
	}//end testDraftAndScheduledAreListedButPublicAndWithdrawnAreNot()

	/**
	 * Every read goes through OpenRegister with RBAC on, newest first.
	 *
	 * @return void
	 */
	public function testEveryReadHasRbacOnAndTheNewestFirst(): void {
		$this->service(publications: $this->scenario())->publications(limit: 10);

		$this->assertCount(2, $this->searches);
		$this->assertSame([true, true], array_column($this->searches, 'rbac'));
		$this->assertSame('desc', $this->searches[1]['query']['_order']['updated']);
		$this->assertSame(UnpublishedOverviewService::WINDOW, $this->searches[1]['query']['_limit']);
	}//end testEveryReadHasRbacOnAndTheNewestFirst()

	/**
	 * The list is cut to its length while the total counts every match.
	 *
	 * @return void
	 */
	public function testTheListIsCutButTheTotalCountsAll(): void {
		$result = $this->service(publications: $this->scenario())->publications(limit: 1);

		$this->assertCount(1, $result['items']);
		$this->assertSame(2, $result['total']);
	}//end testTheListIsCutButTheTotalCountsAll()

	/**
	 * A read cut by the window says so.
	 *
	 * @return void
	 */
	public function testAWindowCutReadCarriesTheScope(): void {
		$result = $this->service(publications: $this->scenario(), total: 500)->publications(limit: 10);

		$this->assertSame(UnpublishedOverviewService::SCOPE_RECENT, $result['scope']);
	}//end testAWindowCutReadCarriesTheScope()

	/**
	 * A publication outside every catalogue scope has no slug.
	 *
	 * @return void
	 */
	public function testAPublicationOutsideEveryCatalogueHasNoSlug(): void {
		$rows = [$this->publication('loose', 'Loose', null, null, '2026-11-10T10:00:00+00:00', 99)];

		$result = $this->service(publications: $rows)->publications(limit: 10);

		$this->assertNull($result['items'][0]['catalogSlug']);
	}//end testAPublicationOutsideEveryCatalogueHasNoSlug()

	/**
	 * Without a configured catalogue scope nothing is searched and nothing listed.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredScopeListsNothing(): void {
		$result = $this->service(publications: $this->scenario(), catalogRegister: '')->publications(limit: 10);

		$this->assertSame(['items' => [], 'total' => 0, 'scope' => null], $result);
		$this->assertSame([], $this->searches);
	}//end testAnUnconfiguredScopeListsNothing()

	/**
	 * A file with no published time and one published in the future are listed; a published one is not.
	 *
	 * @return void
	 */
	public function testUnpublishedAndFutureFilesAreListedButPublishedOnesAreNot(): void {
		$files = [
			11 => ['fileid' => 11, 'name' => 'besluit.pdf', 'mtime' => 1700000300, 'published' => null],
			12 => ['fileid' => 12, 'name' => 'bijlage.pdf', 'mtime' => 1700000200, 'published' => '2099-01-01T00:00:00+00:00'],
			13 => ['fileid' => 13, 'name' => 'live.pdf', 'mtime' => 1700000100, 'published' => '2020-01-01T00:00:00+00:00'],
		];

		$result = $this->service(
			publications: $this->scenario(),
			fileIds: ['public' => [11, 13], 'draft' => [12]],
			files: $files
		)->documents(limit: 10);

		$this->assertSame(2, $result['total']);
		$this->assertSame(['besluit.pdf', 'bijlage.pdf'], array_column($result['items'], 'title'));
		$this->assertSame(['Public', 'Draft'], array_column($result['items'], 'publicationTitle'));
		$this->assertSame(['public', 'draft'], array_column($result['items'], 'publicationId'));
		$this->assertSame('woo', $result['items'][0]['catalogSlug']);
		$this->assertSame(11, $result['items'][0]['fileId']);
	}//end testUnpublishedAndFutureFilesAreListedButPublishedOnesAreNot()

	/**
	 * Without readable publications the file mapper is never asked.
	 *
	 * @return void
	 */
	public function testNoReadablePublicationsMeansNoDocuments(): void {
		$result = $this->service(publications: [])->documents(limit: 10);

		$this->assertSame(0, $result['total']);
		$this->assertSame([], $result['items']);
	}//end testNoReadablePublicationsMeansNoDocuments()

	/**
	 * The requested length is clamped to 1..50 with 10 as the default.
	 *
	 * @return void
	 */
	public function testTheLimitIsClamped(): void {
		$this->assertSame(10, UnpublishedOverviewService::clampLimit(limit: null));
		$this->assertSame(10, UnpublishedOverviewService::clampLimit(limit: 0));
		$this->assertSame(5, UnpublishedOverviewService::clampLimit(limit: 5));
		$this->assertSame(50, UnpublishedOverviewService::clampLimit(limit: 500));
	}//end testTheLimitIsClamped()
}//end class
