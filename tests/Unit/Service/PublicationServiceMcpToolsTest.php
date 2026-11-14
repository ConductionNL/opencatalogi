<?php

/**
 * Unit tests for the curated MCP tools on PublicationService.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\DownloadService;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\PublicationService;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;

/**
 * searchCatalogPublications() and listPublicationFiles() (opencatalogi-mcp-adoption).
 *
 * @covers \OCA\OpenCatalogi\Service\PublicationService
 */
class PublicationServiceMcpToolsTest extends TestCase {
	private IAppConfig&MockObject $config;
	private IRequest&MockObject $request;
	private ContainerInterface&MockObject $container;
	private IAppManager&MockObject $appManager;

	/**
	 * Mock the collaborators of the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = $this->createMock(IAppConfig::class);
		$this->request = $this->createMock(IRequest::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
	}//end setUp()

	/**
	 * The constructor arguments of the service.
	 *
	 * @return array<int, mixed>
	 */
	private function arguments(): array {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		return [
			$this->config,
			$this->request,
			$this->container,
			$this->appManager,
			$this->createMock(DirectoryService::class),
			$this->createMock(DownloadService::class),
			$session,
		];
	}//end arguments()

	/**
	 * The real service.
	 *
	 * @return PublicationService
	 */
	private function service(): PublicationService {
		return new PublicationService(...$this->arguments());
	}//end service()

	/**
	 * The service with attachments() replaced, to see what listPublicationFiles() hands it.
	 *
	 * @return PublicationService&MockObject
	 */
	private function serviceWithAttachments(): PublicationService&MockObject {
		return $this->getMockBuilder(PublicationService::class)
			->setConstructorArgs($this->arguments())
			->onlyMethods(['attachments'])
			->getMock();
	}//end serviceWithAttachments()

	/**
	 * A catalog object as OpenRegister returns it.
	 *
	 * @param array<string, mixed> $data The serialised catalog.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity
	 */
	private function catalog(array $data): \OCA\OpenRegister\Db\ObjectEntity {
		$catalog = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize'])
			->getMock();
		$catalog->method('jsonSerialize')->willReturn($data);
		$catalog->setRegister('register-1');
		$catalog->setSchema('schema-1');
		return $catalog;
	}//end catalog()

	/**
	 * A search without a catalog, a blank query or a limit out of range is
	 * refused before anything is asked of OpenRegister, and the refusal of a
	 * missing catalog points at the derived publication search.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#scenario-the-tool-cannot-be-called-without-a-catalog
	 */
	public function testACatalogSearchWithoutACatalogIsRefused(): void {
		$this->container->expects($this->never())->method('get');
		$service = $this->service();

		$noCatalog = $service->searchCatalogPublications(catalog: '  ', query: 'besluit');
		$this->assertSame('invalid_arguments', $noCatalog['error']['code']);
		$this->assertStringContainsString('opencatalogi.publication.search', $noCatalog['error']['message']);

		$this->assertSame(
			['error' => ['code' => 'invalid_arguments', 'message' => 'Required argument "query" is missing or empty.']],
			$service->searchCatalogPublications(catalog: 'woo', query: ' ')
		);
		$this->assertSame(
			'Invalid limit 0. Must be between 1 and 50.',
			$service->searchCatalogPublications(catalog: 'woo', query: 'besluit', limit: 0)['error']['message']
		);
		$this->assertSame(
			'Invalid limit 51. Must be between 1 and 50.',
			$service->searchCatalogPublications(catalog: 'woo', query: 'besluit', limit: 51)['error']['message']
		);
	}//end testACatalogSearchWithoutACatalogIsRefused()

	/**
	 * A catalog search runs the catalog's own search with the query and limit,
	 * and answers the rows and the total.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#scenario-an-agent-searches-within-one-catalog
	 */
	public function testACatalogSearchRunsTheCatalogSearch(): void {
		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$queryService = $this->createMock(PublicationQueryService::class);
		$queryService->method('applySchemaScopeReadRuleGuard')->willReturnArgument(0);
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
		$this->container->method('get')->willReturnCallback(
			static fn (string $id) => match ($id) {
				'OCA\\OpenRegister\\Service\\ObjectService' => $objectService,
				PublicationQueryService::class => $queryService,
				default => null,
			}
		);
		$this->config->method('getValueString')->willReturnMap([
			['opencatalogi', 'catalog_schema', '', 'schema-1'],
			['opencatalogi', 'catalog_register', '', 'register-1'],
		]);
		$objectService->method('searchObjects')->willReturn([]);
		$objectService->method('find')->willReturn($this->catalog(['registers' => ['reg-1', 'reg-2'], 'schemas' => ['sch-1']]));
		$objectService->expects($this->once())
			->method('searchObjectsPaginated')
			->with(
				$this->callback(
					static fn (array $query): bool => ($query['_search'] ?? null) === 'besluit'
						&& ($query['_limit'] ?? null) === 2
						&& ($query['_page'] ?? null) === 1
				)
			)
			->willReturn(['results' => [['id' => 'a'], ['id' => 'b']], 'total' => 7]);
		$this->request->method('getParams')->willReturn([]);

		$answer = $this->service()->searchCatalogPublications(catalog: ' catalog-99 ', query: ' besluit ', limit: 2);

		$this->assertSame(7, $answer['total']);
		$this->assertCount(2, $answer['results']);
		$this->assertArrayNotHasKey('error', $answer);
	}//end testACatalogSearchRunsTheCatalogSearch()

	/**
	 * The files of a publication come from attachments(), which runs the
	 * catalog-scope gate and the read check first.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#scenario-file-attachments-are-returned-for-a-publication-the-caller-may-read
	 */
	public function testTheFilesOfAPublicationComeFromAttachments(): void {
		$files = ['results' => [['name' => 'besluit.pdf'], ['name' => 'bijlage.pdf']], 'total' => 2];
		$service = $this->serviceWithAttachments();
		$service->expects($this->once())->method('attachments')->with('pub-1')->willReturn(new JSONResponse($files));

		$this->assertSame($files, $service->listPublicationFiles(id: ' pub-1 '));
	}//end testTheFilesOfAPublicationComeFromAttachments()

	/**
	 * An out-of-scope or unknown publication yields an error and no file list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#scenario-an-out-of-scope-publication-yields-no-files
	 */
	public function testAnOutOfScopePublicationYieldsNoFiles(): void {
		$service = $this->serviceWithAttachments();
		$service->method('attachments')->willReturnOnConsecutiveCalls(
			new JSONResponse(['error' => 'Not Found'], 404),
			new JSONResponse(['error' => 'disk on fire'], 500),
			new JSONResponse('odd', 500)
		);

		$this->assertSame(['error' => ['code' => 'not_found', 'message' => 'Not Found']], $service->listPublicationFiles(id: 'elsewhere'));
		$this->assertSame(['error' => ['code' => 'error', 'message' => 'disk on fire']], $service->listPublicationFiles(id: 'pub-1'));
		$this->assertSame('The files of this publication could not be listed.', $service->listPublicationFiles(id: 'pub-1')['error']['message']);
	}//end testAnOutOfScopePublicationYieldsNoFiles()

	/**
	 * A blank id is refused without asking for attachments; a non-array answer lists nothing.
	 *
	 * @return void
	 */
	public function testABlankIdIsRefusedAndAnOddAnswerListsNothing(): void {
		$service = $this->serviceWithAttachments();
		$service->expects($this->once())->method('attachments')->willReturn(new JSONResponse('odd'));

		$this->assertSame('invalid_arguments', $service->listPublicationFiles(id: ' ')['error']['code']);
		$this->assertSame(['results' => []], $service->listPublicationFiles(id: 'pub-1'));
	}//end testABlankIdIsRefusedAndAnOddAnswerListsNothing()

	/**
	 * Both tools declare themselves read-only with scope read, and the files
	 * tool tells itself apart from the publication get.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#scenario-the-curated-service-tools-are-discovered
	 */
	public function testBothToolsDeclareAReadOnlyAttribute(): void {
		$declared = [];
		foreach (['searchCatalogPublications', 'listPublicationFiles'] as $method) {
			$attributes = (new ReflectionMethod(PublicationService::class, $method))->getAttributes(McpTool::class);
			$this->assertCount(1, $attributes, $method . ' carries exactly one #[McpTool]');
			$declared[$method] = $attributes[0]->getArguments();
		}

		foreach ($declared as $method => $arguments) {
			$this->assertSame($method, $arguments['name']);
			$this->assertSame('read', $arguments['scope']);
			$this->assertTrue($arguments['readOnlyHint']);
			$this->assertFalse($arguments['destructiveHint']);
			$this->assertTrue($arguments['idempotentHint']);
			$this->assertSame('user', $arguments['reach']);
		}

		$this->assertStringContainsString('opencatalogi.publication.search', $declared['searchCatalogPublications']['description']);
		$this->assertStringContainsString('opencatalogi.publication.get', $declared['listPublicationFiles']['description']);
	}//end testBothToolsDeclareAReadOnlyAttribute()
}//end class
