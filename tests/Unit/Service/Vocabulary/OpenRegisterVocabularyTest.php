<?php

/**
 * Tests for the reach into OpenRegister's vocabulary register (REQ-WVC-002).
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service\Vocabulary;

use OCA\OpenCatalogi\Service\Vocabulary\OpenRegisterVocabulary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The adapter over stand-ins with OpenRegister's method names.
 *
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
 */
#[CoversClass(OpenRegisterVocabulary::class)]
class OpenRegisterVocabularyTest extends TestCase {

	/**
	 * An adapter whose container answers the given services.
	 *
	 * @param array<string, object> $services The services by name.
	 *
	 * @return OpenRegisterVocabulary
	 */
	private function adapter(array $services): OpenRegisterVocabulary {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $name) use ($services): object {
				if (isset($services[$name]) === false) {
					throw new class('no ' . $name) extends \Exception implements \Psr\Container\NotFoundExceptionInterface {
					};
				}

				return $services[$name];
			}
		);

		return new OpenRegisterVocabulary(container: $container);
	}//end adapter()

	/**
	 * A deprecated concept is not active.
	 *
	 * @return void
	 */
	public function testOnlyConceptsThatAreNotDeprecatedAreActive(): void {
		$repository = new class {
			/**
			 * The concepts of a scheme.
			 *
			 * @param string $schemeUri The scheme.
			 *
			 * @return array<string, array<string, mixed>>
			 */
			public function conceptsOf(string $schemeUri): array {
				return ['https://x.test/a' => ['deprecated' => false], 'https://x.test/b' => ['deprecated' => true], 'https://x.test/c' => []];
			}
		};

		$uris = $this->adapter(services: [OpenRegisterVocabulary::CONCEPTS => $repository])->activeConceptUris(schemeUri: 'https://x.test/s');
		$this->assertSame(expected: ['https://x.test/a', 'https://x.test/c'], actual: $uris);
	}//end testOnlyConceptsThatAreNotDeprecatedAreActive()

	/**
	 * The import answers OpenRegister's counts, and its failure is a RuntimeException.
	 *
	 * @return void
	 */
	public function testTheImportAnswersTheCountsAndWrapsAFailure(): void {
		$importer = new class {
			/**
			 * Import.
			 *
			 * @param array<string, mixed> $jsonLd The document.
			 *
			 * @return array<string, mixed>
			 */
			public function importJsonLd(array $jsonLd): array {
				if ($jsonLd === []) {
					throw new \InvalidArgumentException('no scheme');
				}

				return ['scheme' => 's', 'created' => 2, 'updated' => 0, 'unchanged' => 0, 'deprecated' => 0];
			}
		};
		$adapter = $this->adapter(services: [OpenRegisterVocabulary::IMPORTER => $importer]);

		$this->assertSame(expected: 2, actual: $adapter->import(jsonLd: ['@graph' => [['@id' => 's']]])['created']);

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'The vocabulary import failed: no scheme');
		$adapter->import(jsonLd: []);
	}//end testTheImportAnswersTheCountsAndWrapsAFailure()

	/**
	 * Without OpenRegister both calls refuse, naming what is missing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterBothCallsRefuse(): void {
		$adapter = $this->adapter(services: []);
		try {
			$adapter->activeConceptUris(schemeUri: 'https://x.test/s');
			$this->fail(message: 'the read did not refuse');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString(needle: 'ConceptRepository', haystack: $e->getMessage());
		}

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'VocabularyImporterInterface');
		$adapter->import(jsonLd: ['@graph' => []]);
	}//end testWithoutOpenRegisterBothCallsRefuse()

	/**
	 * Concepts are named by their Dutch label, else another label, else their notation or uri.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
	 */
	public function testConceptsAreNamedByTheirDutchLabel(): void {
		$repository = new class {
			/**
			 * The concepts of a scheme.
			 *
			 * @param string $schemeUri The scheme.
			 *
			 * @return array<string, mixed>
			 */
			public function conceptsOf(string $schemeUri): array {
				return [
					'https://t/a' => ['prefLabel' => ['en' => 'Dordrecht municipality', 'nl' => 'gemeente Dordrecht'], 'notation' => 'gm0505'],
					'https://t/b' => ['prefLabel' => ['en' => 'Water board', 'nl' => ' '], 'deprecated' => true],
					'https://t/c' => ['prefLabel' => 'provincie Utrecht', 'notation' => 'pv26'],
					'https://t/d' => ['notation' => 'ws0152'],
					'https://t/e' => [],
					'https://t/f' => 'not a concept',
				];
			}
		};

		$concepts = $this->adapter(services: [OpenRegisterVocabulary::CONCEPTS => $repository])->concepts(schemeUri: 'https://t');

		$this->assertSame(
			expected: [
				'https://t/a' => ['label' => 'gemeente Dordrecht', 'notation' => 'gm0505', 'deprecated' => false],
				'https://t/b' => ['label' => 'Water board', 'notation' => '', 'deprecated' => true],
				'https://t/c' => ['label' => 'provincie Utrecht', 'notation' => 'pv26', 'deprecated' => false],
				'https://t/d' => ['label' => 'ws0152', 'notation' => 'ws0152', 'deprecated' => false],
				'https://t/e' => ['label' => 'https://t/e', 'notation' => '', 'deprecated' => false],
			],
			actual: $concepts
		);
	}//end testConceptsAreNamedByTheirDutchLabel()
}//end class
