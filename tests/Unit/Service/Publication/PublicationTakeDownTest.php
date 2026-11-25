<?php

/**
 * PublicationTakeDownTest
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
 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service\Publication;

use OCA\OpenCatalogi\Contract\PublicationStateServiceInterface;
use OCA\OpenCatalogi\Controller\PublicationTransitions;
use OCA\OpenCatalogi\Service\Publication\PublicationTakeDown;
use OCA\OpenCatalogi\Service\Publication\PublicationTakeDownRefused;
use OCA\OpenCatalogi\Service\Publication\PublicationTakeDownRequest;
use OCA\OpenCatalogi\Service\Publication\WithdrawalReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The withdraw button's path as a service, over a double of PublicationTransitions and OpenRegister's object service.
 *
 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
 */
#[CoversClass(PublicationTakeDown::class)]
#[CoversClass(PublicationTakeDownRequest::class)]
#[CoversClass(PublicationTakeDownRefused::class)]
#[CoversClass(WithdrawalReason::class)]
class PublicationTakeDownTest extends TestCase {

	/**
	 * The saves: object, register/schema and RBAC flag.
	 *
	 * @var list<array{object: array<string, mixed>, schema: string, uuid: ?string, rbac: bool}>
	 */
	public array $saves = [];

	/**
	 * Schemas whose save throws.
	 *
	 * @var list<string>
	 */
	public array $failing = [];

	/**
	 * The take-down over a publication in the given state.
	 *
	 * @param string $state The state stateOf() answers.
	 *
	 * @return PublicationTakeDown The service.
	 */
	private function takeDown(string $state): PublicationTakeDown {
		$objects = new class($this) {
			/**
			 * Constructor.
			 *
			 * @param PublicationTakeDownTest $test The test recording the saves.
			 */
			public function __construct(private PublicationTakeDownTest $test) {
			}

			/**
			 * @param array<string, mixed> $object  The object.
			 * @param mixed                $options The save options.
			 * @param bool                 $_rbac   Whether RBAC applies.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, mixed $options, bool $_rbac = true): array {
				$schema = (string)$options->target->schema;
				if (in_array($schema, $this->test->failing, true) === true) {
					throw new RuntimeException(message: 'refused');
				}

				$this->test->saves[] = ['object' => $object, 'schema' => $schema, 'uuid' => $options->uuid, 'rbac' => $_rbac];
				return ['id' => 'dep-1', 'object' => $object];
			}
		};

		$registers = $this->createMock(originalClassName: PublicationTransitions::class);
		$registers->method('stateOf')->willReturn($state);
		$registers->method('registerConfiguration')->willReturnCallback(
			static fn (string $registerKey, string $schemaKey): array => ['register' => '7', 'schema' => $schemaKey]
		);
		$registers->method('getObjectService')->willReturn($objects);
		$registers->method('withdrawnRecord')->willReturnCallback(
			static fn (array $publication): array => $publication + ['depublicationDate' => '2026-11-25T00:00:00+00:00']
		);
		$registers->method('channelsReached')->willReturn(['woo-index']);
		$registers->method('depublish')->willReturnCallback(
			static fn (array $withdrawal): array => [
				'publication' => $withdrawal['publication']['id'],
				'reason' => $withdrawal['reason'],
				'depublishedBy' => $withdrawal['depublishedBy'],
				'channels' => $withdrawal['channels'],
			]
		);
		$registers->method('storable')->willReturnArgument(0);
		$registers->method('outstandingChannels')->willReturn([]);

		return new PublicationTakeDown(registers: $registers);
	}//end takeDown()

	/**
	 * The request for one public publication.
	 *
	 * @param array<string, mixed> $tombstone The tombstone choice.
	 *
	 * @return PublicationTakeDownRequest The request.
	 */
	private static function request(array $tombstone = []): PublicationTakeDownRequest {
		return new PublicationTakeDownRequest(
			id: 'pub-1',
			properties: ['id' => 'pub-1', 'title' => 'Besluit', 'status' => 'published'],
			why: new WithdrawalReason(reason: 'Rechterlijke uitspraak', actor: 'publisher1', tombstone: $tombstone)
		);
	}//end request()

	/**
	 * A public publication is saved withdrawn with RBAC, every channel is told, and the depublication is stored as the
	 * system with the tombstone choice that is not empty.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 */
	public function testAPublicPublicationComesDownAndWhyIsStored(): void {
		$answer = $this->takeDown(state: PublicationStateServiceInterface::STATE_PUBLIC)->withdraw(
			request: self::request(tombstone: ['publicReason' => 'Op last van de rechter', 'tombstoneShowsTitle' => false])
		);

		$this->assertSame(expected: ['publication_schema', 'depublication_schema'], actual: array_column($this->saves, 'schema'));
		$this->assertSame(expected: [true, false], actual: array_column($this->saves, 'rbac'));
		$this->assertSame(expected: 'pub-1', actual: $this->saves[0]['uuid']);
		$this->assertArrayNotHasKey(key: 'id', array: $this->saves[0]['object']);
		$this->assertSame(
			expected: [
				'publication' => 'pub-1',
				'reason' => 'Rechterlijke uitspraak',
				'depublishedBy' => 'publisher1',
				'channels' => ['woo-index'],
				'publicReason' => 'Op last van de rechter',
			],
			actual: $this->saves[1]['object']
		);
		$this->assertSame(expected: 'withdrawn', actual: $answer['state']);
		$this->assertSame(expected: 'pub-1', actual: $answer['depublication']['publication']);
		$this->assertTrue(condition: $answer['complete']);
	}//end testAPublicPublicationComesDownAndWhyIsStored()

	/**
	 * A scheduled publication reached no channel; a draft or a withdrawn one is refused with its state, nothing saved.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 */
	public function testOnlyAPublicOrScheduledPublicationComesDown(): void {
		$this->takeDown(state: PublicationStateServiceInterface::STATE_SCHEDULED)->withdraw(request: self::request());
		$this->assertSame(expected: [], actual: $this->saves[1]['object']['channels']);

		$this->saves = [];
		foreach (['draft', PublicationStateServiceInterface::STATE_WITHDRAWN] as $state) {
			try {
				$this->takeDown(state: $state)->withdraw(request: self::request());
				$this->fail(message: 'refused expected for ' . $state);
			} catch (PublicationTakeDownRefused $e) {
				$this->assertSame(expected: PublicationTakeDownRefused::WRONG_STATE, actual: $e->reason);
				$this->assertSame(expected: $state, actual: $e->state);
			}
		}

		$this->assertSame(expected: [], actual: $this->saves);
	}//end testOnlyAPublicOrScheduledPublicationComesDown()

	/**
	 * A refused withdrawn record is `not-saved` with OpenRegister's reason; a failed record of why is `depublication-unstored`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 */
	public function testAFailedSaveSaysWhichStepFailed(): void {
		$cases = ['publication_schema' => PublicationTakeDownRefused::NOT_SAVED, 'depublication_schema' => PublicationTakeDownRefused::UNSTORED];
		foreach ($cases as $schema => $reason) {
			$this->failing = [$schema];
			try {
				$this->takeDown(state: PublicationStateServiceInterface::STATE_PUBLIC)->withdraw(request: self::request());
				$this->fail(message: 'refused expected for ' . $schema);
			} catch (PublicationTakeDownRefused $e) {
				$this->assertSame(expected: $reason, actual: $e->reason);
				$this->assertSame(expected: 'refused', actual: $e->getPrevious()?->getMessage());
			}
		}
	}//end testAFailedSaveSaysWhichStepFailed()
}//end class
