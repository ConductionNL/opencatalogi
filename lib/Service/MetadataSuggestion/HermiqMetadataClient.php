<?php

/**
 * OpenCatalogi Hermiq metadata client
 *
 * Asks Hermiq to propose values for a publication's missing Woo fields from the
 * text of its documents, through Hermiq's form-fill contract.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\MetadataSuggestion;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hermiq's proposals for missing Woo fields (design D3).
 *
 * Hermiq is optional: its contract is named by string and resolved through the
 * container, so this app stays installable without it. Any failure to reach it
 * answers "unavailable", never an error to the page.
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-hermiq-may-add-suggestions-never-replace-them-req-wms-002
 */
class HermiqMetadataClient {

	/**
	 * The Hermiq app id.
	 *
	 * @var string
	 */
	public const APP = 'hermiq';

	/**
	 * Hermiq's contract that proposes form field values from text.
	 *
	 * @var string
	 */
	public const ENGINE = 'OCA\\Hermiq\\Kernel\\Contracts\\FormFillEngineInterface';

	/**
	 * The language the publication's fields are written in.
	 *
	 * @var string
	 */
	private const LANGUAGE = 'nl';

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager Answers whether Hermiq is installed.
	 * @param ContainerInterface $container Resolves Hermiq's contract.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether Hermiq can be asked now: installed, its contract present and its feature enabled.
	 *
	 * @return bool True only when every one of those holds.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-hermiq-may-add-suggestions-never-replace-them-req-wms-002
	 */
	public function isAvailable(): bool {
		$engine = $this->engine();
		if ($engine === null) {
			return false;
		}

		try {
			return $engine->isAvailable() === true;
		} catch (Throwable $e) {
			$this->logger->warning('[HermiqMetadataClient] Hermiq could not say whether it is available: ' . $e->getMessage());
			return false;
		}
	}//end isAvailable()

	/**
	 * Hermiq's proposed values for the fields, from the text.
	 *
	 * @param string $text The text of the publication's documents.
	 * @param array<int, array{key: string, label: string, type: string, options: array<int, string>}> $fields The fields to propose for.
	 * @param string $userId The editor who asked.
	 *
	 * @return array<string, mixed>|null The values by field key, or null when Hermiq could not be asked or declined.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-hermiq-may-add-suggestions-never-replace-them-req-wms-002
	 */
	public function propose(string $text, array $fields, string $userId): ?array {
		$engine = $this->engine();
		if ($engine === null) {
			return null;
		}

		try {
			$answer = $engine->fill(text: $text, fields: $fields, language: self::LANGUAGE, userId: $userId);
		} catch (Throwable $e) {
			$this->logger->warning('[HermiqMetadataClient] Hermiq could not propose values: ' . $e->getMessage());
			return null;
		}

		if (is_array($answer) === false || ($answer['available'] ?? false) !== true || is_array($answer['values'] ?? null) === false) {
			return null;
		}

		return $answer['values'];
	}//end propose()

	/**
	 * Hermiq's form-fill engine, or null when Hermiq is not installed or does not ship it.
	 *
	 * @return object|null The engine.
	 */
	private function engine(): ?object {
		if ($this->appManager->isInstalled(self::APP) === false) {
			return null;
		}

		try {
			$engine = $this->container->get(self::ENGINE);
		} catch (Throwable $e) {
			$this->logger->warning('[HermiqMetadataClient] Hermiq\'s engine could not be resolved: ' . $e->getMessage());
			return null;
		}

		if (is_object($engine) === false) {
			return null;
		}

		return $engine;
	}//end engine()
}//end class
