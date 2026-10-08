<?php

/**
 * Tests for the Woo request intake the portal delivers through.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Woo
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
 * @spec openspec/changes/portal-woo-request-intake/specs/woo-request-intake/spec.md#requirement-a-woo-request-delivered-by-the-portal-arms-its-term-req-wri-008
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service\Woo;

use OCA\OpenCatalogi\Portal\PortalContributionProvider;
use OCA\OpenCatalogi\Service\Woo\StatutoryTerm;
use OCA\OpenCatalogi\Service\Woo\TermEngineUnavailableException;
use OCA\OpenCatalogi\Service\Woo\WooRequestIntake;
use OCA\OpenCatalogi\Service\Woo\WooRequestService;
use OCA\OpenCatalogi\Service\Woo\WooRequestStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A Woo request the portal hands over is minted here and its term is armed.
 *
 * @covers \OCA\OpenCatalogi\Service\Woo\WooRequestIntake
 * @covers \OCA\OpenCatalogi\Portal\PortalContributionProvider
 * @covers \OCA\OpenCatalogi\Service\Woo\WooRequestService
 */
class WooRequestIntakeTest extends TestCase {

	/**
	 * What the store was asked to save, in order.
	 *
	 * @var array<int, array{record: array<string, mixed>, uuid: string}>
	 */
	private array $saved = [];

	/**
	 * The term double.
	 *
	 * @var StatutoryTerm&MockObject
	 */
	private StatutoryTerm $terms;

	/**
	 * The intake under test.
	 *
	 * @var WooRequestIntake
	 */
	private WooRequestIntake $intake;

	/**
	 * Build the intake over a store that echoes and a mocked term.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->saved = [];
		$store = $this->getMockBuilder(WooRequestStore::class)
			->disableOriginalConstructor()
			->onlyMethods(['save'])
			->getMock();
		$store->method('save')->willReturnCallback(
			function (array $record, string $uuid = ''): array {
				$this->saved[] = ['record' => $record, 'uuid' => $uuid];
				$record['id'] = 'req-1';
				return $record;
			}
		);

		$this->terms = $this->getMockBuilder(StatutoryTerm::class)
			->disableOriginalConstructor()
			->onlyMethods(['arm'])
			->getMock();

		$this->intake = new WooRequestIntake(new WooRequestService(), $store, $this->terms);

	}//end setUp()

	/**
	 * An armed request answers with its own minted reference and the due date.
	 *
	 * @return void
	 */
	public function testARequestFromThePortalIsMintedAndItsTermArmed(): void {
		$this->terms->expects($this->once())
			->method('arm')
			->with($this->equalTo('req-1'), $this->stringStartsWith('WOO-2026-'))
			->willReturn(['timer' => 'timer-1', 'dueAt' => '2026-11-02T09:00:00+00:00', 'extensionCount' => 0, 'basis' => 'Woo art. 4.4 lid 1']);

		$result = $this->intake->receive(
			answers: ['requestedInformation' => 'Alle stukken over de Stationsweg.', 'requesterEmail' => 'a@example.org', 'reference' => 'MINE-1'],
			receivedAt: '2026-10-05T09:00:00+00:00'
		);

		$this->assertSame(WooRequestIntake::OUTCOME_ARMED, $result['outcome']);
		$this->assertSame('req-1', $result['requestId']);
		$this->assertMatchesRegularExpression('/^WOO-2026-[0-9A-F]{6}$/', $result['reference']);
		$this->assertSame('2026-11-02T09:00:00+00:00', $result['dueAt']);
		// The caller's own reference is never taken over.
		$this->assertNotSame('MINE-1', $result['reference']);
		// The term is stored on the request.
		$this->assertCount(2, $this->saved);
		$this->assertSame('timer-1', $this->saved[1]['record']['termTimer']);
		$this->assertSame('req-1', $this->saved[1]['uuid']);
		// The term counts from when the citizen sent it, not from delivery.
		$this->assertSame('2026-10-05T09:00:00+00:00', $this->saved[0]['record']['receivedAt']);
		$this->assertSame('web', $this->saved[0]['record']['channel']);
		$this->assertSame('portaliq', $this->saved[0]['record']['receivedBy']);

	}//end testARequestFromThePortalIsMintedAndItsTermArmed()

	/**
	 * A term that cannot be armed is never reported as armed.
	 *
	 * @return void
	 */
	public function testATermThatCannotBeArmedIsNotArmed(): void {
		$this->terms->method('arm')->willThrowException(new TermEngineUnavailableException('OpenRegister has no timer engine.'));

		$result = $this->intake->receive(answers: ['requestedInformation' => 'Iets.']);

		$this->assertSame(WooRequestIntake::OUTCOME_NOT_ARMED, $result['outcome']);
		$this->assertSame('req-1', $result['requestId']);
		$this->assertSame('', $result['dueAt']);
		$this->assertSame('OpenRegister has no timer engine.', $result['message']);

	}//end testATermThatCannotBeArmedIsNotArmed()

	/**
	 * An armed term without a due date still reads as not armed.
	 *
	 * @return void
	 */
	public function testATermWithoutADueDateIsNotArmed(): void {
		$this->terms->method('arm')->willReturn(['timer' => 'timer-1', 'extensionCount' => 0]);

		$result = $this->intake->receive(answers: ['requestedInformation' => 'Iets.'], receivedAt: 'not a date');

		$this->assertSame(WooRequestIntake::OUTCOME_NOT_ARMED, $result['outcome']);
		$this->assertSame('', $result['dueAt']);

	}//end testATermWithoutADueDateIsNotArmed()

	/**
	 * A request for nothing is refused and nothing is stored.
	 *
	 * @return void
	 */
	public function testARequestForNothingIsRefusedAndNotStored(): void {
		$this->terms->expects($this->never())->method('arm');

		$result = $this->intake->receive(answers: []);

		$this->assertSame(WooRequestIntake::OUTCOME_REFUSED, $result['outcome']);
		$this->assertSame([], $this->saved);

	}//end testARequestForNothingIsRefusedAndNotStored()

	/**
	 * A register that cannot store the request is unavailable, not armed.
	 *
	 * @return void
	 */
	public function testARegisterThatCannotStoreIsUnavailable(): void {
		$store = $this->getMockBuilder(WooRequestStore::class)
			->disableOriginalConstructor()
			->onlyMethods(['save'])
			->getMock();
		$store->method('save')->willThrowException(new RuntimeException('woo_request_register is not set'));
		$this->terms->expects($this->never())->method('arm');

		$result = (new WooRequestIntake(new WooRequestService(), $store, $this->terms))->receive(answers: ['requestedInformation' => 'Iets.']);

		$this->assertSame(WooRequestIntake::OUTCOME_UNAVAILABLE, $result['outcome']);
		$this->assertSame('woo_request_register is not set', $result['message']);

	}//end testARegisterThatCannotStoreIsUnavailable()

	/**
	 * The portal reaches the intake through the provider it already locates.
	 *
	 * @return void
	 */
	public function testThePortalProviderHandsTheRequestToTheIntake(): void {
		$this->terms->method('arm')->willReturn(['timer' => 'timer-1', 'dueAt' => '2026-11-02T09:00:00+00:00']);

		$result = (new PortalContributionProvider(null, $this->intake))->receiveWooRequest(['requestedInformation' => 'Iets.']);

		$this->assertSame(WooRequestIntake::OUTCOME_ARMED, $result['outcome']);

	}//end testThePortalProviderHandsTheRequestToTheIntake()

	/**
	 * A provider built without the intake says so rather than pretending.
	 *
	 * @return void
	 */
	public function testAProviderWithoutTheIntakeIsUnavailable(): void {
		$result = (new PortalContributionProvider())->receiveWooRequest(['requestedInformation' => 'Iets.']);

		$this->assertSame(WooRequestIntake::OUTCOME_UNAVAILABLE, $result['outcome']);
		$this->assertSame('', $result['dueAt']);

	}//end testAProviderWithoutTheIntakeIsUnavailable()
}//end class
