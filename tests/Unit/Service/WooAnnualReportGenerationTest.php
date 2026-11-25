<?php

/**
 * The woo-jaarverslag template generated through OpenRegister's report generation over a fixture year.
 *
 * @category Tests
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/woo-annual-report/specs/woo-compliance/spec.md#requirement-opencatalogi-declares-the-woo-annual-report-template-req-war-001
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters -- PHPUnit assertion and mock helpers take positional arguments.

namespace OCA\OpenCatalogi\Tests\Unit\Service;

use OCA\OpenCatalogi\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Runs the shipped template through OpenRegister's real ReportTemplateGenerator, with an
 * aggregation runner that evaluates each section's query over fixture objects.
 */
class WooAnnualReportGenerationTest extends TestCase {

	/**
	 * The OpenRegister classes this test drives.
	 *
	 * @var array<int, string>
	 */
	private const GENERATOR = [
		'OCA\OpenRegister\Search\Service\Reporting\ReportTemplateGenerator',
		'OCA\OpenRegister\Search\Service\Reporting\ReportSectionQuery',
		'OCA\OpenRegister\Search\Service\Reporting\ReportPeriod',
	];

	/**
	 * Fixture objects per schema.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function fixture(): array {
		return [
			'publication' => [
				['status' => 'published', 'wooCategory' => 'infocat012', 'publicationDate' => '2026-01-15T10:00:00+00:00', 'creationDate' => '2026-01-02'],
				['status' => 'published', 'wooCategory' => 'infocat012', 'publicationDate' => '2026-03-01T10:00:00+00:00', 'creationDate' => null],
				['status' => 'published', 'wooCategory' => 'infocat016', 'publicationDate' => '2026-03-20T10:00:00+00:00', 'creationDate' => '2026-03-01'],
				['status' => 'draft', 'wooCategory' => 'infocat016', 'publicationDate' => '2026-04-01T10:00:00+00:00', 'creationDate' => '2026-03-01'],
				['status' => 'published', 'wooCategory' => 'infocat001', 'publicationDate' => '2025-06-01T10:00:00+00:00', 'creationDate' => '2025-05-01'],
			],
			'depublication' => [
				['reason' => 'Fout in de bijlage', 'depublishedAt' => '2026-05-01T09:00:00+00:00'],
				['reason' => 'Fout in de bijlage', 'depublishedAt' => '2026-06-01T09:00:00+00:00'],
				['reason' => 'Persoonsgegevens', 'depublishedAt' => '2025-02-01T09:00:00+00:00'],
			],
		];
	}//end fixture()

	/**
	 * Whether one object passes a filter map of equality and operator conditions.
	 *
	 * @param array<string, mixed> $object The object.
	 * @param array<string, mixed> $filter The filter.
	 *
	 * @return bool
	 */
	private function admits(array $object, array $filter): bool {
		foreach ($filter as $field => $condition) {
			$value = ($object[$field] ?? null);
			if (is_array($condition) === false) {
				if ($value !== $condition) {
					return false;
				}

				continue;
			}

			foreach ($condition as $operator => $operand) {
				$day = substr((string)$value, 0, 10);
				$holds = match ($operator) {
					'isnull' => ($value === null) === (bool)$operand,
					'gte' => $value !== null && $day >= $operand,
					'lt' => $value !== null && $day < $operand,
					default => false,
				};
				if ($holds === false) {
					return false;
				}
			}
		}

		return true;
	}//end matches()

	/**
	 * Build OpenRegister's generator over the fixture.
	 *
	 * @param array<int, array<string, mixed>> $rendered The widgets the PDF writer received, written back.
	 *
	 * @return object
	 */
	private function generator(?array &$rendered): object {
		$runner = $this->createMock('OCA\OpenRegister\Service\Aggregation\AggregationRunnerInterface');
		$runner->method('findSchema')->willReturn(new \OCA\OpenRegister\Db\Schema());
		$runner->method('runAdhocByRef')->willReturnCallback(
			function (string $registerRef, string $schemaRef, object $query): array {
				$objects = array_values(array_filter($this->fixture()[$schemaRef], fn (array $o): bool => $this->admits($o, $query->filter)));
				if ($query->dateBucket !== null) {
					$groups = [];
					foreach ($objects as $object) {
						$day = substr((string)$object[$query->dateBucket['field']], 0, 10);
						if ($day < $query->dateBucket['start'] || $day >= $query->dateBucket['end']) {
							continue;
						}

						$key = substr((string)$object[$query->dateBucket['field']], 0, 7) . '-01T00:00:00Z';
						$groups[$key] = (($groups[$key] ?? 0) + 1);
					}

					return ['groups' => array_map(static fn ($k, $v) => ['key' => $k, 'value' => $v], array_keys($groups), $groups)];
				}

				if ($query->groupBy === null) {
					return ['value' => count($objects)];
				}

				$counts = array_count_values(array_map(static fn (array $o): string => (string)$o[$query->groupBy['field']], $objects));
				return ['groups' => array_map(static fn ($k, $v) => ['key' => (string)$k, 'value' => $v], array_keys($counts), $counts)];
			}
		);
		$validator = $this->createMock('OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidator');
		$validator->method('validate')->willReturnCallback(
			static fn (array $input): object => \OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery::create(
				metric: 'count',
				filter: $input['filter'],
				layout: new \OCA\OpenRegister\Search\Service\Aggregation\AggregationLayout(
					dateBucket: ['field' => $input['field'], 'start' => $input['from'], 'end' => $input['to'], 'gap' => $input['interval']]
				)
			)
		);
		$pdf = $this->createMock('OCA\OpenRegister\Search\Service\Reporting\PdfReportWriter');
		$pdf->method('write')->willReturnCallback(
			static function (array $dashboard, array $resolvedWidgets) use (&$rendered): string {
				$rendered = $resolvedWidgets;
				return '%PDF';
			}
		);
		$render = new \OCA\OpenRegister\Search\Service\Reporting\ReportRenderService(
			$runner,
			$this->createMock('OCA\OpenRegister\Search\Service\GraphQL\GraphQLService'),
			new \OCA\OpenRegister\Search\Service\Reporting\ReportWriters(
				$this->createMock('OCA\OpenRegister\Search\Service\Reporting\SpreadsheetReportWriter'),
				$this->createMock('OCA\OpenRegister\Search\Service\Reporting\HtmlReportWriter'),
				$pdf
			),
			$this->createMock(LoggerInterface::class)
		);

		return new \OCA\OpenRegister\Search\Service\Reporting\ReportTemplateGenerator(
			new \OCA\OpenRegister\Search\Service\Reporting\ReportSectionQuery(
				new \OCA\OpenRegister\Search\Controller\Support\AggregationControllerServices($runner, $validator)
			),
			$render
		);
	}//end generator()

	/**
	 * The shipped template's data, as OpenRegister stores it.
	 *
	 * @return array<string, mixed>
	 */
	private function template(): array {
		$root = dirname(__DIR__, 3) . '/lib/Settings';
		$base = json_decode((string)file_get_contents($root . '/publication_register.json'), true, flags: JSON_THROW_ON_ERROR);
		$merged = (new ReflectionMethod(SettingsService::class, 'mergeRegisterFragments'))->invoke(null, $base, $root . '/register.d');
		foreach ($merged['components']['objects'] as $object) {
			if (($object['@self']['slug'] ?? null) === 'woo-jaarverslag') {
				unset($object['@self']);
				return $object;
			}
		}

		$this->fail('No woo-jaarverslag seed is shipped.');
	}//end template()

	/**
	 * The sections' totals for 2026, with 2025 beside each.
	 *
	 * @return void
	 */
	public function testTheReportTotalsMatchTheFixture(): void {
		foreach (self::GENERATOR as $class) {
			if (class_exists($class) === false) {
				$this->markTestSkipped('OpenRegister\'s report generation (rapportage-bi-export report templates) is not installed beside this app.');
			}
		}

		$rendered = null;
		$this->generator($rendered)->generate(
			$this->template(),
			new \OCA\OpenRegister\Search\Service\Reporting\ReportPeriod('2026-01-01', '2026-12-31'),
			'pdf'
		);
		$byTitle = [];
		foreach ($rendered as $entry) {
			$byTitle[$entry['widget']['title'] . ' ' . $entry['widget']['subtitle']] = array_column($entry['data']['groups'], 'value', 'key');
		}

		$categories = $byTitle['Publicaties per informatiecategorie 2026'];
		$this->assertCount(18, $categories);
		$this->assertSame(2, $categories['infocat012']);
		$this->assertSame(1, $categories['infocat016']);
		$this->assertSame(0, $categories['infocat001']);
		$this->assertSame(1, $byTitle['Publicaties per informatiecategorie 2025']['infocat001']);

		$months = $byTitle['Publicaties per maand 2026'];
		$this->assertCount(12, $months);
		$this->assertSame([1, 0, 2], [$months['2026-01'], $months['2026-02'], $months['2026-03']]);

		$this->assertSame([3], array_values($byTitle['Publicaties in totaal 2026']));
		$this->assertSame([1], array_values($byTitle['Publicaties in totaal 2025']));
		$this->assertSame([1], array_values($byTitle['Publicaties zonder creatiedatum 2026']));
		$this->assertSame([0], array_values($byTitle['Publicaties zonder creatiedatum 2025']));
		$this->assertSame(['Fout in de bijlage' => 2], $byTitle['Intrekkingen per reden 2026']);
		$this->assertSame(['Persoonsgegevens' => 1], $byTitle['Intrekkingen per reden 2025']);
	}//end testTheReportTotalsMatchTheFixture()
}//end class
