<?php

/**
 * Unit tests for EdepotHandover.
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

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenCatalogi\Service\EdepotHandover;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The e-depot side of an archive decision: starting a transfer and reading back how it ended.
 *
 * @covers \OCA\OpenCatalogi\Service\EdepotHandover
 */
class EdepotHandoverTest extends TestCase {

	private const AT = '2026-11-13T10:00:00+00:00';

	/**
	 * The fixed moment of every call.
	 *
	 * @return DateTimeImmutable The moment.
	 */
	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable(self::AT, new DateTimeZone('UTC'));
	}//end now()

	/**
	 * A handover over a container answering the given services by id.
	 *
	 * @param array<string, mixed> $services Services by container id; a Throwable is thrown.
	 *
	 * @return EdepotHandover The handover.
	 */
	private function handover(array $services): EdepotHandover {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static function (string $id) use ($services) {
			$service = ($services[$id] ?? new \RuntimeException('not installed'));
			if ($service instanceof \Throwable) {
				throw $service;
			}

			return $service;
		});

		return new EdepotHandover($container, $this->createMock(LoggerInterface::class));
	}//end handover()

	/**
	 * A publication object as OpenRegister's find() returns it.
	 *
	 * @return object The publication.
	 */
	private static function publication(): object {
		return new class {
			public function getUuid(): string {
				return 'pub-1';
			}
		};
	}//end publication()

	/**
	 * A transfer-list service that records what it was asked and answers or throws.
	 *
	 * @param array<string, mixed>|\Throwable $answer The list, or what to throw.
	 *
	 * @return object The service.
	 */
	private static function transferLists(array|\Throwable $answer): object {
		return new class($answer) {
			/** @var array<int, mixed> */
			public array $asked = [];

			public function __construct(private array|\Throwable $answer) {
			}

			public function createTransferList(array $objects): array {
				$this->asked[] = $objects;
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};
	}//end transferLists()

	/**
	 * A transfer-record service answering one list, or throwing.
	 *
	 * @param array<string, mixed>|\Throwable|null $list The list.
	 *
	 * @return object The service.
	 */
	private static function transferRecords(array|\Throwable|null $list): object {
		return new class($list) {
			/** @var array<int, string> */
			public array $loaded = [];

			public function __construct(private array|\Throwable|null $list) {
			}

			public function loadTransferList(string $uuid): ?array {
				$this->loaded[] = $uuid;
				if ($this->list instanceof \Throwable) {
					throw $this->list;
				}

				return $this->list;
			}
		};
	}//end transferRecords()

	/**
	 * A started transfer carries OpenRegister's reference with status requested.
	 *
	 * @return void
	 */
	public function testARequestStoresTheTransferReferenceAsRequested(): void {
		$lists = self::transferLists(['uuid' => 'tl-1', 'status' => 'in_review']);
		$publication = self::publication();

		$state = $this->handover([EdepotHandover::TRANSFER_LISTS => $lists])->request($publication, self::now());

		$this->assertSame(['reference' => 'tl-1', 'status' => 'requested', 'requestedAt' => self::AT, 'updatedAt' => self::AT], $state);
		$this->assertSame([[$publication]], $lists->asked);
	}//end testARequestStoresTheTransferReferenceAsRequested()

	/**
	 * Without OpenRegister's transfer service the transfer fails with that reason.
	 *
	 * @return void
	 */
	public function testARequestWithoutTheTransferServiceFails(): void {
		$state = $this->handover([])->request(self::publication(), self::now());
		$this->assertSame('failed', $state['status']);
		$this->assertSame('', $state['reference']);
		$this->assertSame('The e-depot transfer is not available in OpenRegister', $state['reason']);

		$state = $this->handover([EdepotHandover::TRANSFER_LISTS => new \stdClass()])->request(self::publication(), self::now());
		$this->assertSame('The e-depot transfer is not available in OpenRegister', $state['reason']);
	}//end testARequestWithoutTheTransferServiceFails()

	/**
	 * A publication that is not an OpenRegister object is not sent.
	 *
	 * @return void
	 */
	public function testAPublicationThatIsNotAnObjectIsNotSent(): void {
		$lists = self::transferLists(['uuid' => 'tl-1']);
		$state = $this->handover([EdepotHandover::TRANSFER_LISTS => $lists])->request(['id' => 'pub-1'], self::now());

		$this->assertSame('failed', $state['status']);
		$this->assertSame('The publication could not be read for the e-depot transfer', $state['reason']);
		$this->assertSame([], $lists->asked);
	}//end testAPublicationThatIsNotAnObjectIsNotSent()

	/**
	 * A refused or empty answer from OpenRegister fails the transfer with its reason.
	 *
	 * @return void
	 */
	public function testARefusedOrEmptyRequestFailsWithTheReason(): void {
		$refusing = self::transferLists(new \InvalidArgumentException('No objects provided for transfer list'));
		$state = $this->handover([EdepotHandover::TRANSFER_LISTS => $refusing])->request(self::publication(), self::now());
		$this->assertSame(['reference' => '', 'status' => 'failed', 'reason' => 'No objects provided for transfer list', 'requestedAt' => self::AT, 'updatedAt' => self::AT], $state);

		$empty = self::transferLists(['status' => 'in_review']);
		$state = $this->handover([EdepotHandover::TRANSFER_LISTS => $empty])->request(self::publication(), self::now());
		$this->assertSame('OpenRegister did not return a transfer reference', $state['reason']);
	}//end testARefusedOrEmptyRequestFailsWithTheReason()

	/**
	 * A completed list marks the transfer transferred; a running one changes nothing.
	 *
	 * @return void
	 */
	public function testACompletedListMarksTheTransferTransferred(): void {
		$requested = ['reference' => 'tl-1', 'status' => 'requested', 'requestedAt' => 'earlier', 'updatedAt' => 'earlier'];

		$records = self::transferRecords(['uuid' => 'tl-1', 'status' => 'completed']);
		$state = $this->handover([EdepotHandover::TRANSFER_RECORDS => $records])->refresh($requested, self::now());
		$this->assertSame(['reference' => 'tl-1', 'status' => 'transferred', 'requestedAt' => 'earlier', 'updatedAt' => self::AT], $state);
		$this->assertSame(['tl-1'], $records->loaded);

		foreach (['in_review', 'approved', 'in_progress', ''] as $running) {
			$records = self::transferRecords(['uuid' => 'tl-1', 'status' => $running]);
			$this->assertSame($requested, $this->handover([EdepotHandover::TRANSFER_RECORDS => $records])->refresh($requested, self::now()));
		}
	}//end testACompletedListMarksTheTransferTransferred()

	/**
	 * A failed or rejected list fails the transfer with the e-depot's reason.
	 *
	 * @return void
	 */
	public function testAFailedListGivesTheEdepotsReason(): void {
		$requested = ['reference' => 'tl-1', 'status' => 'requested', 'reason' => 'stale'];
		$cases = [
			'rejected by the archivist' => [['status' => 'rejected', 'approvalMetadata' => ['rejectionReason' => 'Not permanent']], 'Not permanent'],
			'package not built' => [['status' => 'failed', 'transferResult' => ['error' => 'SIP build failed: disk']], 'SIP build failed: disk'],
			'one object refused' => [['status' => 'partially_failed', 'transferResult' => ['objectResults' => ['a' => ['error' => null], 'b' => ['error' => 'Checksum mismatch']]]], 'Checksum mismatch'],
			'no reason given' => [['status' => 'failed', 'transferResult' => ['objectResults' => ['a' => 'x']]], 'The e-depot transfer ended with status failed'],
			'blank rejection' => [['status' => 'rejected', 'approvalMetadata' => ['rejectionReason' => ' '], 'transferResult' => 'x'], 'The e-depot transfer ended with status rejected'],
		];

		foreach ($cases as $name => [$list, $reason]) {
			$records = self::transferRecords($list);
			$state = $this->handover([EdepotHandover::TRANSFER_RECORDS => $records])->refresh($requested, self::now());
			$this->assertSame('failed', $state['status'], $name);
			$this->assertSame($reason, $state['reason'], $name);
			$this->assertSame(self::AT, $state['updatedAt'], $name);
		}
	}//end testAFailedListGivesTheEdepotsReason()

	/**
	 * An ended or reference-less transfer is not looked up; an unloadable list changes nothing.
	 *
	 * @return void
	 */
	public function testOnlyAPendingTransferIsLookedUp(): void {
		$records = self::transferRecords(['status' => 'failed']);
		$handover = $this->handover([EdepotHandover::TRANSFER_RECORDS => $records]);

		$ended = ['reference' => 'tl-1', 'status' => 'transferred'];
		$this->assertSame($ended, $handover->refresh($ended, self::now()));
		$noReference = ['reference' => null, 'status' => 'requested'];
		$this->assertSame($noReference, $handover->refresh($noReference, self::now()));
		$this->assertSame([], $records->loaded);

		$requested = ['reference' => 'tl-1', 'status' => 'requested'];
		$this->assertSame($requested, $this->handover([EdepotHandover::TRANSFER_RECORDS => self::transferRecords(null)])->refresh($requested, self::now()));
		$this->assertSame($requested, $this->handover([EdepotHandover::TRANSFER_RECORDS => self::transferRecords(new \RuntimeException('db'))])->refresh($requested, self::now()));
		$this->assertSame($requested, $this->handover([])->refresh($requested, self::now()));
	}//end testOnlyAPendingTransferIsLookedUp()

	/**
	 * A transfer is pending only while requested with a reference.
	 *
	 * @return void
	 */
	public function testIsPending(): void {
		$this->assertTrue(EdepotHandover::isPending(['reference' => 'tl-1', 'status' => 'requested']));
		$this->assertFalse(EdepotHandover::isPending(['reference' => '', 'status' => 'requested']));
		$this->assertFalse(EdepotHandover::isPending(['reference' => 'tl-1', 'status' => 'failed']));
		$this->assertFalse(EdepotHandover::isPending(null));
		$this->assertFalse(EdepotHandover::isPending('requested'));
	}//end testIsPending()

	/**
	 * Without a moment the request is stamped now.
	 *
	 * @return void
	 */
	public function testARequestWithoutAMomentIsStampedNow(): void {
		$before = time();
		$state = $this->handover([])->request(self::publication());
		$this->assertGreaterThanOrEqual($before - 1, strtotime($state['requestedAt']));
		$this->assertStringEndsWith('+00:00', $state['requestedAt']);
	}//end testARequestWithoutAMomentIsStampedNow()
}//end class
