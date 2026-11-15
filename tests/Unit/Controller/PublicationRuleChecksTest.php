<?php

/**
 * Unit tests for PublicationRuleChecks.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
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

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\DecisionPublicationValidatorInterface;
use OCA\OpenCatalogi\Contract\PublicationRuleServiceInterface;
use OCA\OpenCatalogi\Controller\PublicationRuleChecks;
use PHPUnit\Framework\TestCase;

/**
 * The public search hands the rule service only the array entries, as a list.
 *
 * @covers \OCA\OpenCatalogi\Controller\PublicationRuleChecks
 */
class PublicationRuleChecksTest extends TestCase {

	/**
	 * Records and rules that are not arrays never reach the rule service.
	 *
	 * @return void
	 */
	public function testThePublicSearchPassesOnlyArrayEntries(): void {
		$rule = ['recordType' => 'besluit'];
		$record = ['@type' => 'besluit'];
		$service = $this->createMock(PublicationRuleServiceInterface::class);
		$service->expects($this->once())->method('indexByRecordType')
			->with([$rule])
			->willReturn(['besluit' => $rule]);
		$service->expects($this->once())->method('searchPublic')
			->with([$record], ['besluit' => $rule], 'woord')
			->willReturn([['title' => 'x']]);

		$checks = new PublicationRuleChecks($service, $this->createMock(DecisionPublicationValidatorInterface::class));

		$this->assertSame(
			[['title' => 'x']],
			$checks->searchPublic(query: ['records' => ['junk', 3 => $record], 'rules' => [null, 5 => $rule], 'terms' => 'woord'])
		);

	}//end testThePublicSearchPassesOnlyArrayEntries()
}//end class
