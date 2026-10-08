<?php

/**
 * OpenCatalogi Published Collections Service.
 *
 * Which collections publish, and under what conditions, is configuration
 * rather than code, so a collection is added to the published set on a running
 * instance and its records publish from that moment without a release.
 *
 * A configuration that cannot be read is refused rather than defaulted. An
 * unreadable configuration read as "publish nothing" silently stops a
 * statutory publication; read as "publish everything" it publishes what nobody
 * approved. Neither is a default worth having.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-which-collections-are-published-and-on-what-conditions-is-configured-req-pin-108
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use OCP\IAppConfig;

/**
 * Reads and writes the published-collection configuration.
 *
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-which-collections-are-published-and-on-what-conditions-is-configured-req-pin-108
 */
class PublishedCollectionsService {

	/**
	 * The config key the published set lives under.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'published_collections';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config App configuration.
	 * @param PublicationRuleService $ruleService The rule evaluator.
	 * @param string $appName The app name.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly PublicationRuleService $ruleService,
		private readonly string $appName = 'opencatalogi',
	) {

	}//end __construct()

	/**
	 * The configured published collections.
	 *
	 * @return array<int, array<string, mixed>> The collections.
	 *
	 * @throws UnreadableRuleException When the stored configuration is not readable JSON.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-which-collections-are-published-and-on-what-conditions-is-configured-req-pin-108
	 */
	public function collections(): array {
		$stored = trim($this->config->getValueString($this->appName, self::CONFIG_KEY, ''));
		if ($stored === '') {
			return [];
		}

		$decoded = json_decode($stored, true);
		if (is_array($decoded) === false) {
			throw new UnreadableRuleException(
				message: 'The published-collection configuration cannot be read, so this app will not guess which collections publish.'
			);
		}

		return array_values(array_filter($decoded, 'is_array'));

	}//end collections()

	/**
	 * Save the published collections, after checking every rule on them.
	 *
	 * @param array<int, array<string, mixed>> $collections The collections.
	 *
	 * @return array{saved: boolean, errors: array<int, string>}
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-which-collections-are-published-and-on-what-conditions-is-configured-req-pin-108
	 */
	public function save(array $collections): array {
		$errors = [];

		foreach ($collections as $index => $collection) {
			if (is_array($collection) === false) {
				$errors[] = 'Collection ' . $index . ' cannot be read.';
				continue;
			}

			$validation = $this->ruleService->validateRule(
				rule: [
					'recordType' => ($collection['recordType'] ?? ''),
					'anonymousProperties' => ($collection['anonymousProperties'] ?? []),
					'conditions' => ($collection['conditions'] ?? []),
				]
			);

			foreach ($validation['errors'] as $error) {
				$errors[] = 'Collection ' . $index . ': ' . $error;
			}
		}

		if ($errors !== []) {
			return ['saved' => false, 'errors' => $errors];
		}

		$this->config->setValueString($this->appName, self::CONFIG_KEY, (string)json_encode(array_values($collections)));

		return ['saved' => true, 'errors' => []];

	}//end save()

	/**
	 * Whether a record publishes under the configured set.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return boolean True when a configured collection publishes it.
	 *
	 * @throws UnreadableRuleException When the configuration or a condition cannot be read.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-which-collections-are-published-and-on-what-conditions-is-configured-req-pin-108
	 */
	public function publishes(array $record): bool {
		foreach ($this->collections() as $collection) {
			$collection['enabled'] = true;
			if ($this->ruleService->publishes(record: $record, rule: $collection) === true) {
				return true;
			}
		}

		return false;

	}//end publishes()
}//end class
