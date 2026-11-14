<?php

/**
 * Pins the answer shapes of the agent tools at their edges.
 *
 * Covers what the recorded golden cases leave out: an attachments answer
 * that holds no list, the attachment cap, a search row whose `@self` is not
 * an object and carries no identifier, and the search request and
 * truncation envelope over more rows than the cap.
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
 * @covers \OCA\OpenCatalogi\Mcp\OpenCatalogiToolProvider
 */

declare(strict_types=1);

namespace Unit\Mcp;

use OCA\OpenCatalogi\Contract\PublicationServiceInterface;
use OCA\OpenCatalogi\Mcp\OpenCatalogiToolProvider;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Edge answer shapes of OpenCatalogiToolProvider.
 */
class ToolProviderAnswerShapesTest extends TestCase {

	/**
	 * The publication service double.
	 *
	 * @var PublicationServiceInterface&MockObject
	 */
	private PublicationServiceInterface&MockObject $publications;

	/**
	 * The provider under test.
	 *
	 * @var OpenCatalogiToolProvider
	 */
	private OpenCatalogiToolProvider $provider;

	/**
	 * Build the provider over a publication service double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->publications = $this->createMock(PublicationServiceInterface::class);
		$this->provider = new OpenCatalogiToolProvider(
			publicationService: $this->publications,
			userSession: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * An attachments answer that holds no list gives an empty attachment list, not a failure.
	 *
	 * @return void
	 */
	public function testAttachmentsThatAreNoListBecomeEmpty(): void {
		$this->publications->expects($this->once())->method('show')->with('p-1')
			->willReturn(new JSONResponse(['uuid' => 'p-1', 'title' => 'Besluit']));
		$this->publications->expects($this->once())->method('attachments')->with('p-1')
			->willReturn(new JSONResponse('not a list'));

		$result = $this->provider->invokeTool(toolId: 'opencatalogi.getPublication', arguments: ['id' => 'p-1']);

		$this->assertSame(
			[
				'success' => true,
				'publication' => ['uuid' => 'p-1', 'title' => 'Besluit'],
				'attachments' => [],
				'sources' => [
					[
						'type' => 'opencatalogi.publication',
						'uuid' => 'p-1',
						'url' => '/apps/opencatalogi/publications/p-1',
						'label' => 'Besluit',
					],
				],
			],
			$result
		);

	}//end testAttachmentsThatAreNoListBecomeEmpty()

	/**
	 * Attachments under `results` are read from there and capped at twenty, in order.
	 *
	 * @return void
	 */
	public function testAttachmentsUnderResultsAreCappedAtTwenty(): void {
		$files = [];
		for ($i = 1; $i <= 25; $i++) {
			$files['f' . $i] = ['name' => 'file-' . $i . '.pdf'];
		}

		$this->publications->method('show')->willReturn(new JSONResponse(['uuid' => 'p-1']));
		$this->publications->method('attachments')->willReturn(new JSONResponse(['results' => $files, 'files' => [['name' => 'ignored.pdf']]]));

		$attachments = $this->provider->invokeTool(toolId: 'opencatalogi.getPublication', arguments: ['id' => 'p-1'])['attachments'];

		$this->assertCount(20, $attachments);
		$this->assertSame(['name' => 'file-1.pdf'], $attachments[0]);
		$this->assertSame(['name' => 'file-20.pdf'], $attachments[19]);
		$this->assertSame(range(0, 19), array_keys($attachments));

	}//end testAttachmentsUnderResultsAreCappedAtTwenty()

	/**
	 * A search row whose `@self` is no object and that carries no identifier links to an empty uuid;
	 * a row identified only under `@self` takes that identifier.
	 *
	 * @return void
	 */
	public function testSearchRowIdentifiersFallBackThroughSelf(): void {
		$this->publications->method('index')->willReturn(
			new JSONResponse(
				[
					'results' => [
						['title' => 'Broken self', '@self' => 'not an object'],
						['name' => 'Slugged', '@self' => ['slug' => 'slug-1']],
					],
				]
			)
		);

		$sources = $this->provider->invokeTool(toolId: 'opencatalogi.searchCatalog', arguments: ['query' => 'x'])['sources'];

		$this->assertSame(
			[
				['type' => 'opencatalogi.publication', 'uuid' => '', 'url' => '/apps/opencatalogi/publications/', 'label' => 'Broken self'],
				['type' => 'opencatalogi.publication', 'uuid' => 'slug-1', 'url' => '/apps/opencatalogi/publications/slug-1', 'label' => 'Slugged'],
			],
			$sources
		);

	}//end testSearchRowIdentifiersFallBackThroughSelf()

	/**
	 * A search passes the trimmed query, limit, first page and catalog, and flags results beyond the cap.
	 *
	 * @return void
	 */
	public function testSearchPassesItsRequestAndFlagsTruncation(): void {
		$rows = [];
		for ($i = 1; $i <= 23; $i++) {
			$rows[] = ['uuid' => 'u-' . $i, 'title' => 'T' . $i];
		}

		$this->publications->expects($this->once())->method('index')
			->with('cat-1', ['_search' => 'water', '_limit' => 50, '_page' => 1])
			->willReturn(new JSONResponse(['results' => $rows]));

		$result = $this->provider->invokeTool(
			toolId: 'opencatalogi.searchCatalog',
			arguments: ['query' => '  water ', 'limit' => 50, 'catalog' => ' cat-1 ']
		);

		$this->assertTrue($result['success']);
		$this->assertSame('water', $result['query']);
		$this->assertCount(20, $result['publications']);
		$this->assertCount(20, $result['sources']);
		$this->assertSame('u-20', $result['sources'][19]['uuid']);
		$this->assertTrue($result['resultsTruncated']);
		$this->assertSame(23, $result['resultsTotalCount']);

	}//end testSearchPassesItsRequestAndFlagsTruncation()
}//end class
