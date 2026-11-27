<?php

/**
 * The values a redaction removed from a document, kept in memory so the copy
 * that is published can be verified again.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/published-file-carries-its-facts/tasks.md#task-2.2b
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use JsonSerializable;

/**
 * What OpenRegister's verifier checks a redacted copy against: the values
 * that were redacted, read from the assessment's redaction log, and the
 * output mode the redaction wrote. The values never leave this object by
 * accident: serialised or dumped it shows how many there are, not which.
 *
 * @spec openspec/changes/published-file-carries-its-facts/tasks.md#task-2.2b
 */
final class RedactedValues implements JsonSerializable {

	/**
	 * The distinct non-empty values.
	 *
	 * @var array<int, string>
	 */
	private array $values = [];

	/**
	 * The output mode the redaction wrote, or an empty string when unknown.
	 *
	 * @var string
	 */
	private string $outputMode = '';

	/**
	 * Read the values from a redaction log and the output mode from a stored verdict.
	 *
	 * @param mixed $redactionLog The assessment's `redactionLog`: entries with a `value`.
	 * @param mixed $verification The assessment's `redactionVerification`, for its `outputMode`.
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/tasks.md#task-2.2b
	 */
	public function __construct(mixed $redactionLog, mixed $verification) {
		$values = [];
		foreach ((array)$redactionLog as $entry) {
			$value = '';
			if (is_array($entry) === true) {
				$value = ($entry['value'] ?? '');
			}

			if (is_string($value) === true && $value !== '') {
				$values[$value] = $value;
			}
		}

		$this->values = array_values($values);
		$mode = '';
		if (is_array($verification) === true) {
			$mode = ($verification['outputMode'] ?? '');
		}

		if (is_string($mode) === true) {
			$this->outputMode = $mode;
		}
	}//end __construct()

	/**
	 * The values that must not be recoverable.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/tasks.md#task-2.2b
	 */
	public function values(): array {
		return $this->values;
	}//end values()

	/**
	 * The output mode, or an empty string when unknown.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/tasks.md#task-2.2b
	 */
	public function outputMode(): string {
		return $this->outputMode;
	}//end outputMode()

	/**
	 * How many values there are, never which.
	 *
	 * @return array{values: int, outputMode: string}
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/tasks.md#task-2.2b
	 */
	public function jsonSerialize(): array {
		return ['values' => count($this->values), 'outputMode' => $this->outputMode];
	}//end jsonSerialize()

	/**
	 * What var_dump() and print_r() show: the same as the JSON form.
	 *
	 * @return array{values: int, outputMode: string}
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/tasks.md#task-2.2b
	 */
	public function __debugInfo(): array {
		return $this->jsonSerialize();
	}//end __debugInfo()
}//end class
