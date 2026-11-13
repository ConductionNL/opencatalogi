<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A document reference inside its window is a DCAT distribution whose
 * download and access URL is the source URL, after the files (REQ-PBR-002).
 *
 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-a-reference-is-published-like-a-file-pointing-at-its-source-req-pbr-002
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DcatAssurance;
use OCA\OpenCatalogi\Service\DcatMappingService;
use OCA\OpenCatalogi\Service\DcatSerializer;
use OCA\OpenCatalogi\Service\DcatService;
use OCA\OpenCatalogi\Service\DcatVocabularyService;
use OCA\OpenCatalogi\Service\DocumentReferenceMapper;
use OCA\OpenCatalogi\Service\DocumentReferences;
use OCA\OpenCatalogi\Service\InstanceSettings;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\StandardsVersionService;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class DcatServiceDocumentReferenceTest extends TestCase {

	private const SOURCE = 'https://documenten.voorbeeldgemeente.nl/besluit-2026-001.pdf';

	/**
	 * The DCAT service over one publication with one file.
	 *
	 * @param DocumentReferences|null $references The references, or none.
	 *
	 * @return DcatService The service.
	 */
	private function service(?DocumentReferences $references): DcatService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('https://host');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn ($app, $key, $default = '') => $default);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('searchObjectsPaginated')->willReturn(
			['results' => [['@self' => ['uuid' => 'pub-1', 'schema' => 28], 'id' => 'pub-1', 'title' => 'Besluit']], 'next' => null]
		);
		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFiles')->willReturn(['f']);
		$fileService->method('formatFiles')->willReturn(['results' => [['title' => 'file.pdf', 'downloadUrl' => 'https://host/d/file.pdf', 'extension' => 'pdf']]]);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willThrowException(new \RuntimeException('not needed'));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => match ($id) {
				'OCA\\OpenRegister\\Service\\ObjectService' => $objectService,
				'OCA\\OpenRegister\\Service\\FileService' => $fileService,
				'OCA\\OpenRegister\\Db\\SchemaMapper' => $schemaMapper,
			}
		);

		return new DcatService(
			new OpenRegisterLookup($container, $appManager),
			new DcatMappingService(new DcatVocabularyService()),
			new DcatSerializer(),
			new InstanceSettings($urlGenerator, $appConfig),
			$this->createMock(LoggerInterface::class),
			new DcatAssurance($this->createMock(StandardsVersionService::class)),
			$references
		);
	}//end service()

	/**
	 * The download URLs of the distributions of the one dataset.
	 *
	 * @param DcatService $service The service.
	 *
	 * @return list<string> The URLs.
	 */
	private static function downloadUrls(DcatService $service): array {
		$document = $service->buildCatalogDocument(catalog: ['id' => 'c1', 'title' => 'Open', 'registers' => ['20'], 'schemas' => [28]], catalogSlug: 'open');
		foreach ($document['@graph'] as $node) {
			if (($node['@type'] ?? null) === 'dcat:Dataset') {
				return array_map(static fn (array $distribution): string => $distribution['dcat:downloadURL']['@id'], ($node['dcat:distribution'] ?? []));
			}
		}

		return [];
	}//end downloadUrls()

	public function testAReferenceIsADistributionAfterTheFiles(): void {
		$references = $this->createMock(DocumentReferences::class);
		$references->expects($this->once())->method('appendTo')->with($this->anything(), 'pub-1')->willReturnCallback(
			static fn (array $files): array => array_merge($files, [(new DocumentReferenceMapper())->asFileEntry(reference: ['url' => self::SOURCE, 'title' => 'Besluit', 'format' => 'pdf'])])
		);

		$this->assertSame(['https://host/d/file.pdf', self::SOURCE], self::downloadUrls(service: $this->service(references: $references)));
	}//end testAReferenceIsADistributionAfterTheFiles()

	public function testWithoutReferencesTheFilesAreAsBefore(): void {
		$this->assertSame(['https://host/d/file.pdf'], self::downloadUrls(service: $this->service(references: null)));
	}//end testWithoutReferencesTheFilesAreAsBefore()
}//end class
