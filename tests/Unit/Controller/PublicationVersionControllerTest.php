<?php

/**
 * Unit tests for PublicationVersionController: an earlier version stays at its
 * own URL while the publication is public, and is gone when it is not.
 *
 * @category Tests
 * @package  OCA\OpenCatalogi\Tests\Unit\Controller
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
 *
 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\PublicationVersionServiceInterface;
use OCA\OpenCatalogi\Controller\PublicationsController;
use OCA\OpenCatalogi\Controller\PublicationVersionController;
use OCA\OpenCatalogi\Service\Publication\PublicationStateService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenCatalogi\Controller\PublicationVersionController
 * @uses \OCA\OpenCatalogi\Service\Publication\PublicationStateService
 */
final class PublicationVersionControllerTest extends TestCase {

	private const UUID = 'a1b2c3d4-0000-4000-8000-000000000001';

	/**
	 * Version 1, kept when the title was corrected and the document replaced.
	 *
	 * @var array<string, mixed>
	 */
	private const VERSION_ONE = [
		'id' => 'v0000000-0000-4000-8000-000000000001',
		'publication' => self::UUID,
		'version' => 1,
		'supersededAt' => '2026-11-23T10:00:00+00:00',
		'fields' => ['id' => self::UUID, 'title' => 'Besluit parkeren 2026'],
		'documents' => [['title' => 'besluit.pdf', 'format' => 'application/pdf', 'fileId' => 1005]],
	];

	/**
	 * The controller over a public read that answers `$current` with `$status`.
	 *
	 * @param array<string, mixed> $current The current publication the public read answers.
	 * @param int $status The status of the public read.
	 * @param object|null $file The document the version service answers.
	 *
	 * @return PublicationVersionController
	 */
	private function controller(array $current, int $status = 200, ?object $file = null): PublicationVersionController {
		$publications = $this->createMock(originalClassName: PublicationsController::class);
		$publications->method('show')->willReturn(new JSONResponse($current, $status));

		$versions = $this->createMock(originalClassName: PublicationVersionServiceInterface::class);
		$versions->method('versionsOf')->willReturnCallback(
			static fn (string $publication): array => ($publication === self::UUID) ? [self::VERSION_ONE] : []
		);
		$versions->method('documentOf')->willReturn($file);

		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $parameters): string => 'https://example.org/' . $route . '?' . http_build_query($parameters)
		);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('*');

		return new PublicationVersionController(
			appName: 'opencatalogi',
			request: $this->createMock(originalClassName: IRequest::class),
			publications: $publications,
			versions: $versions,
			states: new PublicationStateService(),
			urls: $urls,
			l10n: $l10n,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			appConfig: $appConfig
		);
	}//end controller()

	/**
	 * The corrected publication as the public read answers it.
	 *
	 * @param array<string, mixed> $extra Properties to add or replace.
	 *
	 * @return array<string, mixed>
	 */
	private static function corrected(array $extra = []): array {
		return $extra + [
			'id' => self::UUID,
			'title' => 'Besluit parkeren binnenstad 2026',
			'status' => 'published',
			'publicationDate' => '2026-01-05T09:00:00+00:00',
		];
	}//end corrected()

	/**
	 * After a correction, version 1 answers the old title and document, superseded, beside the current URL.
	 *
	 * @return void
	 */
	public function testACorrectionKeepsTheEarlierVersionAtItsOwnUrl(): void {
		$controller = $this->controller(current: self::corrected());

		$response = $controller->show(catalogSlug: 'woo', id: self::UUID, version: '1');
		$data = $response->getData();

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: 'Besluit parkeren 2026', actual: $data['fields']['title']);
		$this->assertTrue(condition: $data['superseded']);
		$this->assertSame(expected: 'besluit.pdf', actual: $data['documents'][0]['title']);
		$this->assertStringContainsString(needle: 'opencatalogi.publicationVersion.document', haystack: $data['documents'][0]['downloadUrl']);
		$this->assertStringContainsString(needle: 'fileId=1005', haystack: $data['documents'][0]['downloadUrl']);
		$this->assertStringContainsString(needle: 'opencatalogi.publications.show', haystack: $data['current']);
		$this->assertSame(expected: '*', actual: $response->getHeaders()['Access-Control-Allow-Origin']);

		$list = $controller->index(catalogSlug: 'woo', id: self::UUID)->getData();
		$this->assertSame(expected: 1, actual: $list['total']);
		$this->assertSame(expected: 1, actual: $list['results'][0]['version']);
		$this->assertStringContainsString(needle: 'opencatalogi.publicationVersion.show', haystack: $list['results'][0]['url']);
	}//end testACorrectionKeepsTheEarlierVersionAtItsOwnUrl()

	/**
	 * A publication the public read no longer answers has no versions, nor documents.
	 *
	 * @return void
	 */
	public function testAVersionOfAPublicationThatIsNoLongerPublicIs404(): void {
		$controller = $this->controller(current: ['error' => 'not found'], status: 404, file: $this->file());

		$this->assertSame(expected: 404, actual: $controller->index(catalogSlug: 'woo', id: self::UUID)->getStatus());
		$this->assertSame(expected: 404, actual: $controller->show(catalogSlug: 'woo', id: self::UUID, version: '1')->getStatus());
		$this->assertSame(expected: 404, actual: $controller->document(catalogSlug: 'woo', id: self::UUID, version: '1', fileId: '1005')->getStatus());
	}//end testAVersionOfAPublicationThatIsNoLongerPublicIs404()

	/**
	 * A signed-in caller reading a withdrawn publication still gets no versions.
	 *
	 * @return void
	 */
	public function testAWithdrawnPublicationReadBySignedInCallerIs404(): void {
		$controller = $this->controller(current: self::corrected(extra: ['depublicationDate' => '2026-02-01T00:00:00+00:00']));

		$this->assertSame(expected: 404, actual: $controller->show(catalogSlug: 'woo', id: self::UUID, version: '1')->getStatus());
	}//end testAWithdrawnPublicationReadBySignedInCallerIs404()

	/**
	 * A version number that was never stored is 404.
	 *
	 * @return void
	 */
	public function testAnUnknownVersionIs404(): void {
		$controller = $this->controller(current: self::corrected());

		$this->assertSame(expected: 404, actual: $controller->show(catalogSlug: 'woo', id: self::UUID, version: '2')->getStatus());
		$this->assertSame(expected: 404, actual: $controller->show(catalogSlug: 'woo', id: self::UUID, version: '0')->getStatus());
	}//end testAnUnknownVersionIs404()

	/**
	 * A listed document of a public publication's version downloads.
	 *
	 * @return void
	 */
	public function testADocumentOfAnEarlierVersionDownloads(): void {
		$response = $this->controller(current: self::corrected(), file: $this->file())->document(catalogSlug: 'woo', id: self::UUID, version: '1', fileId: '1005');

		$this->assertInstanceOf(expected: DataDownloadResponse::class, actual: $response);
		$this->assertSame(expected: 200, actual: $response->getStatus());
	}//end testADocumentOfAnEarlierVersionDownloads()

	/**
	 * The preflight allows the read.
	 *
	 * @return void
	 */
	public function testThePreflightAllowsTheRead(): void {
		$response = $this->controller(current: self::corrected())->preflightedCors();

		$this->assertSame(expected: '*', actual: $response->getHeaders()['Access-Control-Allow-Origin']);
	}//end testThePreflightAllowsTheRead()

	/**
	 * A file node double.
	 *
	 * @return object
	 */
	private function file(): object {
		return new class {
			/** @return string */
			public function getContent(): string {
				return '%PDF-1.7';
			}

			/** @return string */
			public function getName(): string {
				return 'besluit.pdf';
			}

			/** @return string */
			public function getMimetype(): string {
				return 'application/pdf';
			}
		};
	}//end file()
}//end class
