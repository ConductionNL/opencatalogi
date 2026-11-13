<?php

/**
 * Unit tests for WooController.
 *
 * Covers the two routed WOO endpoints that had no contract test of any kind
 * (gate-25): the per-batch inventarislijst export and the weigeringsgronden
 * (refusal-ground) vocabulary lookup.
 *
 * WooService is mocked; only the controller's auth posture, format
 * negotiation, parameter marshalling and response shaping are under test here.
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

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\WooController;
use OCA\OpenCatalogi\Service\WooService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WooController.
 */
class WooControllerTest extends TestCase {

	private IRequest|MockObject $request;
	private WooService|MockObject $wooService;
	private IUserSession|MockObject $userSession;
	private WooController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->wooService = $this->createMock(WooService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$categories = new \OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry(
			new \OCA\OpenCatalogi\Service\TooiVocabularyService(),
			$this->createMock(\OCA\OpenCatalogi\Service\SettingsService::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			new \OCA\OpenCatalogi\Service\Woo\LocalCategoryAdmission(new \OCA\OpenCatalogi\Service\TooiVocabularyService()),
		);

		$this->controller = new WooController(
			'opencatalogi',
			$this->request,
			$this->wooService,
			$l10n,
			$this->userSession,
			$categories,
			new \OCA\OpenCatalogi\Service\TooiVocabularyService()
		);

	}//end setUp()

	/**
	 * Report an authenticated user on the session.
	 */
	private function authenticate(string $uid = 'admin'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

	}//end authenticate()

	/**
	 * Make the request return $params, falling back to the caller's default.
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn ($key, $default = null) => ($params[$key] ?? $default)
		);

	}//end withParams()

	/*
	 * ------------------------------------------------------------------
	 * inventarislijst — GET /api/woo/batches/{batchId}/inventarislijst
	 * ------------------------------------------------------------------
	 */

	public function testInventarislijstDefaultsToCsv(): void {
		$this->withParams([]);

		$rows = [['document' => 'doc-1', 'grond' => '5.1.2a']];
		$this->wooService->expects($this->once())
			->method('buildInventarislijst')
			->with('batch-1')
			->willReturn($rows);
		$this->wooService->expects($this->once())
			->method('renderInventarislijstCsv')
			->with($rows)
			->willReturn("document,grond\ndoc-1,5.1.2a\n");
		// No format was requested, so the HTML renderer must not run.
		$this->wooService->expects($this->never())->method('renderInventarislijstHtml');

		$response = $this->controller->inventarislijst('batch-1');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertStringContainsString('doc-1', $response->render());

	}//end testInventarislijstDefaultsToCsv()

	public function testInventarislijstHonoursHtmlFormat(): void {
		$this->withParams(['format' => 'html']);

		$rows = [['document' => 'doc-1']];
		$this->wooService->method('buildInventarislijst')->willReturn($rows);
		// The HTML renderer receives the BATCH ID as well as the rows — it
		// renders a titled reading-room page, not just a table, so dropping
		// the id would still produce valid HTML and a passing status code.
		$this->wooService->expects($this->once())
			->method('renderInventarislijstHtml')
			->with('batch-1', $rows)
			->willReturn('<table><tr><td>doc-1</td></tr></table>');
		$this->wooService->expects($this->never())->method('renderInventarislijstCsv');

		$response = $this->controller->inventarislijst('batch-1');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertStringContainsString('<table>', $response->render());

	}//end testInventarislijstHonoursHtmlFormat()

	/**
	 * format=pdf answers with the PDF/A bytes as application/pdf.
	 *
	 * @return void
	 */
	public function testInventarislijstPdfFormatReturnsAPdf(): void {
		$this->withParams(['format' => 'pdf']);

		$rows = [['document' => 'doc-1']];
		$this->wooService->method('buildInventarislijst')->willReturn($rows);
		$this->wooService->expects($this->once())
			->method('renderInventarislijstPdf')
			->with('batch-1', $rows)
			->willReturn('%PDF-1.4 inventarislijst');
		$this->wooService->expects($this->never())->method('renderInventarislijstCsv');

		$response = $this->controller->inventarislijst('batch-1');

		$this->assertInstanceOf(expected: DataDownloadResponse::class, actual: $response);
		$this->assertSame(expected: 'application/pdf', actual: $response->getHeaders()['Content-Type']);
		$this->assertStringContainsString(needle: 'inventarislijst.pdf', haystack: $response->getHeaders()['Content-Disposition']);
		$this->assertStringStartsWith(prefix: '%PDF', string: $response->render());

	}//end testInventarislijstPdfFormatReturnsAPdf()

	/**
	 * The download route is registered for the GET its links send.
	 *
	 * @return void
	 */
	public function testInventarislijstRouteIsAGet(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$verbs = [];
		foreach ($routes['routes'] as $route) {
			// ApiPathAliases adds an /api/v1/ alias by the same name; check the unversioned route.
			if ($route['name'] === 'woo#inventarislijst' && str_starts_with($route['url'], '/api/v1/') === false) {
				$verbs[] = $route['verb'];
			}
		}

		// The batch page downloads the list through a link, which always sends a GET.
		$this->assertSame(expected: ['GET'], actual: $verbs);

	}//end testInventarislijstRouteIsAGet()

	/**
	 * An unknown format falls back to CSV.
	 *
	 * @return void
	 */
	public function testInventarislijstUnknownFormatFallsBackToCsv(): void {
		$this->withParams(params: ['format' => 'xlsx']);

		$this->wooService->method('buildInventarislijst')->willReturn([]);
		$this->wooService->expects($this->once())
			->method('renderInventarislijstCsv')
			->willReturn('');
		$this->wooService->expects($this->never())->method('renderInventarislijstHtml');

		$response = $this->controller->inventarislijst('batch-1');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);

	}//end testInventarislijstUnknownFormatFallsBackToCsv()

	public function testInventarislijstUnknownBatchReturns400(): void {
		$this->withParams([]);
		$this->wooService->method('buildInventarislijst')
			->willThrowException(new \RuntimeException('unknown batch'));

		$response = $this->controller->inventarislijst('nope');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(400, $response->getStatus());
		$this->assertSame('unknown batch', $response->getData()['error']);

	}//end testInventarislijstUnknownBatchReturns400()

	/*
	 * ------------------------------------------------------------------
	 * weigeringsgronden — GET /api/woo/weigeringsgronden
	 * ------------------------------------------------------------------
	 */

	/**
	 * REQ-WPC-003: the 17 Woo information categories, each with its Dutch and
	 * English name and a label in the reader's language.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function testCategoriesListsEveryWaardelijstMemberWithBothNames(): void {
		$this->authenticate();

		$response = $this->controller->categories();

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(18, $data['total']);
		$this->assertCount(18, $data['results']);
		$first = $data['results'][0];
		$this->assertSame('infocat001', $first['code']);
		$this->assertSame('Wetten en algemeen verbindende voorschriften', $first['nl']);
		$this->assertSame('Laws and generally binding regulations', $first['en']);
		$this->assertSame('Laws and generally binding regulations', $first['label']);
		$codes = array_column($data['results'], 'code');
		$this->assertContains('infocat012', $codes);
		$this->assertContains('infocat017', $codes);
		$this->assertContains('infocat018', $codes);

	}//end testCategoriesListsEveryWaardelijstMemberWithBothNames()

	public function testEveryCategoryRowSaysWhatItPublishesUnderAndWhereItCameFrom(): void {
		$this->authenticate();

		$rows = $this->controller->categories()->getData()['results'];
		foreach ($rows as $row) {
			$this->assertStringStartsWith(
				'https://identifier.overheid.nl/tooi/def/thes/kern/c_',
				$row['uri'],
				$row['code'] . ' carries no waardelijst URI.'
			);
			$this->assertSame('waardelijst', $row['origin']);
			$this->assertSame('sitemapindex-diwoo-' . $row['code'] . '.xml', $row['sitemap']);
		}

	}//end testEveryCategoryRowSaysWhatItPublishesUnderAndWhereItCameFrom()

	/**
	 * REQ-DWP-002: the documentsoorten endpoint lists the bundled members as
	 * `{code, label, uri}`.
	 *
	 * @return void
	 */
	public function testDocumentsoortenListsTheBundledMembers(): void {
		$this->authenticate();

		$response = $this->controller->documentsoorten();
		$data = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(91, $data['total']);
		$this->assertCount(91, $data['results']);
		$this->assertSame(
			[
				'code' => 'c_852da199',
				'label' => 'Afkondigingsblad',
				'uri' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_852da199',
			],
			$data['results'][0]
		);

	}//end testDocumentsoortenListsTheBundledMembers()

	/**
	 * The documentsoorten endpoint refuses an anonymous caller, like categories().
	 *
	 * @return void
	 */
	public function testDocumentsoortenAnonymousReturns401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->assertSame(401, $this->controller->documentsoorten()->getStatus());

	}//end testDocumentsoortenAnonymousReturns401()

	/**
	 * The method has a caller: a GET route beside woo#categories.
	 *
	 * @return void
	 */
	public function testTheDocumentsoortenRouteIsAGet(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$found = array_values(array_filter($routes['routes'], static fn (array $route): bool => $route['name'] === 'woo#documentsoorten'));

		// ApiPathAliases serves every unversioned route under /api/v1/ as well, by the same name.
		$urls = array_column($found, 'url');
		sort($urls);
		$this->assertSame(['/api/v1/woo/documentsoorten', '/api/woo/documentsoorten'], $urls);
		$this->assertSame(['GET', 'GET'], array_column($found, 'verb'));

	}//end testTheDocumentsoortenRouteIsAGet()

	public function testCategoriesAnonymousReturns401(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertSame(401, $this->controller->categories()->getStatus());

	}//end testCategoriesAnonymousReturns401()

	public function testWeigeringsgrondenAnonymousReturns401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->wooService->expects($this->never())->method('getWeigeringsgronden');

		$response = $this->controller->weigeringsgronden();

		$this->assertSame(401, $response->getStatus());

	}//end testWeigeringsgrondenAnonymousReturns401()

	public function testWeigeringsgrondenReturnsResultsAndTotal(): void {
		$this->authenticate();
		$this->withParams([]);

		$grounds = [
			['code' => '5.1.1a', 'label' => 'eenheid van de Kroon'],
			['code' => '5.1.2a', 'label' => 'betrekkingen met andere staten'],
		];
		// An absent search must reach the service as null, not '': an empty
		// string is a filter that matches nothing in some backends, which
		// would silently return an empty vocabulary.
		$this->wooService->expects($this->once())
			->method('getWeigeringsgronden')
			->with(search: null)
			->willReturn($grounds);

		$response = $this->controller->weigeringsgronden();

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame($grounds, $data['results']);
		// `total` must describe the payload actually returned.
		$this->assertSame(2, $data['total']);

	}//end testWeigeringsgrondenReturnsResultsAndTotal()

	public function testWeigeringsgrondenPassesSearchTermThrough(): void {
		$this->authenticate();
		$this->withParams(['search' => 'Kroon']);

		$this->wooService->expects($this->once())
			->method('getWeigeringsgronden')
			->with(search: 'Kroon')
			->willReturn([['code' => '5.1.1a', 'label' => 'eenheid van de Kroon']]);

		$response = $this->controller->weigeringsgronden();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);

	}//end testWeigeringsgrondenPassesSearchTermThrough()

	public function testWeigeringsgrondenEmptyVocabularyReportsZeroTotal(): void {
		$this->authenticate();
		$this->withParams(['search' => 'no-such-ground']);
		$this->wooService->method('getWeigeringsgronden')->willReturn([]);

		$response = $this->controller->weigeringsgronden();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([], $response->getData()['results']);
		$this->assertSame(0, $response->getData()['total']);

	}//end testWeigeringsgrondenEmptyVocabularyReportsZeroTotal()
}//end class
