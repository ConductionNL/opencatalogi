<?php

/**
 * Golden test for the whole depublication DepublicationService::depublish()
 * records: each channel once, the moment it was taken down, what every
 * channel answered or why it was not reached, and the warning for the latter.
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

use DateTimeImmutable;
use OCA\OpenCatalogi\Service\Publication\DepublicationService;
use OCA\OpenCatalogi\Service\Publication\IndexUnreachableException;
use OCA\OpenCatalogi\Service\Publication\NationalIndexService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenCatalogi\Service\Publication\DepublicationService
 */
class DepublicationGoldenTest extends TestCase {

	/**
	 * Every channel once, in the order given, each with its answer or why not.
	 *
	 * @return void
	 */
	public function testTheWholeDepublication(): void {
		$asked = [];
		$index = $this->getMockBuilder(NationalIndexService::class)->disableOriginalConstructor()->onlyMethods(['withdraw'])->getMock();
		$index->method('withdraw')->willReturnCallback(
			static function (string $channel, string $publicationId, string $reason) use (&$asked): array {
				$asked[] = [$channel, $publicationId, $reason];
				if ($channel === 'plooi') {
					throw new IndexUnreachableException(message: 'no source');
				}

				return ($channel === 'woo-index' ? ['acknowledgedAt' => '2026-03-01T10:00:00+00:00', 'answer' => 'ok'] : []);
			}
		);
		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$depublication = (new DepublicationService($index, $logger))->depublish(
			publication: ['id' => 7, 'title' => 'Besluit'],
			reason: 'published in error',
			depublishedBy: 'admin',
			channels: ['woo-index', 'plooi', 'woo-index', 3, 'local'],
			now: new DateTimeImmutable('2026-03-01T11:00:00+01:00')
		);

		$this->assertSame(
			[
				'publication' => '7',
				'reason' => 'published in error',
				'depublishedBy' => 'admin',
				'depublishedAt' => '2026-03-01T11:00:00+01:00',
				'withdrawals' => [
					['channel' => 'woo-index', 'sentAt' => '2026-03-01T11:00:00+01:00', 'acknowledgedAt' => '2026-03-01T10:00:00+00:00', 'answer' => 'ok'],
					['channel' => 'plooi', 'sentAt' => '2026-03-01T11:00:00+01:00', 'acknowledgedAt' => null, 'answer' => 'not delivered: no source'],
					['channel' => '3', 'sentAt' => '2026-03-01T11:00:00+01:00', 'acknowledgedAt' => null, 'answer' => null],
					['channel' => 'local', 'sentAt' => '2026-03-01T11:00:00+01:00', 'acknowledgedAt' => null, 'answer' => null],
				],
			],
			$depublication
		);
		$this->assertSame(
			[['woo-index', '7', 'published in error'], ['plooi', '7', 'published in error'], ['3', '7', 'published in error'], ['local', '7', 'published in error']],
			$asked
		);
		$this->assertSame(['[DepublicationService] The withdrawal to "plooi" was not delivered: no source'], $warnings);

	}//end testTheWholeDepublication()
}//end class
