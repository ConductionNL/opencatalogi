<?php

/**
 * A vocabulary register that answers from the recorded TOOI downloads.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-the-tooi-lists-are-read-from-openregisters-vocabulary-register-req-wvc-001
 */

declare(strict_types=1);

namespace Unit\Support;

use OCA\OpenCatalogi\Contract\OrganisationActivationInterface;
use OCA\OpenCatalogi\Contract\VocabularyRegisterInterface;
use OCA\OpenCatalogi\Service\TooiVocabularyService;
use OCA\OpenCatalogi\Service\Vocabulary\ValueListRefresh;
use RuntimeException;

/**
 * Answers `concepts()` with what the daily refresh imports from the TOOI
 * downloads recorded in `tests/fixtures/tooi/` (the versions DiWoo 0.9.8
 * binds), so a test reads the lists a refreshed instance holds. A test can
 * mark concepts deprecated, drop a scheme, or make every read throw.
 */
class RecordedTooiRegister implements VocabularyRegisterInterface {

	/**
	 * The recorded download of each scheme.
	 *
	 * @var array<string, string>
	 */
	public const FIXTURES = [
		ValueListRefresh::CATEGORIES_SCHEME => 'scw_woo_informatiecategorieen_4.json',
		'https://identifier.overheid.nl/tooi/set/ccw_plooi_documentsoorten' => 'ccw_plooi_documentsoorten_7.json',
		'https://identifier.overheid.nl/tooi/set/ccw_plooi_documenthandelingen' => 'ccw_plooi_documenthandelingen_2.json',
		'https://identifier.overheid.nl/tooi/def/thes/xtrn/taal' => 'scw_talen_1.json',
	];

	/**
	 * SKOS, as the downloads spell it.
	 *
	 * @var string
	 */
	private const SKOS = 'http://www.w3.org/2004/02/skos/core#';

	/**
	 * Every read made, as scheme uris.
	 *
	 * @var array<int, string>
	 */
	public array $reads = [];

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $deprecated Concept uris to answer as deprecated.
	 * @param array<int, string> $empty      Scheme uris to answer as holding nothing.
	 * @param bool               $unreadable Whether every read throws, as OpenRegisterVocabulary does without OpenRegister.
	 */
	public function __construct(
		private readonly array $deprecated = [],
		private readonly array $empty = [],
		private readonly bool $unreadable = false,
	) {
	}//end __construct()

	/**
	 * The service over the recorded lists.
	 *
	 * @param OrganisationActivationInterface|null $activation The activated organisations, if any.
	 *
	 * @return TooiVocabularyService The service.
	 */
	public static function service(?OrganisationActivationInterface $activation = null): TooiVocabularyService {
		return new TooiVocabularyService(activation: $activation, register: new self());
	}//end service()

	/**
	 * The service over a register that cannot be read.
	 *
	 * @return TooiVocabularyService The service.
	 */
	public static function unreadableService(): TooiVocabularyService {
		return new TooiVocabularyService(register: new self(unreadable: true));
	}//end unreadableService()

	/**
	 * The uris of the concepts of a scheme that are not deprecated.
	 *
	 * @param string $schemeUri The scheme's uri.
	 *
	 * @return array<int, string> The uris.
	 */
	public function activeConceptUris(string $schemeUri): array {
		$uris = [];
		foreach ($this->concepts(schemeUri: $schemeUri) as $uri => $concept) {
			if ($concept['deprecated'] === false) {
				$uris[] = $uri;
			}
		}

		return $uris;
	}//end activeConceptUris()

	/**
	 * Refuses: the recorded register is read-only.
	 *
	 * @param array<string, mixed> $jsonLd The document.
	 *
	 * @return array{scheme: string, created: int, updated: int, unchanged: int, deprecated: int} Never.
	 *
	 * @throws RuntimeException Always.
	 */
	public function import(array $jsonLd): array {
		throw new RuntimeException('The recorded register is read-only.');
	}//end import()

	/**
	 * The concepts of a scheme as the refresh imports them from the recorded download.
	 *
	 * @param string $schemeUri The scheme's uri.
	 *
	 * @return array<string, array{label: string, notation: string, deprecated: bool}> Keyed by concept uri.
	 *
	 * @throws RuntimeException When the register is made unreadable.
	 */
	public function concepts(string $schemeUri): array {
		$this->reads[] = $schemeUri;
		if ($this->unreadable === true) {
			throw new RuntimeException('OpenRegister vocabulary unavailable: ' . $schemeUri);
		}

		if (isset(self::FIXTURES[$schemeUri]) === false || in_array($schemeUri, $this->empty, true) === true) {
			return [];
		}

		$nodes = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/tooi/' . self::FIXTURES[$schemeUri]), true);
		$concepts = [];
		foreach ($nodes as $node) {
			if (in_array(self::SKOS . 'Concept', (array)($node['@type'] ?? []), true) === false) {
				continue;
			}

			$uri = (string)$node['@id'];
			$concepts[$uri] = [
				'label' => self::firstValue(node: $node, property: 'prefLabel', fallback: $uri),
				'notation' => self::firstValue(node: $node, property: 'notation', fallback: ''),
				'deprecated' => in_array($uri, $this->deprecated, true),
			];
		}

		return $concepts;
	}//end concepts()

	/**
	 * The Dutch (or only) value of a SKOS property of a node.
	 *
	 * @param array<string, mixed> $node     The expanded JSON-LD node.
	 * @param string               $property The SKOS property's local name.
	 * @param string               $fallback What to answer without a value.
	 *
	 * @return string The value.
	 */
	private static function firstValue(array $node, string $property, string $fallback): string {
		$values = (array)($node[self::SKOS . $property] ?? []);
		foreach ($values as $value) {
			if (($value['@language'] ?? 'nl') === 'nl' && is_string($value['@value'] ?? null) === true) {
				return $value['@value'];
			}
		}

		return $fallback;
	}//end firstValue()
}//end class
