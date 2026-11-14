<?php

/**
 * Unit tests for the e-depot handover of RetentionService (REQ-CRR-003).
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

namespace Unit\Service;

use OCA\OpenCatalogi\Service\EdepotHandover;
use OCA\OpenCatalogi\Service\RetentionService;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An archive decision starts an e-depot transfer, and the daily pass records how it ended.
 *
 * @covers \OCA\OpenCatalogi\Service\RetentionService
 * @uses \OCA\OpenCatalogi\Service\EdepotHandover
 * @uses \OCA\OpenCatalogi\Service\RetentionRules
 * @uses \OCA\OpenCatalogi\Service\ReadsObjectProperties
 * @uses \OCA\OpenCatalogi\Service\RetentionPolicyTable
 * @uses \OCA\OpenCatalogi\Service\RetentionRun
 */
class RetentionEdepotTransferTest extends TestCase {

	/**
	 * The OpenRegister stand-in: objects, transfer lists and transfer records.
	 *
	 * @var object
	 */
	private object $openRegister;

	/**
	 * Build the stand-in and the service over it.
	 *
	 * @param array<int, mixed> $archived The archived publications the search answers.
	 * @param array<string, array<string, mixed>> $lists Transfer lists by uuid.
	 * @param bool $searchFails Whether the archived search throws.
	 *
	 * @return RetentionService The service.
	 */
	private function service(array $archived = [], array $lists = [], bool $searchFails = false): RetentionService {
		$this->openRegister = new class($archived, $lists, $searchFails) {
			/** @var array<int, mixed> */
			public array $calls = [];

			public function __construct(public array $archived, public array $lists, public bool $searchFails) {
			}

			public function find(string $id): object {
				$this->calls[] = ['find' => $id];
				return new class($id) {
					public function __construct(private string $id) {
					}

					public function getUuid(): string {
						return $this->id;
					}

					public function jsonSerialize(): array {
						return ['id' => $this->id, 'title' => 'Besluit', 'status' => 'published'];
					}
				};
			}

			public function saveObject(array $object, ?string $register = null, ?string $schema = null, string $uuid = '', bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls[] = ['save' => $object, 'uuid' => $uuid];
				return $object;
			}

			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls[] = ['search' => $query];
				if ($this->searchFails === true) {
					throw new \RuntimeException('index down');
				}

				return ['results' => $this->archived];
			}

			public function createTransferList(array $objects): array {
				$this->calls[] = ['transfer' => array_map(static fn ($o) => $o->getUuid(), $objects)];
				return ['uuid' => 'tl-1', 'status' => 'in_review'];
			}

			public function loadTransferList(string $uuid): ?array {
				return ($this->lists[$uuid] ?? null);
			}
		};

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'publication_register' => 'pub-reg',
				'publication_schema' => 'pub-sch',
				default => $default,
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->openRegister);
		$logger = $this->createMock(LoggerInterface::class);

		return new RetentionService($config, $container, $this->createMock(IUserSession::class), $logger, edepot: new EdepotHandover($container, $logger));
	}//end service()

	/**
	 * The calls of one kind made to the stand-in.
	 *
	 * @param string $kind The call kind.
	 *
	 * @return array<int, mixed> The calls.
	 */
	private function calls(string $kind): array {
		return array_values(array_filter($this->openRegister->calls, static fn (array $call) => array_key_exists($kind, $call)));
	}//end calls()

	/**
	 * An archive decision starts a transfer of the publication and stores its reference as requested.
	 *
	 * @return void
	 */
	public function testAnArchiveDecisionStartsATransfer(): void {
		$saved = $this->service()->recordHumanDecision('p1', 'archive', 'Permanent keeping');

		$this->assertSame('archived', $saved['status']);
		$this->assertSame('tl-1', $saved['edepotTransfer']['reference']);
		$this->assertSame('requested', $saved['edepotTransfer']['status']);
		$this->assertSame([['transfer' => ['p1']]], $this->calls('transfer'));
		$this->assertSame('requested', $this->calls('save')[0]['save']['edepotTransfer']['status']);
	}//end testAnArchiveDecisionStartsATransfer()

	/**
	 * Other decisions start no transfer.
	 *
	 * @return void
	 */
	public function testOtherDecisionsStartNoTransfer(): void {
		$saved = $this->service()->recordHumanDecision('p1', 'depublish', 'Withdrawn');

		$this->assertArrayNotHasKey('edepotTransfer', $saved);
		$this->assertSame([], $this->calls('transfer'));
	}//end testOtherDecisionsStartNoTransfer()

	/**
	 * The daily pass saves an ended transfer; the publication stays archived.
	 *
	 * @return void
	 */
	public function testTheDailyPassRecordsAnEndedTransfer(): void {
		$archived = [
			['id' => 'a', 'status' => 'archived', 'edepotTransfer' => ['reference' => 'tl-a', 'status' => 'requested']],
			['id' => 'b', 'status' => 'archived', 'edepotTransfer' => ['reference' => 'tl-b', 'status' => 'requested']],
			['id' => 'c', 'status' => 'archived', 'edepotTransfer' => ['reference' => 'tl-c', 'status' => 'requested']],
			['id' => 'd', 'status' => 'archived', 'edepotTransfer' => ['reference' => 'tl-d', 'status' => 'transferred']],
			['id' => 'e', 'status' => 'archived'],
		];
		$lists = [
			'tl-a' => ['status' => 'completed'],
			'tl-b' => ['status' => 'rejected', 'approvalMetadata' => ['rejectionReason' => 'Not on the selection list']],
			'tl-c' => ['status' => 'in_progress'],
		];

		$this->assertSame(2, $this->service($archived, $lists)->refreshEdepotTransfers());

		$saves = $this->calls('save');
		$this->assertCount(2, $saves);
		$this->assertSame('a', $saves[0]['uuid']);
		$this->assertSame('transferred', $saves[0]['save']['edepotTransfer']['status']);
		$this->assertSame('b', $saves[1]['uuid']);
		$this->assertSame('archived', $saves[1]['save']['status']);
		$this->assertSame('failed', $saves[1]['save']['edepotTransfer']['status']);
		$this->assertSame('Not on the selection list', $saves[1]['save']['edepotTransfer']['reason']);
		$this->assertSame(
			['@self' => ['register' => 'pub-reg', 'schema' => 'pub-sch'], 'status' => 'archived', '_limit' => 1000],
			$this->calls('search')[0]['search']
		);
	}//end testTheDailyPassRecordsAnEndedTransfer()

	/**
	 * A failing search updates nothing.
	 *
	 * @return void
	 */
	public function testAFailingSearchUpdatesNothing(): void {
		$this->assertSame(0, $this->service(searchFails: true)->refreshEdepotTransfers());
		$this->assertSame([], $this->calls('save'));
	}//end testAFailingSearchUpdatesNothing()

	/**
	 * Without a configured register the pass does nothing.
	 *
	 * @return void
	 */
	public function testWithoutConfigurationThePassDoesNothing(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$service = new RetentionService($config, $this->createMock(ContainerInterface::class), $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class));

		$this->assertSame(0, $service->refreshEdepotTransfers());
	}//end testWithoutConfigurationThePassDoesNothing()
}//end class
