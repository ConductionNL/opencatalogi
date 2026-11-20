<?php

/**
 * The two curated agent tools on PublicationService (opencatalogi-mcp-adoption).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Mcp\OpenCatalogiScannableServices;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\DownloadService;
use OCA\OpenCatalogi\Service\Publication\AgentToolAnswer;
use OCA\OpenCatalogi\Service\PublicationService;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * searchCatalogPublications() and listPublicationFiles() delegate to index() and
 * attachments() and answer plain arrays with structured errors.
 */
#[CoversClass(PublicationService::class)]
#[CoversClass(AgentToolAnswer::class)]
#[CoversClass(OpenCatalogiScannableServices::class)]
class PublicationAgentToolsTest extends TestCase {

	/**
	 * The service with index() and attachments() replaced.
	 *
	 * @var PublicationService&MockObject
	 */
	private PublicationService&MockObject $service;

	protected function setUp(): void {
		$this->service = $this->getMockBuilder(PublicationService::class)
			->setConstructorArgs([
				$this->createMock(IAppConfig::class),
				$this->createMock(IRequest::class),
				$this->createMock(ContainerInterface::class),
				$this->createMock(IAppManager::class),
				$this->createMock(DirectoryService::class),
				$this->createMock(DownloadService::class),
			])
			->onlyMethods(['index', 'attachments'])
			->getMock();
	}

	/**
	 * The search runs index() for the catalog with the trimmed query, the limit
	 * and the first page, and answers its rows and total.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-catalog-scoped-search-survives-as-a-curated-service-tool-req-004
	 */
	public function testTheSearchRunsInsideTheNamedCatalog(): void {
		$this->service->expects($this->once())->method('index')
			->with('woo', ['_search' => 'afval', '_limit' => 2, '_page' => 1])
			->willReturn(new JSONResponse(['results' => [['id' => 'p1'], ['id' => 'p2'], ['id' => 'p3']], 'total' => 7]));

		$answer = $this->service->searchCatalogPublications(catalog: ' woo ', query: ' afval ', limit: 2);

		$this->assertSame([
			'success' => true,
			'catalog' => 'woo',
			'query' => 'afval',
			'publications' => [['id' => 'p1'], ['id' => 'p2']],
			'total' => 7,
		], $answer);
	}

	/**
	 * Without a catalog the search is refused before index() runs, and the agent
	 * is pointed at the unscoped publication search.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-catalog-scoped-search-survives-as-a-curated-service-tool-req-004
	 */
	public function testASearchWithoutACatalogIsRefused(): void {
		$this->service->expects($this->never())->method('index');

		$answer = $this->service->searchCatalogPublications(catalog: '  ', query: 'afval');

		$this->assertSame('invalid_arguments', $answer['error']['code']);
		$this->assertStringContainsString('opencatalogi.publication.search', $answer['error']['message']);
	}

	/**
	 * A blank query and a limit outside 1 to 50 are refused before index() runs.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-catalog-scoped-search-survives-as-a-curated-service-tool-req-004
	 */
	public function testABlankQueryOrAnOutOfRangeLimitIsRefused(): void {
		$this->service->expects($this->never())->method('index');

		$this->assertSame(
			['error' => ['code' => 'invalid_arguments', 'message' => 'Required argument "query" is missing or empty.']],
			$this->service->searchCatalogPublications(catalog: 'woo', query: '')
		);
		$this->assertSame(
			['error' => ['code' => 'invalid_arguments', 'message' => 'Invalid limit 51. Must be between 1 and 50.']],
			$this->service->searchCatalogPublications(catalog: 'woo', query: 'x', limit: 51)
		);
		$this->assertSame('invalid_arguments', $this->service->searchCatalogPublications(catalog: 'woo', query: 'x', limit: 0)['error']['code']);
	}

	/**
	 * A refused search parameter is an argument error; a failing search answers a
	 * fixed message and never the exception's text.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-catalog-scoped-search-survives-as-a-curated-service-tool-req-004
	 */
	public function testSearchFailuresAnswerStructuredErrors(): void {
		$this->service->method('index')->willReturnOnConsecutiveCalls(
			new JSONResponse(['error' => 'Invalid _order'], 400),
			$this->throwException(new RuntimeException('secret detail'))
		);

		$this->assertSame(
			['error' => ['code' => 'invalid_arguments', 'message' => 'The search parameters were refused.']],
			$this->service->searchCatalogPublications(catalog: 'woo', query: 'x')
		);
		$this->assertSame(
			['error' => ['code' => 'internal_error', 'message' => 'Failed to search publications.']],
			$this->service->searchCatalogPublications(catalog: 'woo', query: 'x')
		);
	}

	/**
	 * The file list comes from attachments(), which runs the catalog-scope gate
	 * and the RBAC read check; its files and total are answered.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-the-publication-file-attachment-list-survives-as-a-curated-service-tool-req-005
	 */
	public function testTheFilesOfAReadablePublicationAreListed(): void {
		$files = [['id' => 1, 'title' => 'besluit.pdf'], ['id' => 2, 'title' => 'bijlage.pdf']];
		$this->service->expects($this->once())->method('attachments')
			->with('pub-1')
			->willReturn(new JSONResponse(['results' => $files, 'total' => 2]));

		$this->assertSame(
			['success' => true, 'publication' => 'pub-1', 'files' => $files, 'total' => 2],
			$this->service->listPublicationFiles(id: ' pub-1 ')
		);
	}

	/**
	 * A publication outside the catalog scope (attachments() answers 404) yields
	 * no file list; a failing lookup answers a fixed message.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-the-publication-file-attachment-list-survives-as-a-curated-service-tool-req-005
	 */
	public function testAnOutOfScopePublicationYieldsNoFiles(): void {
		$this->service->method('attachments')->willReturnOnConsecutiveCalls(
			new JSONResponse(['error' => 'Not Found'], 404),
			new JSONResponse(['error' => '/var/data/secret'], 500),
			$this->throwException(new RuntimeException('secret detail'))
		);

		$this->assertSame(['error' => ['code' => 'not_found', 'message' => 'Publication not found.']], $this->service->listPublicationFiles(id: 'elsewhere'));
		$failed = ['error' => ['code' => 'internal_error', 'message' => 'Failed to list the files of the publication.']];
		$this->assertSame($failed, $this->service->listPublicationFiles(id: 'pub-1'));
		$this->assertSame($failed, $this->service->listPublicationFiles(id: 'pub-1'));
	}

	/**
	 * A blank id is refused before attachments() runs.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-the-publication-file-attachment-list-survives-as-a-curated-service-tool-req-005
	 */
	public function testABlankIdIsRefused(): void {
		$this->service->expects($this->never())->method('attachments');

		$this->assertSame('invalid_arguments', $this->service->listPublicationFiles(id: ' ')['error']['code']);
	}

	/**
	 * Both tools declare themselves read-only, scope read, reach user, and the
	 * file tool tells itself apart from document objects.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-no-hand-written-mcp-tool-provider-remains-in-opencatalogi-req-006
	 */
	public function testBothToolsDeclareAReadOnlyPosture(): void {
		foreach (['searchCatalogPublications', 'listPublicationFiles'] as $method) {
			$attributes = (new ReflectionMethod(PublicationService::class, $method))->getAttributes(McpTool::class);
			$this->assertCount(1, $attributes, $method);
			$tool = $attributes[0]->newInstance();
			$this->assertSame($method, $tool->name);
			$this->assertTrue($tool->readOnlyHint, $method);
			$this->assertFalse($tool->destructiveHint, $method);
			$this->assertTrue($tool->idempotentHint, $method);
			$this->assertSame('read', $tool->scope, $method);
			$this->assertSame('user', $tool->reach, $method);
		}

		$files = (new ReflectionMethod(PublicationService::class, 'listPublicationFiles'))->getAttributes(McpTool::class)[0]->newInstance();
		$this->assertStringContainsString('not structured document objects', $files->description);
		$this->assertTrue((new ReflectionMethod(PublicationService::class, 'searchCatalogPublications'))->getParameters()[0]->isOptional() === false);
	}

	/**
	 * OpenRegister scans exactly PublicationService for OpenCatalogi's tools.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-no-hand-written-mcp-tool-provider-remains-in-opencatalogi-req-006
	 */
	public function testTheScannableServicesNamePublicationService(): void {
		$this->assertSame([PublicationService::class], (new OpenCatalogiScannableServices())->getScannableServiceClasses());
	}
}
