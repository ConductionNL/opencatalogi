<?php

/**
 * A withdrawn publication answers with a tombstone about the withdrawal (REQ-PWA-002).
 *
 * Driven through `PublicationsController::show()`: the anonymous read misses,
 * and the real {@see PublicationTombstone} reads, as the system, the stored
 * publication and its depublications from an OpenRegister double.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Controller;

use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Controller\PublicationsController;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Controller\PublicationTombstone;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\PublicationService;
use OCA\OpenCatalogi\Service\UsageCounterService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenCatalogi\Controller\PublicationsController
 * @covers \OCA\OpenCatalogi\Controller\PublicationTombstone
 */
final class PublicationTombstoneTest extends TestCase {

	private const UUID = '0b7c1d52-6f1e-4a8e-9c3d-2a1b0c9d8e7f';

	/**
	 * The stored publication, read as the system; null when there is none.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $stored = null;

	/**
	 * The stored depublications.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $depublications = [];

	/**
	 * Ask the controller for the publication anonymously.
	 *
	 * @return JSONResponse The answer.
	 */
	private function show(): JSONResponse {
		$objects = $this->createMock(originalClassName: ObjectService::class);
		// The anonymous read finds nothing.
		$objects->method('searchObjects')->willReturn([]);
		$objects->method('find')->willReturnCallback(
			fn (int|string $id, ?ObjectFindOptions $options = null, bool $_rbac = true): ?ObjectEntity => $this->systemRead(
				id: (string)$id,
				options: $options,
				rbac: $_rbac
			)
		);
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			fn (array $query = []): array => ['results' => $this->depublicationsIn(query: $query)]
		);

		$lookup = $this->createMock(originalClassName: OpenRegisterLookupInterface::class);
		$lookup->method('installedObjectService')->willReturn($objects);
		$lookup->method('registerConfiguration')->willReturn(['register' => 5, 'schema' => 9]);
		$tombstones = new PublicationTombstone(openRegister: $lookup);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): mixed => match ($id) {
				PublicationTombstone::class => $tombstones,
				default => $objects,
			}
		);
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$catalogs = $this->createMock(originalClassName: CatalogiService::class);
		$catalogs->method('getCatalogBySlug')->willReturn(['id' => 'cat-1', 'title' => 'Woo', 'registers' => [5], 'schemas' => [7]]);
		$query = $this->createMock(originalClassName: PublicationQueryService::class);
		$query->method('applyCatalogReadRuleGuard')->willReturnCallback(static fn (array $catalog): array => $catalog);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnArgument(2);
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParams')->willReturn([]);
		$request->method('getHeader')->willReturn('');

		$controller = new PublicationsController(
			appName: 'opencatalogi',
			request: $request,
			publicationService: $this->createMock(originalClassName: PublicationService::class),
			catalogiService: $catalogs,
			queryService: $query,
			container: $container,
			appManager: $appManager,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			l10n: $l10n,
			usageCounterService: $this->createMock(originalClassName: UsageCounterService::class),
			appConfig: $config
		);

		return $controller->show(catalogSlug: 'woo', id: self::UUID);
	}//end show()

	/**
	 * The stored depublications when the search names the depublication schema (9).
	 *
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function depublicationsIn(array $query): array {
		if (($query['@self']['schema'] ?? null) !== 9) {
			return [];
		}

		return $this->depublications;
	}//end depublicationsIn()

	/**
	 * The stored publication when the system reads it in the catalogue's pair (register 5, schema 7).
	 *
	 * @param string $id The id asked for.
	 * @param ObjectFindOptions|null $options The register and schema.
	 * @param bool $rbac Whether the read is under RBAC.
	 *
	 * @return ObjectEntity|null The publication.
	 */
	private function systemRead(string $id, ?ObjectFindOptions $options, bool $rbac): ?ObjectEntity {
		if ($this->stored === null || $rbac === true || $id !== self::UUID || $options?->register !== 5 || $options?->schema !== 7) {
			return null;
		}

		$entity = new ObjectEntity();
		$entity->setUuid(self::UUID);
		$entity->setObject($this->stored);
		return $entity;
	}//end systemRead()

	/**
	 * A withdrawn publication answers 410 with its id, the moment and the public reason, and nothing else.
	 *
	 * @return void
	 */
	public function testAWithdrawnPublicationAnswers410WithItsTombstone(): void {
		$this->stored = ['title' => 'Besluit parkeren 2026', 'summary' => 'Over parkeren', 'status' => 'published'];
		$this->depublications = [
			[
				'publication' => self::UUID,
				'reason' => 'Intern: AVG-melding 12',
				'depublishedAt' => '2026-11-22T09:00:00+00:00',
				'publicReason' => 'Gepubliceerd in strijd met de AVG',
			],
		];

		$response = $this->show();

		$this->assertSame(expected: 410, actual: $response->getStatus());
		$this->assertSame(
			expected: ['id' => self::UUID, 'withdrawnAt' => '2026-11-22T09:00:00+00:00', 'publicReason' => 'Gepubliceerd in strijd met de AVG'],
			actual: $response->getData()
		);
	}//end testAWithdrawnPublicationAnswers410WithItsTombstone()

	/**
	 * A draft that was never public, and an id nothing holds, stay a 404.
	 *
	 * @return void
	 */
	public function testADraftThatWasNeverPublicStays404(): void {
		$this->assertSame(expected: 404, actual: $this->show()->getStatus());

		$this->stored = ['title' => 'Concept', 'status' => 'draft'];
		$this->assertSame(expected: 404, actual: $this->show()->getStatus());
	}//end testADraftThatWasNeverPublicStays404()

	/**
	 * The title is in the tombstone only when the depublication chose to show it.
	 *
	 * @return void
	 */
	public function testTheTombstoneCarriesNoTitleUnlessChosen(): void {
		$this->stored = ['title' => 'Besluit parkeren 2026', 'status' => 'published'];
		$this->depublications = [
			['publication' => self::UUID, 'reason' => 'intern', 'depublishedAt' => '2026-11-20T09:00:00+00:00', 'tombstoneShowsTitle' => false],
		];
		$this->assertArrayNotHasKey(key: 'title', array: $this->show()->getData());

		$this->depublications[] = [
			'publication' => self::UUID,
			'reason' => 'intern',
			'depublishedAt' => '2026-11-21T09:00:00+00:00',
			'tombstoneShowsTitle' => true,
		];
		$data = $this->show()->getData();
		$this->assertSame(expected: 'Besluit parkeren 2026', actual: $data['title']);
		$this->assertSame(expected: '2026-11-21T09:00:00+00:00', actual: $data['withdrawnAt']);
	}//end testTheTombstoneCarriesNoTitleUnlessChosen()

	/**
	 * A withdrawn file, or a record that put something back, is no withdrawal of the publication.
	 *
	 * @return void
	 */
	public function testAFileWithdrawalOrAPutBackIsNoTombstone(): void {
		$this->stored = ['title' => 'Besluit', 'status' => 'published'];
		$this->depublications = [
			['publication' => self::UUID, 'reason' => 'bijlage', 'depublishedAt' => '2026-11-20T09:00:00+00:00', 'file' => '412'],
			['publication' => self::UUID, 'reason' => 'terug', 'depublishedAt' => '2026-11-21T09:00:00+00:00', 'republished' => true],
		];

		$this->assertSame(expected: 404, actual: $this->show()->getStatus());
	}//end testAFileWithdrawalOrAPutBackIsNoTombstone()

	/**
	 * An archived publication that was once released answers 410 without a reason.
	 *
	 * @return void
	 */
	public function testAnArchivedOnceReleasedPublicationAnswers410(): void {
		$this->stored = [
			'title' => 'Oud besluit',
			'status' => 'archived',
			'firstReleasedAt' => '2025-03-01T10:00:00+00:00',
			'depublicationDate' => '2026-01-01T00:00:00+00:00',
		];

		$response = $this->show();

		$this->assertSame(expected: 410, actual: $response->getStatus());
		$this->assertSame(
			expected: ['id' => self::UUID, 'withdrawnAt' => '2026-01-01T00:00:00+00:00', 'publicReason' => null],
			actual: $response->getData()
		);
	}//end testAnArchivedOnceReleasedPublicationAnswers410()
}//end class
