<?php

/**
 * Golden test for DcatService::validateCatalog() and validateForDonl().
 *
 * The catalog document is served by a test subclass, so every case pins how the
 * profile-version, theme and mandatory-property violations are assembled. The
 * answers are compared to fixtures/dcat-validation-golden.json.
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

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DcatAssurance;
use OCA\OpenCatalogi\Service\DcatMappingService;
use OCA\OpenCatalogi\Service\DcatSerializer;
use OCA\OpenCatalogi\Service\DcatService;
use OCA\OpenCatalogi\Service\DcatVocabularyService;
use OCA\OpenCatalogi\Service\InstanceSettings;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\StandardsVersionService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for the DCAT validation answers.
 *
 * @covers \OCA\OpenCatalogi\Service\DcatService
 * @uses   \OCA\OpenCatalogi\Service\DcatAssurance
 * @uses   \OCA\OpenCatalogi\Service\DcatMappingService
 * @uses   \OCA\OpenCatalogi\Service\DcatSerializer
 * @uses   \OCA\OpenCatalogi\Service\DcatVocabularyService
 * @uses   \OCA\OpenCatalogi\Service\InstanceSettings
 * @uses   \OCA\OpenCatalogi\Service\OpenRegisterLookup
 */
class DcatValidationGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/dcat-validation-golden.json';

	/**
	 * The recorded cases: the served graph, the theme violations and the profile status.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$full = ['@type' => 'dcat:Dataset', '@id' => 'https://d/1', 'dct:title' => 'T', 'dct:publisher' => ['@id' => 'p'], 'dcat:landingPage' => ['@id' => 'l']];

		return [
			'no datasets' => [['graph' => [['@type' => 'dcat:Catalog', '@id' => 'c']]]],
			'complete dataset' => [['graph' => [$full]]],
			'missing everything' => [['graph' => [['@type' => 'dcat:Dataset', '@id' => 'https://d/2']]]],
			'empty values and no id' => [['graph' => [['@type' => 'dcat:Dataset', 'dct:title' => '', 'dct:publisher' => [], 'dcat:landingPage' => 'x']]]],
			'only datasets count' => [['graph' => [['@type' => 'dcat:Distribution'], ['@id' => 'untyped'], $full, ['@type' => 'dcat:Dataset', '@id' => 'https://d/3', 'dct:title' => 'x']]]],
			'theme violations' => [['graph' => [$full], 'themes' => [['axis' => 'theme', 'iri' => 'https://d/1', 'value' => 'bad']]]],
			'profile behind with themes' => [['graph' => [], 'themes' => [['axis' => 'theme', 'iri' => 'x']], 'profile' => StandardsVersionService::STATUS_BEHIND]],
			'profile behind' => [['graph' => [['@type' => 'dcat:Dataset', '@id' => 'https://d/4']], 'profile' => StandardsVersionService::STATUS_BEHIND]],
		];

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param array<string, mixed> $world The case.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testValidationMatchesTheRecording(array $world): void {
		$name = $this->dataName();
		$versions = $this->createMock(StandardsVersionService::class);
		$versions->method('dcatApNlVersion')->willReturn(
			['declared' => '3.0', 'published' => '3.1', 'status' => ($world['profile'] ?? StandardsVersionService::STATUS_CURRENT), 'profile' => StandardsVersionService::DCAT_AP_NL_PROFILE]
		);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getBaseUrl')->willReturn('https://host');

		$service = new class(new OpenRegisterLookup($this->createMock(ContainerInterface::class), $this->createMock(IAppManager::class)), new DcatMappingService(new DcatVocabularyService()), new DcatSerializer(), new InstanceSettings($urls, $this->createMock(IAppConfig::class)), $this->createMock(LoggerInterface::class), new DcatAssurance($versions)) extends DcatService {
			/**
			 * The served document: the catalog's `graph`, and its `themes` as theme violations.
			 *
			 * @param array<string, mixed> $catalog The catalog.
			 * @param string $catalogSlug The slug.
			 * @param int $page The page.
			 * @param array $violations The theme violations, appended to.
			 *
			 * @return array<string, mixed> The document.
			 */
			public function buildCatalogDocument(array $catalog, string $catalogSlug, int $page = 1, array &$violations = []): array {
				array_push($violations, ...($catalog['themes'] ?? []));
				return ['@context' => [], '@graph' => $catalog['graph']];
			}
		};

		$actual = [
			'validateCatalog' => $service->validateCatalog(catalog: $world, catalogSlug: 'woo'),
			'validateForDonl' => $service->validateForDonl(catalog: $world, catalogSlug: 'woo'),
		];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($actual), true));

	}//end testValidationMatchesTheRecording()
}//end class
