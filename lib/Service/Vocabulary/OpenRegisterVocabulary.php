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
 *
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
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
	 * OpenRegister's scope for app-initiated work without a user session.
	 *
	 * @var string
	 */
	public const SYSTEM_SCOPE = 'OCA\\OpenRegister\\Service\\SystemOperationContext';

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
		$repository = $this->service(name: self::CONCEPTS);
		$concepts = $this->asSystem(operation: static fn (): array => $repository->conceptsOf(schemeUri: $schemeUri));

		$uris = [];
		foreach ($concepts as $uri => $concept) {
			if (is_array($concept) === true && ($concept['deprecated'] ?? false) !== true) {
				$uris[] = (string)$uri;
			}
		}

		return $uris;
	}//end activeConceptUris()

	/**
	 * The concepts of a scheme with their Dutch label, notation and whether they are deprecated.
	 *
	 * The label is the Dutch `prefLabel`, else the first one filled, else the
	 * notation, else the uri, so every concept can be named in a pick list.
	 *
	 * @param string $schemeUri The scheme's uri.
	 *
	 * @return array<string, array{label: string, notation: string, deprecated: bool}> Keyed by concept uri.
	 *
	 * @throws RuntimeException When OpenRegister's vocabulary cannot be read.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
	 */
	public function concepts(string $schemeUri): array {
		$repository = $this->service(name: self::CONCEPTS);
		$concepts = $this->asSystem(operation: static fn (): array => $repository->conceptsOf(schemeUri: $schemeUri));

		$answer = [];
		foreach ($concepts as $uri => $concept) {
			if (is_array($concept) === false) {
				continue;
			}

			$notation = trim((string)($concept['notation'] ?? ''));
			$fallback = $notation;
			if ($fallback === '') {
				$fallback = (string)$uri;
			}

			$answer[(string)$uri] = [
				'label' => self::labelOf(labels: ($concept['prefLabel'] ?? null), fallback: $fallback),
				'notation' => $notation,
				'deprecated' => (($concept['deprecated'] ?? false) === true),
			];
		}

		return $answer;
	}//end concepts()

	/**
	 * The Dutch label of a `prefLabel` map, else its first filled label, else the fallback.
	 *
	 * @param mixed  $labels   The decoded `prefLabel`: a language map, or a plain string.
	 * @param string $fallback What names the concept without a label.
	 *
	 * @return string The label.
	 */
	private static function labelOf(mixed $labels, string $fallback): string {
		if (is_string($labels) === true && trim($labels) !== '') {
			return trim($labels);
		}

		if (is_array($labels) === false) {
			return $fallback;
		}

		$ordered = array_merge(['nl' => ($labels['nl'] ?? null)], $labels);
		foreach ($ordered as $label) {
			if (is_string($label) === true && trim($label) !== '') {
				return trim($label);
			}
		}

		return $fallback;
	}//end labelOf()

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
			$importer = $this->service(name: self::IMPORTER);
			return $this->asSystem(operation: static fn (): array => $importer->importJsonLd(jsonLd: $jsonLd));
		} catch (RuntimeException $e) {
			throw $e;
		} catch (Throwable $e) {
			throw new RuntimeException('The vocabulary import failed: ' . $e->getMessage(), 0, $e);
		}
	}//end import()

	/**
	 * Run an operation in OpenRegister's system scope.
	 *
	 * The refresh runs from cron, where there is no user: OpenRegister's RBAC
	 * refuses an anonymous write, and its read answers an empty scheme, which
	 * would blind the mass-deprecation guard. The scope is the one OpenRegister
	 * itself uses to seed this register, entered from code only and ended with
	 * the call.
	 *
	 * @param callable(): array<mixed> $operation The read or the import.
	 *
	 * @return array<mixed> Its answer.
	 */
	private function asSystem(callable $operation): array {
		if (class_exists(self::SYSTEM_SCOPE) === false) {
			return $operation();
		}

		return (self::SYSTEM_SCOPE)::run($operation);
	}//end asSystem()

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
