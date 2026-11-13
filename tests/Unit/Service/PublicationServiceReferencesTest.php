<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec scenario "A reader asks for the attachments": the public attachments
 * list carries the publication's references after its files, each with
 * `kind: reference` and the source URL (REQ-PBR-002).
 *
 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-a-reference-is-published-like-a-file-pointing-at-its-source-req-pbr-002
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\DocumentReferenceMapper;
use OCA\OpenCatalogi\Service\DocumentReferences;
use OCA\OpenCatalogi\Service\DownloadService;
use OCA\OpenCatalogi\Service\PublicationService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class PublicationServiceReferencesTest extends TestCase {

	private const SOURCE = 'https://documenten.voorbeeldgemeente.nl/besluit-2026-001.pdf';

	/**
	 * The service whose container answers the references with this.
	 *
	 * @param DocumentReferences|\Throwable $references The store, or what the container throws.
	 *
	 * @return PublicationService The service.
	 */
	private function service(DocumentReferences|\Throwable $references): PublicationService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($references): DocumentReferences {
				if ($references instanceof \Throwable) {
					throw $references;
				}

				return $references;
			}
		);

		return new PublicationService(
			$this->createMock(IAppConfig::class),
			$this->createMock(IRequest::class),
			$container,
			$this->createMock(IAppManager::class),
			$this->createMock(DirectoryService::class),
			$this->createMock(DownloadService::class),
		);
	}//end service()

	/**
	 * A store holding one public reference for pub-1.
	 *
	 * @return DocumentReferences The store.
	 */
	private function oneReference(): DocumentReferences {
		$references = $this->createMock(DocumentReferences::class);
		$references->method('publishedFileEntries')->willReturnCallback(
			static fn (string $id): array => $id === 'pub-1' ? [(new DocumentReferenceMapper())->asFileEntry(reference: ['url' => self::SOURCE, 'title' => 'Besluit', 'format' => 'pdf'])] : []
		);
		return $references;
	}//end oneReference()

	public function testTheReferenceFollowsTheFilesWithItsKindAndSourceUrl(): void {
		$formatted = ['results' => [['title' => 'file.pdf', 'downloadUrl' => 'https://host/d/file.pdf']], 'total' => 1, 'page' => 1, 'pages' => 1];

		$result = $this->service(references: $this->oneReference())->withReferences(formatted: $formatted, publicationId: 'pub-1');

		$this->assertSame(['file.pdf', 'Besluit'], array_column($result['results'], 'title'));
		$this->assertSame('reference', $result['results'][1]['kind']);
		$this->assertSame(self::SOURCE, $result['results'][1]['downloadUrl']);
		$this->assertSame(self::SOURCE, $result['results'][1]['accessUrl']);
		$this->assertSame(2, $result['total']);
	}//end testTheReferenceFollowsTheFilesWithItsKindAndSourceUrl()

	public function testALaterPageDoesNotRepeatTheReferences(): void {
		$formatted = ['results' => [['title' => 'file-30.pdf']], 'total' => 31, 'page' => 2];

		$this->assertSame($formatted, $this->service(references: $this->oneReference())->withReferences(formatted: $formatted, publicationId: 'pub-1'));
	}//end testALaterPageDoesNotRepeatTheReferences()

	public function testAPublicationWithoutReferencesKeepsItsList(): void {
		$formatted = ['results' => [], 'total' => 0];

		$this->assertSame($formatted, $this->service(references: $this->oneReference())->withReferences(formatted: $formatted, publicationId: 'pub-2'));
	}//end testAPublicationWithoutReferencesKeepsItsList()

	public function testAnUnavailableStoreKeepsTheFiles(): void {
		$formatted = ['results' => [['title' => 'file.pdf']], 'total' => 1];

		$this->assertSame($formatted, $this->service(references: new \RuntimeException('down'))->withReferences(formatted: $formatted, publicationId: 'pub-1'));
	}//end testAnUnavailableStoreKeepsTheFiles()
}//end class
