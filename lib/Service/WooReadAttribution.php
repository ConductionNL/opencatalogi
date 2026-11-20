<?php

/**
 * OpenCatalogi Woo read attribution: how many logged reads of Woo records fell back to the unclassified activity.
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
 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-reads-of-non-public-woo-records-are-logged-req-wab-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use DateTime;
use OCA\OpenRegister\Db\ProcessingLogReadScope;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use Psr\Container\ContainerInterface;

/**
 * Counts, for the readiness check, the processing log entries of the last 30
 * days on the five Woo schemas that OpenRegister attributed to its
 * unclassified fallback activity (REQ-WAB-001). A non-zero count means the
 * Woo activity was missing or retired when those reads happened.
 */
class WooReadAttribution {

	/**
	 * The schemas whose reads are logged.
	 */
	public const SCHEMAS = ['wooAssessment', 'wooBatch', 'depublication', 'wooRequest', 'publication'];

	/**
	 * OpenRegister's code for the unclassified fallback activity.
	 */
	public const FALLBACK_CODE = 'niet-geclassificeerde-verwerking';

	/**
	 * The window, in days.
	 */
	public const DAYS = 30;

	/**
	 * Entries read per page.
	 */
	private const PAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For OpenRegister's mappers.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {

	}//end __construct()

	/**
	 * The report fragment: the count and the window, or a null count when it cannot be read.
	 *
	 * @return array{fallbackAttributedReads: int|null, days: int}
	 *
	 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-reads-of-non-public-woo-records-are-logged-req-wab-001
	 */
	public function report(): array {
		return ['fallbackAttributedReads' => $this->fallbackCount(), 'days' => self::DAYS];

	}//end report()

	/**
	 * The count, null when OpenRegister's processing log cannot be read.
	 *
	 * @return int|null The count.
	 */
	private function fallbackCount(): ?int {
		try {
			$fallback = $this->container->get('OCA\OpenRegister\Db\VerwerkingsactiviteitMapper')->findByCode(code: self::FALLBACK_CODE);
			if ($fallback === null) {
				return 0;
			}

			$schemas = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
			$log     = $this->container->get('OCA\OpenRegister\Db\ProcessingLogMapper');
			$scope   = new ProcessingLogReadScope(from: new DateTime('-'.self::DAYS.' days'));
			$count   = 0;
			foreach (self::SCHEMAS as $slug) {
				$filters = ['activity_id' => (string) $fallback->getUuid(), 'schema_id' => (string) $schemas->find($slug)->getId()];
				$count  += $this->countListed(log: $log, filters: $filters, scope: $scope);
			}

			return $count;
		} catch (\Throwable $e) {
			return null;
		}

	}//end fallbackCount()

	/**
	 * Count the entries matching the filters, page by page.
	 *
	 * @param object                                      $log     The processing log mapper.
	 * @param array<string,string>                        $filters The filters.
	 * @param ProcessingLogReadScope                      $scope   The period.
	 *
	 * @return int The count.
	 */
	private function countListed(object $log, array $filters, object $scope): int {
		$count  = 0;
		$offset = 0;
		do {
			$page      = $log->findListed(new ListQuery(limit: self::PAGE, offset: $offset, filters: $filters), $scope);
			$pageCount = count($page);
			$count    += $pageCount;
			$offset   += self::PAGE;
		} while ($pageCount === self::PAGE);

		return $count;

	}//end countListed()
}//end class
