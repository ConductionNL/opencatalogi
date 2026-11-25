<?php

/**
 * OpenCatalogi's reach into OpenRegister's vocabulary register.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Vocabulary
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
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Vocabulary;

use OCA\OpenCatalogi\Contract\VocabularyRegisterInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * Resolves OpenRegister's importer and concept repository by name, so the app
 * loads without OpenRegister and an absent register is a refusal, not a crash.
 */
class OpenRegisterVocabulary implements VocabularyRegisterInterface {

	/**
	 * OpenRegister's importer contract.
	 *
	 * @var string
	 */
	public const IMPORTER = 'OCA\\OpenRegister\\Kernel\\Contracts\\VocabularyImporterInterface';

	/**
	 * OpenRegister's concept repository.
	 *
	 * @var string
	 */
	public const CONCEPTS = 'OCA\\OpenRegister\\Service\\Vocabulary\\ConceptRepository';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's classes.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The uris of the concepts of a scheme that are not deprecated.
	 *
	 * @param string $schemeUri The scheme's uri.
	 *
	 * @return array<int, string> The uris.
	 *
	 * @throws RuntimeException When OpenRegister's vocabulary cannot be read.
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
	 */
	public function activeConceptUris(string $schemeUri): array {
		$concepts = $this->service(name: self::CONCEPTS)->conceptsOf(schemeUri: $schemeUri);

		$uris = [];
		foreach ($concepts as $uri => $concept) {
			if (is_array($concept) === true && ($concept['deprecated'] ?? false) !== true) {
				$uris[] = (string)$uri;
			}
		}

		return $uris;
	}//end activeConceptUris()

	/**
	 * Import a SKOS JSON-LD document.
	 *
	 * @param array<string, mixed> $jsonLd The document.
	 *
	 * @return array{scheme: string, created: int, updated: int, unchanged: int, deprecated: int} The counts.
	 *
	 * @throws RuntimeException When OpenRegister's vocabulary cannot be written.
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
	 */
	public function import(array $jsonLd): array {
		try {
			return $this->service(name: self::IMPORTER)->importJsonLd(jsonLd: $jsonLd);
		} catch (RuntimeException $e) {
			throw $e;
		} catch (Throwable $e) {
			throw new RuntimeException('The vocabulary import failed: ' . $e->getMessage(), 0, $e);
		}
	}//end import()

	/**
	 * One of OpenRegister's services, or a refusal naming it.
	 *
	 * @param string $name The class or interface.
	 *
	 * @return object The service.
	 *
	 * @throws RuntimeException When OpenRegister does not provide it.
	 */
	private function service(string $name): object {
		try {
			$service = $this->container->get($name);
		} catch (Throwable $e) {
			throw new RuntimeException('OpenRegister vocabulary unavailable: ' . $name, 0, $e);
		}

		if (is_object($service) === false) {
			throw new RuntimeException('OpenRegister vocabulary unavailable: ' . $name);
		}

		return $service;
	}//end service()
}//end class
