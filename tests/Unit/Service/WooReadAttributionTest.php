<?php

/**
 * Unit tests for WooReadAttribution.
 *
 * @category Test
 * @package  Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\WooReadAttribution;
use OCA\OpenRegister\Db\ProcessingLogEntry;
use OCA\OpenRegister\Db\ProcessingLogMapper;
use OCA\OpenRegister\Db\ProcessingLogReadScope;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Fallback-attributed reads of the five Woo schemas in the last 30 days (REQ-WAB-001).
 */
#[CoversClass(WooReadAttribution::class)]
class WooReadAttributionTest extends TestCase {

	/**
	 * The service over the given doubles.
	 *
	 * @param VerwerkingsactiviteitMapper $activities The activity mapper.
	 * @param ProcessingLogMapper|null    $log        The log mapper.
	 *
	 * @return WooReadAttribution The service.
	 */
	private function attribution(VerwerkingsactiviteitMapper $activities, ?ProcessingLogMapper $log = null): WooReadAttribution {
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			static function (string $slug): Schema {
				$schema = new Schema();
				$schema->setId(array_search($slug, WooReadAttribution::SCHEMAS, true) + 10);
				return $schema;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				'OCA\OpenRegister\Db\VerwerkingsactiviteitMapper' => $activities,
				'OCA\OpenRegister\Db\SchemaMapper' => $schemas,
				'OCA\OpenRegister\Db\ProcessingLogMapper' => ($log ?? $this->createMock(ProcessingLogMapper::class)),
			}
		);

		return new WooReadAttribution($container);

	}//end attribution()

	/**
	 * Entries under the fallback activity are counted per schema, within 30 days.
	 *
	 * @return void
	 */
	public function testTheFallbackEntriesOfTheFiveSchemasAreCounted(): void {
		$fallback = new Verwerkingsactiviteit();
		$fallback->setUuid('fallback-1');
		$activities = $this->createMock(VerwerkingsactiviteitMapper::class);
		$activities->method('findByCode')->with('niet-geclassificeerde-verwerking')->willReturn($fallback);

		$asked = [];
		$log   = $this->createMock(ProcessingLogMapper::class);
		$log->method('findListed')->willReturnCallback(
			function (ListQuery $query, ProcessingLogReadScope $scope) use (&$asked): array {
				$asked[] = $query->filters;
				$this->assertEqualsWithDelta((new \DateTime('-30 days'))->getTimestamp(), $scope->from->getTimestamp(), 60);
				return ($query->filters['schema_id'] === '10') ? [new ProcessingLogEntry(), new ProcessingLogEntry()] : [];
			}
		);

		$this->assertSame(['fallbackAttributedReads' => 2, 'days' => 30], $this->attribution($activities, $log)->report());
		$this->assertCount(5, $asked);
		$this->assertSame(['activity_id' => 'fallback-1', 'schema_id' => '10'], $asked[0]);

	}//end testTheFallbackEntriesOfTheFiveSchemasAreCounted()

	/**
	 * No fallback activity means nothing fell back; an unreadable log answers null.
	 *
	 * @return void
	 */
	public function testNoFallbackActivityIsZeroAndAnErrorIsNull(): void {
		$none = $this->createMock(VerwerkingsactiviteitMapper::class);
		$none->method('findByCode')->willReturn(null);
		$this->assertSame(0, $this->attribution($none)->report()['fallbackAttributedReads']);

		$broken = $this->createMock(VerwerkingsactiviteitMapper::class);
		$broken->method('findByCode')->willThrowException(new \RuntimeException('no table'));
		$this->assertNull($this->attribution($broken)->report()['fallbackAttributedReads']);

	}//end testNoFallbackActivityIsZeroAndAnErrorIsNull()
}//end class
