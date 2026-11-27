<?php

/**
 * The woo-jaarverslag report template: every category, the creation-date line, and where it lands.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenCatalogi\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/woo-annual-report/specs/woo-compliance/spec.md#requirement-opencatalogi-declares-the-woo-annual-report-template-req-war-001
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters -- PHPUnit assertion and mock helpers take positional arguments.

namespace OCA\OpenCatalogi\Tests\Unit\Settings;

use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\TooiVocabularyService;
use OCA\OpenCatalogi\Service\Woo\LocalCategoryAdmission;
use OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The shipped template, read as OpenRegister imports it.
 */
class WooAnnualReportTemplateTest extends TestCase {

	/**
	 * The merged register configuration.
	 *
	 * @return array<string, mixed>
	 */
	private function merged(): array {
		$root = dirname(__DIR__, 3) . '/lib/Settings';
		$base = json_decode((string)file_get_contents($root . '/publication_register.json'), true, flags: JSON_THROW_ON_ERROR);

		$merge = new ReflectionMethod(SettingsService::class, 'mergeRegisterFragments');
		return $merge->invoke(null, $base, $root . '/register.d');
	}//end merged()

	/**
	 * The woo-jaarverslag seed object.
	 *
	 * @return array<string, mixed>
	 */
	private function template(): array {
		foreach (($this->merged()['components']['objects'] ?? []) as $object) {
			if (($object['@self']['slug'] ?? null) === 'woo-jaarverslag') {
				return $object;
			}
		}

		$this->fail('No woo-jaarverslag seed is shipped.');
	}//end template()

	/**
	 * One section of the template by its key.
	 *
	 * @param string $key The section key.
	 *
	 * @return array<string, mixed>
	 */
	private function section(string $key): array {
		foreach ($this->template()['sections'] as $section) {
			if ($section['key'] === $key) {
				return $section;
			}
		}

		$this->fail('No section ' . $key . '.');
	}//end section()

	/**
	 * Every category the registry knows has its own row, so a category without publications reads 0.
	 *
	 * @return void
	 */
	public function testEveryRegistryCategoryHasARowIncludingZero(): void {
		$settings = $this->createMock(SettingsService::class);
		$registry = new WooCategoryRegistry(
			\Unit\Support\RecordedTooiRegister::service(),
			$settings,
			$this->createMock(LoggerInterface::class),
			new LocalCategoryAdmission(\Unit\Support\RecordedTooiRegister::service())
		);

		$section = $this->section('per-category');

		$this->assertSame('grouped', $section['type']);
		$this->assertSame('wooCategory', $section['groupBy']);
		$this->assertSame(array_keys($registry->all()), $section['rows']);
		$this->assertCount(18, $section['rows']);
		$this->assertTrue($section['compareWithPreviousYear']);
	}//end testEveryRegistryCategoryHasARowIncludingZero()

	/**
	 * A publication without a creation date is counted on its own line, never timed from the save moment.
	 *
	 * @return void
	 */
	public function testAPublicationWithoutCreationDateIsCountedApart(): void {
		$section = $this->section('no-creation-date');

		$this->assertSame('total', $section['type']);
		$this->assertSame(['creationDate' => ['isnull' => true]], $section['filters']);
		$this->assertSame('publicationDate', $this->template()['dateField']);
		$this->assertStringNotContainsString('created', json_encode($this->template()['sections']));
	}//end testAPublicationWithoutCreationDateIsCountedApart()

	/**
	 * The seed names OpenRegister's register and schema and declares neither, so an
	 * OpenRegister without report templates skips it and nothing in OpenCatalogi adopts it.
	 *
	 * @return void
	 */
	public function testTheSeedIsSkippedWithoutTheReportSchema(): void {
		$merged = $this->merged();

		$this->assertSame(
			['register' => 'report-templates', 'schema' => 'report-templates', 'slug' => 'woo-jaarverslag'],
			$this->template()['@self']
		);
		$this->assertArrayNotHasKey('report-templates', $merged['components']['schemas']);
		$this->assertArrayNotHasKey('report-templates', $merged['components']['registers']);
	}//end testTheSeedIsSkippedWithoutTheReportSchema()

	/**
	 * Every shipped section is admitted by OpenRegister's report-templates schema.
	 *
	 * @return void
	 */
	public function testTheSeedIsAdmittedByTheReportTemplatesSchema(): void {
		$template = $this->template();
		unset($template['@self']);
		$schema = (string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/openregister/report-templates.schema.json');

		$result = (new Validator())->validate(json_decode((string)json_encode($template)), $schema);

		$this->assertTrue($result->isValid(), (string)json_encode($result->error()?->info()));
	}//end testTheSeedIsAdmittedByTheReportTemplatesSchema()

	/**
	 * The template counts public publications by their publication date, withdrawals by when they were taken down.
	 *
	 * @return void
	 */
	public function testItCountsPublishedPublicationsAndWithdrawalsPerReason(): void {
		$template = $this->template();
		$withdrawals = $this->section('withdrawals');

		$this->assertSame(['register' => 'publication', 'schema' => 'publication', 'filters' => ['status' => 'published']], $template['dataSource']);
		$this->assertSame(['depublication', 'depublishedAt', 'reason'], [$withdrawals['schema'], $withdrawals['dateField'], $withdrawals['groupBy']]);
		$this->assertArrayNotHasKey('filters', $withdrawals, 'a section on its own schema does not take the publication filters');
		$this->assertSame('month', $this->section('per-month')['interval']);
		$this->assertSame(['pdf', 'ods', 'csv'], $template['formats']);
		$this->assertStringContainsString('dossiq', $template['description']);
	}//end testItCountsPublishedPublicationsAndWithdrawalsPerReason()
}//end class
