<?php

/**
 * Unit tests for the version routes of PublicationsController: an earlier
 * version stays at its own URL while the publication is public, and is gone
 * when the publication's own public read no longer answers it.
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
use OCA\OpenCatalogi\Controller\PublicationVersions;
use OCA\OpenCatalogi\Service\Publication\PublicationStateService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Controller\PublicationVersionController
 * @covers \OCA\OpenCatalogi\Controller\PublicationVersions
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
	 * @param array<string, mixed> $current What the public read answers.
	 * @param array<string, string> $route The version and document numbers of the route.
	 * @param array{status?: int, versions?: PublicationVersionServiceInterface} $with The read's status and a version service.
	 *
	 * @return PublicationVersionController
	 */
	private function controller(array $current, array $route = [], array $with = []): PublicationVersionController {
		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $name, array $parameters): string => 'https://example.org/' . $name . '?' . http_build_query($parameters)
		);
		$publications = $this->createMock(originalClassName: PublicationsController::class);
		$publications->method('show')->willReturn(new JSONResponse($current, ($with['status'] ?? 200)));
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$answers = new PublicationVersions(publications: $publications, versions: ($with['versions'] ?? $this->versions()), urls: $urls, l10n: $l10n);

		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($route[$key] ?? $default)
		);
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnArgument(2);

		return new PublicationVersionController(appName: 'opencatalogi', request: $request, answers: $answers, appConfig: $config);
	}//end controller()

	/**
	 * A version service double holding version 1 of the publication.
	 *
	 * @return PublicationVersionServiceInterface
	 */
	private function versions(): PublicationVersionServiceInterface {
		$versions = $this->createMock(originalClassName: PublicationVersionServiceInterface::class);
		$versions->method('versionsOf')->willReturnCallback(
			static fn (string $publication): array => match ($publication) {
				self::UUID => [self::VERSION_ONE],
				default => [],
			}
		);
		$versions->method('publicUuidOf')->willReturnCallback(
			static function (array $record): ?string {
				if ((new PublicationStateService())->stateOf(publication: $record) !== 'public') {
					return null;
				}

				return (string)$record['id'];
			}
		);
		$versions->method('documentOf')->willReturnCallback(
			fn (array $version, int $fileId): ?object => match ($fileId) {
				1005 => $this->file(),
				default => null,
			}
		);
		return $versions;
	}//end versions()

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
		$response = $this->controller(current: self::corrected(), route: ['version' => '1'])->show(catalogSlug: 'woo', id: self::UUID);
		$data = $response->getData();

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: 'Besluit parkeren 2026', actual: $data['fields']['title']);
		$this->assertTrue(condition: $data['superseded']);
		$this->assertSame(expected: 'besluit.pdf', actual: $data['documents'][0]['title']);
		$this->assertStringContainsString(needle: 'opencatalogi.publicationVersion.document', haystack: $data['documents'][0]['downloadUrl']);
		$this->assertStringContainsString(needle: 'fileId=1005', haystack: $data['documents'][0]['downloadUrl']);
		$this->assertStringContainsString(needle: 'opencatalogi.publications.show', haystack: $data['current']);
		$this->assertSame(expected: '*', actual: $response->getHeaders()['Access-Control-Allow-Origin']);

		$list = $this->controller(current: self::corrected())->index(catalogSlug: 'woo', id: self::UUID)->getData();
		$this->assertSame(expected: 1, actual: $list['total']);
		$this->assertSame(expected: 1, actual: $list['results'][0]['version']);
		$this->assertStringContainsString(needle: 'opencatalogi.publicationVersion.show?', haystack: $list['results'][0]['url']);
	}//end testACorrectionKeepsTheEarlierVersionAtItsOwnUrl()

	/**
	 * A publication the public read no longer answers has no versions, nor documents.
	 *
	 * @return void
	 */
	public function testAVersionOfAPublicationThatIsNoLongerPublicIs404(): void {
		$controller = $this->controller(current: ['error' => 'not found'], route: ['version' => '1', 'fileId' => '1005'], with: ['status' => 404]);

		$this->assertSame(expected: 404, actual: $controller->index(catalogSlug: 'woo', id: self::UUID)->getStatus());
		$this->assertSame(expected: 404, actual: $controller->show(catalogSlug: 'woo', id: self::UUID)->getStatus());
		$this->assertSame(expected: 404, actual: $controller->document(catalogSlug: 'woo', id: self::UUID)->getStatus());
	}//end testAVersionOfAPublicationThatIsNoLongerPublicIs404()

	/**
	 * A signed-in caller who still reads a withdrawn publication gets no versions.
	 *
	 * @return void
	 */
	public function testAWithdrawnPublicationReadBySignedInCallerIs404(): void {
		$withdrawn = self::corrected(extra: ['depublicationDate' => '2026-02-01T00:00:00+00:00']);
		$controller = $this->controller(current: $withdrawn, route: ['version' => '1']);

		$this->assertSame(expected: 404, actual: $controller->show(catalogSlug: 'woo', id: self::UUID)->getStatus());
	}//end testAWithdrawnPublicationReadBySignedInCallerIs404()

	/**
	 * A version number or a document that was never stored is 404.
	 *
	 * @return void
	 */
	public function testAnUnknownVersionOrDocumentIs404(): void {
		$unknownVersion = $this->controller(current: self::corrected(), route: ['version' => '2']);
		$unknownDocument = $this->controller(current: self::corrected(), route: ['version' => '1', 'fileId' => '77']);

		$this->assertSame(expected: 404, actual: $unknownVersion->show(catalogSlug: 'woo', id: self::UUID)->getStatus());
		$this->assertSame(expected: 404, actual: $unknownDocument->document(catalogSlug: 'woo', id: self::UUID)->getStatus());
	}//end testAnUnknownVersionOrDocumentIs404()

	/**
	 * A listed document of a public publication's version downloads.
	 *
	 * @return void
	 */
	public function testADocumentOfAnEarlierVersionDownloads(): void {
		$controller = $this->controller(current: self::corrected(), route: ['version' => '1', 'fileId' => '1005']);

		$response = $controller->document(catalogSlug: 'woo', id: self::UUID);

		$this->assertInstanceOf(expected: DataDownloadResponse::class, actual: $response);
		$this->assertSame(expected: 200, actual: $response->getStatus());
	}//end testADocumentOfAnEarlierVersionDownloads()

	/**
	 * A version store that cannot be read answers 500 without its reason.
	 *
	 * @return void
	 */
	public function testAnUnreadableVersionStoreIs500(): void {
		$versions = $this->createMock(originalClassName: PublicationVersionServiceInterface::class);
		$versions->method('publicUuidOf')->willReturn(self::UUID);
		$versions->method('versionsOf')->willThrowException(new RuntimeException('The publicationVersion schema is not configured.'));

		$response = $this->controller(current: self::corrected(), with: ['versions' => $versions])->index(catalogSlug: 'woo', id: self::UUID);

		$this->assertSame(expected: 500, actual: $response->getStatus());
		$this->assertSame(expected: 'version-unavailable', actual: $response->getData()['error']);
	}//end testAnUnreadableVersionStoreIs500()

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
