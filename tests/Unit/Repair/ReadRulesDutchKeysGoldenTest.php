<?php

/**
 * Golden test for how the read-rule repair renames Dutch match keys.
 *
 * Pins WOO536RepairReadRules::translateDutchKeys() on the access rules it
 * rewrites: which conditions survive (and so still grant or restrict access)
 * under which key, which Dutch duplicates are dropped in favour of an English
 * key and how each drop is reported, and that every non-rule element is left
 * exactly as it was.
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

namespace Unit\Repair;

use OCA\OpenCatalogi\Repair\WOO536RepairReadRules;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Golden test for the Dutch match-key translation of the read-rule repair.
 */
class ReadRulesDutchKeysGoldenTest extends TestCase {

	/**
	 * Run translateDutchKeys() on one authorization block.
	 *
	 * @param array $authorization The authorization block
	 *
	 * @return array{authorization: array, dropped: string[]} The result and the dropped report
	 */
	private function translate(array $authorization): array {
		$step = new WOO536RepairReadRules(
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class)
		);
		$method = new ReflectionMethod(WOO536RepairReadRules::class, 'translateDutchKeys');
		$method->setAccessible(true);
		$dropped = ['earlier'];
		$result = $method->invokeArgs($step, [$authorization, &$dropped]);
		return ['authorization' => $result, 'dropped' => $dropped];

	}//end translate()

	/**
	 * A Dutch key becomes English, in place, keeping its condition and the
	 * order of the other keys; non-rule elements and non-list actions stay.
	 *
	 * @return void
	 */
	public function testTranslatesInPlaceAndLeavesTheRestAlone(): void {
		$authorization = [
			'inheritFromPublic' => true,
			'read' => [
				'authenticated',
				['group' => 'public', 'match' => ['status' => ['$eq' => 'x'], 'publicatiedatum' => ['$lte' => '$now'], 'depublicatiedatum' => ['$exists' => false]]],
				['group' => 'public'],
				['group' => 'public', 'match' => 'publicatiedatum'],
				['group' => 'staff', 'match' => []],
			],
			'update' => [['group' => 'redactie', 'match' => [0 => 'kept', 'depublicatiedatum' => 1]]],
			'delete' => 'admin',
		];

		$this->assertSame(
			[
				'authorization' => [
					'inheritFromPublic' => true,
					'read' => [
						'authenticated',
						['group' => 'public', 'match' => ['status' => ['$eq' => 'x'], 'publicationDate' => ['$lte' => '$now'], 'depublicationDate' => ['$exists' => false]]],
						['group' => 'public'],
						['group' => 'public', 'match' => 'publicatiedatum'],
						['group' => 'staff', 'match' => []],
					],
					'update' => [['group' => 'redactie', 'match' => [0 => 'kept', 'depublicationDate' => 1]]],
					'delete' => 'admin',
				],
				'dropped' => ['earlier'],
			],
			$this->translate($authorization)
		);

	}//end testTranslatesInPlaceAndLeavesTheRestAlone()

	/**
	 * An English key already present wins: the Dutch duplicate (and its
	 * condition) is dropped and reported as action[index].key, in order.
	 *
	 * @return void
	 */
	public function testEnglishKeyWinsAndTheDropIsReported(): void {
		$authorization = [
			'read' => [
				['group' => 'public', 'match' => ['publicatiedatum' => ['$lte' => '2020'], 'publicationDate' => ['$lte' => '$now'], 'depublicatiedatum' => ['$gte' => '$now']]],
				'authenticated',
				['group' => 'public', 'match' => ['depublicationDate' => ['$exists' => false], 'depublicatiedatum' => ['$exists' => true], 'publicatiedatum' => 1, 'publicationDate' => 2]],
			],
			'create' => [['group' => 'x', 'match' => ['depublicationDate' => 0, 'depublicatiedatum' => 9]]],
		];

		$this->assertSame(
			[
				'authorization' => [
					'read' => [
						['group' => 'public', 'match' => ['publicationDate' => ['$lte' => '$now'], 'depublicationDate' => ['$gte' => '$now']]],
						'authenticated',
						['group' => 'public', 'match' => ['depublicationDate' => ['$exists' => false], 'publicationDate' => 2]],
					],
					'create' => [['group' => 'x', 'match' => ['depublicationDate' => 0]]],
				],
				'dropped' => ['earlier', 'read[0].publicatiedatum', 'read[2].depublicatiedatum', 'read[2].publicatiedatum', 'create[0].depublicatiedatum'],
			],
			$this->translate($authorization)
		);

	}//end testEnglishKeyWinsAndTheDropIsReported()

	/**
	 * A block without Dutch keys comes back identical, so the caller sees no change.
	 *
	 * @return void
	 */
	public function testEnglishBlockIsUnchanged(): void {
		$authorization = [
			'read' => [['group' => 'public', 'match' => ['publicationDate' => ['$lte' => '$now']]], 'authenticated'],
			'update' => [],
		];

		$this->assertSame(['authorization' => $authorization, 'dropped' => ['earlier']], $this->translate($authorization));

	}//end testEnglishBlockIsUnchanged()
}//end class
