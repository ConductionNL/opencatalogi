<?php

/**
 * REQ-CVS-004: opening a batch by case reference gathers the documents
 * delivered for that case, the files on its Woo request record.
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
 *
 * @spec openspec/changes/capability-rows-verify-scenarios/specs/woo-transparency/spec.md#requirement-opening-a-batch-gathers-the-documents-of-the-woo-request-req-cvs-004
 */

declare(strict_types=1);

namespace Unit\Service\Woo;

use DomainException;
use OCA\OpenCatalogi\Controller\WooController;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\Woo\CaseDocuments;
use OCA\OpenCatalogi\Service\Woo\WooRequestStore;
use OCA\OpenCatalogi\Service\WooService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * An ObjectService holding Woo requests, searched by reference.
 */
class CaseRequestObjects {

	/**
	 * The stored requests.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $requests = [];

	/**
	 * The last search query.
	 *
	 * @var array<string, mixed>
	 */
	public array $lastQuery = [];

	/**
	 * Search, honouring only the reference filter.
	 *
	 * @param array<string, mixed> $query The query.
	 * @param mixed ...$rest The other arguments.
	 *
	 * @return array{results: array<int, array<string, mixed>>}
	 */
	public function searchObjectsPaginated(array $query, mixed ...$rest): array {
		$this->lastQuery = $query;
		$found = array_values(
			array_filter($this->requests, static fn (array $request): bool => ($request['reference'] ?? null) === ($query['reference'] ?? null))
		);

		return ['results' => $found];
	}//end searchObjectsPaginated()
}//end class

/**
 * A FileService answering the files of one record.
 */
class CaseRequestFiles {

	/**
	 * The files per record id.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public array $files = [];

	/**
	 * The record ids asked for.
	 *
	 * @var array<int, string>
	 */
	public array $asked = [];

	/**
	 * The files of a record.
	 *
	 * @param string $object The record id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getFiles(string $object): array {
		$this->asked[] = $object;
		return ($this->files[$object] ?? []);
	}//end getFiles()

	/**
	 * The files, formatted.
	 *
	 * @param array<int, array<string, mixed>> $files The files.
	 *
	 * @return array{results: array<int, array<string, mixed>>}
	 */
	public function formatFiles(array $files): array {
		return ['results' => $files];
	}//end formatFiles()
}//end class

/**
 * Tests for CaseDocuments and its use by WooController::createBatch().
 */
class CaseDocumentsTest extends TestCase {

	/**
	 * The fake ObjectService.
	 *
	 * @var CaseRequestObjects
	 */
	private CaseRequestObjects $objects;

	/**
	 * The fake FileService.
	 *
	 * @var CaseRequestFiles
	 */
	private CaseRequestFiles $files;

	/**
	 * Fresh fakes, with WOO-2026-031 carrying two delivered documents.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new CaseRequestObjects();
		$this->objects->requests = [
			['id' => 'req-30', 'reference' => 'WOO-2026-030'],
			['id' => 'req-31', 'reference' => 'WOO-2026-031'],
		];
		$this->files = new CaseRequestFiles();
		$this->files->files = [
			'req-31' => [
				['id' => 501, 'title' => 'besluit.pdf', 'mimetype' => 'application/pdf'],
				['id' => 502, 'name' => 'mail.eml', 'mimeType' => 'message/rfc822'],
			],
		];

	}//end setUp()

	/**
	 * The case documents service over the fakes.
	 *
	 * @return CaseDocuments
	 */
	private function caseDocuments(): CaseDocuments {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => (str_ends_with($id, 'FileService') === true) ? $this->files : $this->objects
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$apps->method('getInstalledApps')->willReturn(['openregister']);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('7');
		$lookup = new OpenRegisterLookup($container, $apps);

		return new CaseDocuments(new WooRequestStore($config, $lookup), $lookup);

	}//end caseDocuments()

	/**
	 * The documents of the case are the files on its Woo request, one each.
	 *
	 * @return void
	 */
	public function testTheCasesDeliveredFilesBecomeItsDocuments(): void {
		$documents = $this->caseDocuments()->forCase(caseReference: 'WOO-2026-031');

		$this->assertSame(
			[
				['documentReference' => '501', 'fileName' => 'besluit.pdf', 'fileType' => 'application/pdf'],
				['documentReference' => '502', 'fileName' => 'mail.eml', 'fileType' => 'message/rfc822'],
			],
			$documents
		);
		$this->assertSame(['req-31'], $this->files->asked, 'only the files of the named case are read');
		$this->assertSame('WOO-2026-031', $this->objects->lastQuery['reference']);

	}//end testTheCasesDeliveredFilesBecomeItsDocuments()

	/**
	 * A case without delivered documents opens with none rather than failing.
	 *
	 * @return void
	 */
	public function testACaseWithoutFilesHasNoDocuments(): void {
		$this->assertSame([], $this->caseDocuments()->forCase(caseReference: 'WOO-2026-030'));

	}//end testACaseWithoutFilesHasNoDocuments()

	/**
	 * An unknown reference is refused, naming it.
	 *
	 * @return void
	 */
	public function testAnUnknownReferenceIsRefusedNamingIt(): void {
		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('No Woo request has the case reference WOO-1999-001.');

		$this->caseDocuments()->forCase(caseReference: 'WOO-1999-001');

	}//end testAnUnknownReferenceIsRefusedNamingIt()

	/**
	 * The controller over a post, with case documents wired in.
	 *
	 * @param array<string, mixed> $post The posted values.
	 * @param WooService $service The Woo service.
	 *
	 * @return WooController
	 */
	private function controller(array $post, WooService $service): WooController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($post[$key] ?? $default)
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$categories = $this->createMock(\OCA\OpenCatalogi\Contract\InformationCategoryRegistryInterface::class);

		return new WooController(
			'opencatalogi',
			$request,
			$service,
			$l10n,
			$this->createMock(IUserSession::class),
			$categories,
			$this->caseDocuments()
		);

	}//end controller()

	/**
	 * Add with only a case reference opens the batch with that case's documents.
	 *
	 * @return void
	 */
	public function testAddWithOnlyAReferenceOpensTheBatchWithTheCasesDocuments(): void {
		$service = $this->createMock(WooService::class);
		$service->expects($this->once())->method('createBatch')
			->with(
				'WOO-2026-031',
				[
					['documentReference' => '501', 'fileName' => 'besluit.pdf', 'fileType' => 'application/pdf'],
					['documentReference' => '502', 'fileName' => 'mail.eml', 'fileType' => 'message/rfc822'],
				],
				null
			)
			->willReturn(['id' => 'b-1']);

		$response = $this->controller(post: ['caseReference' => 'WOO-2026-031'], service: $service)->createBatch();

		$this->assertSame(201, $response->getStatus());

	}//end testAddWithOnlyAReferenceOpensTheBatchWithTheCasesDocuments()

	/**
	 * Documents the caller lists are kept; the case is not read.
	 *
	 * @return void
	 */
	public function testListedDocumentsAreKept(): void {
		$service = $this->createMock(WooService::class);
		$service->expects($this->once())->method('createBatch')->with('WOO-2026-031', ['d-1'], null)->willReturn(['id' => 'b-1']);

		$this->controller(post: ['caseReference' => 'WOO-2026-031', 'documents' => ['d-1']], service: $service)->createBatch();

		$this->assertSame([], $this->files->asked);

	}//end testListedDocumentsAreKept()

	/**
	 * An unknown reference opens no batch and answers 409 naming it.
	 *
	 * @return void
	 */
	public function testAnUnknownReferenceOpensNoBatch(): void {
		$service = $this->createMock(WooService::class);
		$service->expects($this->never())->method('createBatch');

		$response = $this->controller(post: ['caseReference' => 'WOO-1999-001'], service: $service)->createBatch();

		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['error' => 'No Woo request has the case reference WOO-1999-001.'], $response->getData());

	}//end testAnUnknownReferenceOpensNoBatch()
}//end class
