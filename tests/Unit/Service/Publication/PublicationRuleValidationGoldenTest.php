<?php

/**
 * Golden tests for which publication rules are refused, with every error and
 * in what order, and for what the preview answers for a refused rule.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every refusal of PublicationRuleService::validateRule().
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\PublicationRuleService
 */
class PublicationRuleValidationGoldenTest extends TestCase {

	/**
	 * Rules and the errors each gets.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, string>}>
	 */
	public static function rules(): array {
		$noType = 'A publication rule must name the record type it governs.';
		$noProperties = 'A publication rule must name the properties an anonymous reader may read, even if that is none.';
		$noCategory = 'A publication rule must name, in wooCategory, one of the Woo information categories this app serves.';
		return [
			'valid' => [['recordType' => 'besluit', 'wooCategory' => 'infocat002', 'anonymousProperties' => ['title'], 'conditions' => [['property' => 'status', 'operator' => 'equals']]], []],
			'empty' => [[], [$noType, $noProperties, $noCategory]],
			'blank type' => [['recordType' => '  ', 'wooCategory' => 'infocat002', 'anonymousProperties' => ['title']], [$noType]],
			'empty properties' => [['recordType' => 'besluit', 'wooCategory' => 'infocat002', 'anonymousProperties' => []], [$noProperties]],
			'properties not a list' => [['recordType' => 'besluit', 'wooCategory' => 'infocat002', 'anonymousProperties' => 'title'], [$noProperties]],
			'no category' => [['recordType' => 'besluit', 'anonymousProperties' => ['title']], [$noCategory]],
			'a category this app does not serve' => [['recordType' => 'besluit', 'wooCategory' => 'infocat099', 'anonymousProperties' => ['title']], [$noCategory]],
			'a category that is no string' => [['recordType' => 'besluit', 'wooCategory' => ['infocat002'], 'anonymousProperties' => ['title']], [$noCategory]],
			'every condition fault' => [
				[
					'recordType' => 'besluit',
					'wooCategory' => 'infocat002',
					'anonymousProperties' => ['title'],
					'conditions' => [
						'x' => 'not a condition',
						1 => ['operator' => 'equals'],
						2 => ['property' => 'status', 'operator' => 'like'],
						3 => ['property' => ' '],
						4 => ['property' => 'date', 'operator' => 'before'],
					],
				],
				[
					'Condition x cannot be read.',
					'Condition 1 names no property.',
					'Condition 2 uses an operator this app does not know: "like".',
					'Condition 3 names no property.',
					'Condition 3 uses an operator this app does not know: "".',
				],
			],
		];

	}//end rules()

	/**
	 * The errors of one rule, and the preview of a refused one.
	 *
	 * @param array<string, mixed> $rule The rule.
	 * @param array<int, string> $errors The errors.
	 *
	 * @return void
	 */
	#[DataProvider('rules')]
	public function testValidateRule(array $rule, array $errors): void {
		$service = new PublicationRuleService();
		$this->assertSame(['valid' => ($errors === []), 'errors' => $errors], $service->validateRule($rule));
		if ($errors === []) {
			return;
		}

		$this->assertSame(
			['valid' => false, 'errors' => $errors, 'wouldPublish' => [], 'wouldNotPublish' => 0, 'exposedProperties' => [], 'sampleSize' => 2],
			$service->preview($rule, [['title' => 'a'], ['title' => 'b']])
		);

	}//end testValidateRule()
}//end class
