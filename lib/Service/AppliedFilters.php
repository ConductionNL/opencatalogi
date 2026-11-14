<?php

/**
 * Collects the filters the server applied to a public list that the caller did not ask for.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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

namespace OCA\OpenCatalogi\Service;

use InvalidArgumentException;
use Throwable;

/**
 * Request-scoped collector behind the `appliedFilters` key of every public list
 * response (REQ-ASF-001, REQ-ASF-002).
 *
 * Each filter site records itself where it applies the filter, so a filter
 * cannot run without being reported. The controllers read the entries with
 * {@see AppliedFilters::forResponse()}, which answers the single `unknown`
 * entry when the collector is missing or fails, never an empty list that
 * reads as "nothing filtered".
 *
 * @spec openspec/changes/api-says-what-it-filtered/specs/search/spec.md
 */
class AppliedFilters {

	/**
	 * The configured catalogue scope narrowed the query.
	 */
	public const CATALOG_SCOPE = 'catalogScope';

	/**
	 * The query ran as the anonymous public reader.
	 */
	public const PUBLIC_ONLY = 'publicOnly';

	/**
	 * Rows with `status: archived` were dropped (RET-006).
	 */
	public const ARCHIVED_EXCLUDED = 'archivedExcluded';

	/**
	 * Caller-supplied register or schema scope keys were removed.
	 */
	public const CALLER_SCOPE_IGNORED = 'callerScopeIgnored';

	/**
	 * The names a filter site may record. The names are stable API.
	 *
	 * @var array<string>
	 */
	public const NAMES = [
		self::CATALOG_SCOPE,
		self::PUBLIC_ONLY,
		self::ARCHIVED_EXCLUDED,
		self::CALLER_SCOPE_IGNORED,
	];

	/**
	 * The entry answered when the filters could not be read.
	 *
	 * @var array{name: string, value: null, reason: string}
	 */
	public const UNKNOWN = [
		'name' => 'unknown',
		'value' => null,
		'reason' => 'not recorded',
	];

	/**
	 * The recorded entries, keyed by name, in the order first recorded.
	 *
	 * @var array<string, array{name: string, value: mixed, reason: string}>
	 */
	private array $entries = [];

	/**
	 * Record that a filter applied. A second record of the same name replaces the value.
	 *
	 * @param string $name One of {@see AppliedFilters::NAMES}.
	 * @param mixed $value What the filter applied (see the spec's table).
	 * @param string $reason A short English sentence.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the name is not one of the stable names.
	 *
	 * @spec openspec/changes/api-says-what-it-filtered/specs/search/spec.md
	 */
	public function record(string $name, mixed $value, string $reason): void {
		if (in_array($name, self::NAMES, true) === false) {
			throw new InvalidArgumentException('Unknown applied filter name: ' . $name);
		}

		$this->entries[$name] = [
			'name' => $name,
			'value' => $value,
			'reason' => $reason,
		];
	}//end record()

	/**
	 * Add to the count of a counting filter, recording it at 1 the first time.
	 *
	 * @param string $name One of {@see AppliedFilters::NAMES}.
	 * @param string $reason A short English sentence.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the name is not one of the stable names.
	 *
	 * @spec openspec/changes/api-says-what-it-filtered/specs/search/spec.md
	 */
	public function increment(string $name, string $reason): void {
		$count = 0;
		if (isset($this->entries[$name]) === true && is_int($this->entries[$name]['value']) === true) {
			$count = $this->entries[$name]['value'];
		}

		$this->record(name: $name, value: ($count + 1), reason: $reason);
	}//end increment()

	/**
	 * The recorded entries, each name once, in the order first recorded.
	 *
	 * @return array<int, array{name: string, value: mixed, reason: string}>
	 *
	 * @spec openspec/changes/api-says-what-it-filtered/specs/search/spec.md
	 */
	public function all(): array {
		return array_values($this->entries);
	}//end all()

	/**
	 * The entries for a response: the collector's, or the single `unknown` entry
	 * when there is no collector or reading it fails (fail closed).
	 *
	 * @param AppliedFilters|null $appliedFilters The request's collector.
	 *
	 * @return array<int, array{name: string, value: mixed, reason: string}>
	 *
	 * @spec openspec/changes/api-says-what-it-filtered/specs/search/spec.md
	 */
	public static function forResponse(?AppliedFilters $appliedFilters): array {
		if ($appliedFilters === null) {
			return [self::UNKNOWN];
		}

		try {
			return $appliedFilters->all();
		} catch (Throwable) {
			return [self::UNKNOWN];
		}
	}//end forResponse()

}//end class
