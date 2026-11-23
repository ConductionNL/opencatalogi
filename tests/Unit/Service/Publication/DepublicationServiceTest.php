<?php

/**
 * DepublicationServiceTest
 *
 * A withdrawal freezes the publication with its internal reason, and a freeze
 * that fails leaves the withdrawal standing and says so (REQ-PWA-003).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-3.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service\Publication;

use OCA\OpenCatalogi\Service\Publication\DepublicationService;
use OCA\OpenCatalogi\Service\Publication\NationalIndexService;
use OCA\OpenCatalogi\Service\Publication\PublicationFreezer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Service\Publication\DepublicationService
 * @covers \OCA\OpenCatalogi\Service\Publication\PublicationFreezer
 */
final class DepublicationServiceTest extends TestCase {

	/**
	 * The freeze calls the archive handler received.
	 *
	 * @var array<int, array{identifier: string, reason: ?string}>
	 */
	private array $freezes = [];

	/**
	 * A depublication service whose archive handler records each freeze, or throws.
	 *
	 * @param \Throwable|null $failure What the handler throws, null to freeze.
	 *
	 * @return DepublicationService The service.
	 */
	private function service(?\Throwable $failure = null): DepublicationService {
		$handler = new class($this->freezes, $failure) {
			/**
			 * Constructor.
			 *
			 * @param array<int, array<string, mixed>> $freezes The calls, by reference.
			 * @param \Throwable|null $failure What to throw.
			 */
			public function __construct(private array &$freezes, private readonly ?\Throwable $failure) {
			}

			/**
			 * Records the freeze, with ArchiveHandler::freeze()'s signature and answer.
			 *
			 * @param string $identifier The object.
			 * @param string|null $reason Why.
			 * @param string|null $state The declaring state.
			 * @param string|null $register The register.
			 * @param string|null $schema The schema.
			 *
			 * @return array{uuid: string, frozen: array<string, mixed>} The marker.
			 */
			public function freeze(
				string $identifier,
				?string $reason = null,
				?string $state = null,
				?string $register = null,
				?string $schema = null,
			): array {
				if ($this->failure !== null) {
					throw $this->failure;
				}

				$this->freezes[] = ['identifier' => $identifier, 'reason' => $reason];

				return ['uuid' => $identifier, 'frozen' => ['by' => 'editor', 'reason' => $reason]];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(PublicationFreezer::ARCHIVE_HANDLER)->willReturn($handler);

		$index = $this->getMockBuilder(NationalIndexService::class)
			->disableOriginalConstructor()
			->onlyMethods(['withdraw'])
			->getMock();
		$index->method('withdraw')->willReturn(['acknowledgedAt' => '2026-11-23T10:00:00+00:00', 'answer' => 'ok']);

		$logger = $this->createMock(LoggerInterface::class);

		return new DepublicationService($index, $logger, new PublicationFreezer($container, $logger));
	}//end service()

	/**
	 * Withdrawing freezes the publication with the internal reason and reports it.
	 *
	 * @return void
	 */
	public function testWithdrawingFreezesThePublicationWithTheReason(): void {
		$depublication = $this->service()->depublish(
			publication: ['id' => 'pub-1'],
			reason: 'Verkeerde bijlage',
			depublishedBy: 'editor',
			channels: ['koop']
		);

		self::assertSame(expected: [['identifier' => 'pub-1', 'reason' => 'Verkeerde bijlage']], actual: $this->freezes);
		self::assertTrue(condition: $depublication['frozen']);
		self::assertArrayNotHasKey(key: 'frozenError', array: $depublication);
		self::assertSame(expected: 'Verkeerde bijlage', actual: $depublication['reason']);
		self::assertCount(expectedCount: 1, haystack: $depublication['withdrawals']);
	}//end testWithdrawingFreezesThePublicationWithTheReason()

	/**
	 * A freeze that throws keeps the withdrawal and answers frozen false with the error.
	 *
	 * @return void
	 */
	public function testAFailedFreezeKeepsTheWithdrawalAndSaysSo(): void {
		$depublication = $this->service(failure: new RuntimeException('No user logged in'))->depublish(
			publication: ['id' => 'pub-1'],
			reason: 'Verkeerde bijlage',
			depublishedBy: 'editor',
			channels: ['koop']
		);

		self::assertFalse(condition: $depublication['frozen']);
		self::assertSame(expected: 'No user logged in', actual: $depublication['frozenError']);
		self::assertSame(expected: 'pub-1', actual: $depublication['publication']);
		self::assertSame(expected: 'koop', actual: $depublication['withdrawals'][0]['channel']);
	}//end testAFailedFreezeKeepsTheWithdrawalAndSaysSo()

	/**
	 * One document coming down leaves the publication unfrozen and the depublication without the key.
	 *
	 * @return void
	 */
	public function testADocumentWithdrawalDoesNotFreezeThePublication(): void {
		$depublication = $this->service()->depublish(
			publication: ['id' => 'pub-1'],
			reason: 'Naam niet weggelakt',
			depublishedBy: 'editor',
			channels: [],
			freeze: false
		);

		self::assertSame(expected: [], actual: $this->freezes);
		self::assertArrayNotHasKey(key: 'frozen', array: $depublication);
	}//end testADocumentWithdrawalDoesNotFreezeThePublication()

	/**
	 * Without a reason nothing is frozen: the refusal comes first.
	 *
	 * @return void
	 */
	public function testNoReasonFreezesNothing(): void {
		try {
			$this->service()->depublish(publication: ['id' => 'pub-1'], reason: ' ', depublishedBy: 'editor', channels: []);
			self::fail(message: 'A depublication without a reason was accepted.');
		} catch (\DomainException) {
			self::assertSame(expected: [], actual: $this->freezes);
		}
	}//end testNoReasonFreezesNothing()
}//end class
