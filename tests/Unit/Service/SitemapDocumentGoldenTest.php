<?php

/**
 * Golden test for the sitemap document mapping.
 *
 * Runs `SitemapService::mapDiwooDocument()` over publications that bind every
 * axis, none of them, and each one alone, and compares the mapped document
 * and the violations with the recorded output. A refactor must keep both,
 * including key order, which is the order of the XML elements.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit tests/Unit/Service/SitemapDocumentGoldenTest.php`.
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
 * @spec openspec/specs/woo-compliance/spec.md
 *
 * @covers \OCA\OpenCatalogi\Service\SitemapService
 * @uses \OCA\OpenCatalogi\Service\TooiVocabularyService
 * @uses \OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry
 * @uses \OCA\OpenCatalogi\Service\Woo\LocalCategoryAdmission
 * @uses \OCA\OpenCatalogi\Service\WooCategory
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\SitemapService;
use OCA\OpenCatalogi\Service\TooiVocabularyService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class SitemapDocumentGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/sitemap-document-golden.json';

	/**
	 * @return array<string, array{0: array, 1: array}>
	 */
	public static function cases(): array {
		$self = ['created' => '2024-01-15 10:00:00', 'updated' => '2024-06-20 14:30:00', 'owner' => 'admin'];
		$file = ['downloadUrl' => 'https://example.com/files/doc.pdf', 'extension' => 'pdf', 'published' => '2024-05-10 12:00:00', 'owner' => 'file-owner'];

		return [
			'every axis binds' => [
				['category' => 'infocat014', 'soortHandeling' => 'vaststelling', 'tooiIdentifier' => 'https://identifier.overheid.nl/tooi/id/gemeente/gm0855', '@self' => $self],
				$file,
			],
			'no axis binds' => [
				['category' => 'nothing', 'soortHandeling' => 'nonsense', '@self' => $self],
				['downloadUrl' => 'https://example.com/a.DOCX', 'extension' => 'DOCX'],
			],
			'only the organisation' => [
				['wooCategory' => 'not a category', 'soortHandeling' => 7, '@self' => $self + ['organisation' => 'https://identifier.overheid.nl/tooi/id/gemeente/gm0363'], 'publicationDate' => '2024-02-02'],
				['extension' => 'odt', 'published' => '2024-03-03 03:03:03'],
			],
			'only the category, handling defaults' => [
				['tooiCategorieUri' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_3baef532', '@self' => $self + ['organisation' => '  '], 'publicationDate' => '2024-02-02'],
				['downloadUrl' => 'https://example.com/b.pdf', 'extension' => 'pdf', 'published' => '2024-04-04 04:04:04'],
			],
			'handling time from the publication date' => [
				['category' => 'infocat014', 'publicationDate' => '2023-12-31', '@self' => $self],
				['downloadUrl' => 'https://example.com/c.pdf', 'extension' => 'pdf'],
			],
			'category by name, owner from the publication' => [
				['tooiCategorieNaam' => 'Wetten en algemeen verbindende voorschriften', '@self' => $self],
				['extension' => 'xml', 'published' => '2024-04-04 04:04:04'],
			],
		];
	}

	/**
	 * @param array $publication The publication.
	 * @param array $file        The file.
	 */
	#[DataProvider('cases')]
	public function testTheMappedDocumentMatchesTheRecordedOutput(array $publication, array $file): void {
		$settings = $this->createMock(SettingsService::class);
		$categories = new \OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry(
			new TooiVocabularyService(),
			$settings,
			$this->createMock(LoggerInterface::class),
			new \OCA\OpenCatalogi\Service\Woo\LocalCategoryAdmission(new TooiVocabularyService()),
		);
		$service = new SitemapService(
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$settings,
			$this->createMock(IURLGenerator::class),
			$this->createMock(IAppConfig::class),
			new TooiVocabularyService(),
			$this->createMock(PublicationQueryService::class),
			$categories,
		);

		$method = new \ReflectionMethod(SitemapService::class, 'mapDiwooDocument');
		$violations = [['documentLoc' => 'earlier', 'axis' => 'kept', 'reason' => 'appended to']];
		$document = $method->invokeArgs($service, [$publication, $file, &$violations]);
		$output = json_decode((string)json_encode(['document' => $document, 'violations' => $violations]), true);

		$name = (string)$this->dataName();
		$recorded = [];
		if (is_file(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $output;
			ksort($recorded);
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->markTestSkipped('Recorded.');
		}

		$this->assertArrayHasKey($name, $recorded, 'No recorded output for this case.');
		$this->assertSame($recorded[$name], $output);
	}
}
