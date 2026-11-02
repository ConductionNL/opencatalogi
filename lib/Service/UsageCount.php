<?php

/**
 * One view or download of a publication, on its way to the daily counter.
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
 *
 * @spec openspec/specs/publication-usage-analytics/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenCatalogi\Contract\UsageCounterServiceInterface;

/**
 * The publication, kind, day and optional catalog label of one count, and the
 * counter store {@see UsageCounterService::increment()} writes it to.
 *
 * It holds no request-derived attribute (ANA-002).
 *
 * @spec openspec/specs/publication-usage-analytics/spec.md
 */
final class UsageCount {

	/**
	 * The day counted, Y-m-d in UTC.
	 *
	 * @var string
	 */
	public readonly string $date;

	/**
	 * The catalog slug, a roll-up label only.
	 *
	 * @var string|null
	 */
	public ?string $catalog = null;

	/**
	 * The counter register id.
	 *
	 * @var string
	 */
	public string $register = '';

	/**
	 * The counter schema id.
	 *
	 * @var string
	 */
	public string $schema = '';

	/**
	 * The OpenRegister object service the counter is read and saved through.
	 *
	 * @var mixed
	 */
	public mixed $objectService = null;

	/**
	 * A count of today.
	 *
	 * @param string $publicationId The publication UUID.
	 * @param string $kind KIND_VIEW or KIND_DOWNLOAD.
	 *
	 * @spec openspec/specs/publication-usage-analytics/spec.md
	 */
	public function __construct(
		public readonly string $publicationId,
		public readonly string $kind,
	) {
		$this->date = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
	}//end __construct()

	/**
	 * Whether the count names a publication and a known kind.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/publication-usage-analytics/spec.md
	 */
	public function isCountable(): bool {
		return $this->publicationId !== ''
			&& in_array(
				$this->kind,
				[UsageCounterServiceInterface::KIND_VIEW, UsageCounterServiceInterface::KIND_DOWNLOAD],
				true
			);
	}//end isCountable()

	/**
	 * The search for today's counter of this publication and kind.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/publication-usage-analytics/spec.md
	 */
	public function counterQuery(): array {
		return [
			'@self' => [
				'register' => $this->register,
				'schema' => $this->schema,
			],
			'publication' => $this->publicationId,
			'date' => $this->date,
			'kind' => $this->kind,
		];
	}//end counterQuery()

	/**
	 * Today's first counter: publication, catalog label, day, kind and a count of 1.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/publication-usage-analytics/spec.md
	 */
	public function firstCounter(): array {
		return [
			'publication' => $this->publicationId,
			'catalog' => ($this->catalog ?? ''),
			'date' => $this->date,
			'kind' => $this->kind,
			'count' => 1,
		];
	}//end firstCounter()
}//end class
