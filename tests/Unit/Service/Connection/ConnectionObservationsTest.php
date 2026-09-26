<?php

/**
 * What each sync, broadcast and readiness outcome says about its connection.
 *
 * @category Tests
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Connection
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
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
 */

declare(strict_types=1);

namespace Unit\Service\Connection;

use OCA\OpenCatalogi\Service\Connection\ConnectionObservations;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the pure outcome mapper.
 *
 * @covers \OCA\OpenCatalogi\Service\Connection\ConnectionObservations
 */
class ConnectionObservationsTest extends TestCase {

	/**
	 * The mapper under test.
	 *
	 * @var ConnectionObservations
	 */
	private ConnectionObservations $observations;

	/**
	 * Build the mapper.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->observations = new ConnectionObservations();
	}//end setUp()

	/**
	 * A sync result in the shape DirectoryService::doCronSync() returns.
	 *
	 * @param int                $synced The directories that answered.
	 * @param array<int, string> $failed The directory URLs that did not.
	 *
	 * @return array<string, mixed>
	 */
	private function sync(int $synced, array $failed = []): array {
		return [
			'total_directories'  => ($synced + count($failed)),
			'synced_directories' => $synced,
			'failed_directories' => count($failed),
			'errors'             => array_map(
				static fn (string $url): array => ['directory' => $url, 'error' => 'cURL error 6 for ' . $url],
				$failed
			),
		];
	}//end sync()

	/**
	 * Every directory answering reads configured, one or many.
	 *
	 * @return void
	 */
	public function testASyncWhereEveryDirectoryAnsweredIsConfigured(): void {
		$this->assertSame(['configured', 'The directory answered the last sync.'], $this->observations->directorySync($this->sync(1)));
		$this->assertSame(['configured', 'All 3 directories answered the last sync.'], $this->observations->directorySync($this->sync(3)));
	}//end testASyncWhereEveryDirectoryAnsweredIsConfigured()

	/**
	 * Some directories answering reads limited, naming the first failing host and nothing else of its URL.
	 *
	 * @return void
	 */
	public function testASyncWhereSomeDirectoriesFailedIsLimitedAndNamesTheHostOnly(): void {
		[$status, $message] = $this->observations->directorySync(
			$this->sync(1, ['https://peer.example.nl/apps/opencatalogi/api/directory?token=secret'])
		);

		$this->assertSame('limited', $status);
		$this->assertSame('1 of 2 directories answered the last sync. peer.example.nl did not.', $message);
		$this->assertStringNotContainsString('token', $message);
		$this->assertStringNotContainsString('/apps/', $message);
	}//end testASyncWhereSomeDirectoriesFailedIsLimitedAndNamesTheHostOnly()

	/**
	 * No directory answering reads error.
	 *
	 * @return void
	 */
	public function testASyncWhereNoDirectoryAnsweredIsAnError(): void {
		$this->assertSame(
			['error', 'No directory answered the last sync. directory.opencatalogi.nl did not.'],
			$this->observations->directorySync($this->sync(0, ['https://directory.opencatalogi.nl/apps/opencatalogi/api/directory']))
		);
		$this->assertSame(
			['error', 'No directory answered the last sync. A directory did not.'],
			$this->observations->directorySync($this->sync(0, ['not a url']))
		);
	}//end testASyncWhereNoDirectoryAnsweredIsAnError()

	/**
	 * A sync with nothing to read says so.
	 *
	 * @return void
	 */
	public function testASyncWithoutDirectoriesIsUnconfigured(): void {
		$this->assertSame('unconfigured', $this->observations->directorySync([])[0]);
	}//end testASyncWithoutDirectoriesIsUnconfigured()

	/**
	 * A broadcast every peer accepted reads configured.
	 *
	 * @return void
	 */
	public function testABroadcastEveryPeerAcceptedIsConfigured(): void {
		$this->assertSame(
			['configured', 'peer.example.nl accepted the last broadcast.'],
			$this->observations->broadcast(['https://peer.example.nl/apps/opencatalogi/api/directory' => true])
		);
		$this->assertSame(
			['configured', 'All 2 peer directories accepted the last broadcast.'],
			$this->observations->broadcast(['https://a.example.nl/x' => true, 'https://b.example.nl/y' => true])
		);
	}//end testABroadcastEveryPeerAcceptedIsConfigured()

	/**
	 * A broadcast some peers refused reads limited; one all refused reads error.
	 *
	 * @return void
	 */
	public function testABroadcastSomePeersRefusedIsLimitedAndAllIsAnError(): void {
		$this->assertSame(
			['limited', '1 of 2 peer directories accepted the last broadcast. b.example.nl did not.'],
			$this->observations->broadcast(['https://a.example.nl/x' => true, 'https://b.example.nl/y?key=1' => false])
		);
		$this->assertSame(
			['error', 'No peer directory accepted the last broadcast. a.example.nl did not.'],
			$this->observations->broadcast(['https://a.example.nl/x' => false, 'https://b.example.nl/y' => false])
		);
		$this->assertSame(
			['error', 'b.example.nl did not accept the last broadcast.'],
			$this->observations->broadcast(['https://b.example.nl/y' => false])
		);
	}//end testABroadcastSomePeersRefusedIsLimitedAndAllIsAnError()

	/**
	 * A broadcast with nobody to tell, or from a local address, reads unconfigured.
	 *
	 * @return void
	 */
	public function testABroadcastWithNobodyToTellOrFromALocalAddressIsUnconfigured(): void {
		$this->assertSame('unconfigured', $this->observations->broadcast([])[0]);

		[$status, $message] = $this->observations->broadcastFromLocalAddress();
		$this->assertSame('unconfigured', $status);
		$this->assertStringContainsString('overwrite.cli.url', $message);
	}//end testABroadcastWithNobodyToTellOrFromALocalAddressIsUnconfigured()

	/**
	 * A readiness report in the shape WooReadinessService::runCheck() returns.
	 *
	 * @param array<int, string>        $statuses     The status of each non-registration check.
	 * @param array<string, mixed>|null $registration The registration check, or null to leave it out.
	 *
	 * @return array<string, mixed>
	 */
	private function readiness(array $statuses, ?array $registration): array {
		$checks = [];
		foreach ($statuses as $index => $status) {
			$checks[] = ['id' => 'check-' . $index, 'status' => $status];
		}

		if ($registration !== null) {
			$checks[] = (['id' => 'registration'] + $registration);
		}

		return ['verdict' => 'ignored', 'checks' => $checks];
	}//end readiness()

	/**
	 * Every check passing with a matching registration reads configured.
	 *
	 * @return void
	 */
	public function testAReadinessCheckThatPassedWithAMatchingRegistrationIsConfigured(): void {
		$this->assertSame(
			'configured',
			$this->observations->wooReadiness($this->readiness(['pass', 'pass'], ['status' => 'pass']))[0]
		);
	}//end testAReadinessCheckThatPassedWithAMatchingRegistrationIsConfigured()

	/**
	 * Every check passing without a confirmed registration reads limited, each for its own reason.
	 *
	 * @return void
	 */
	public function testAReadinessCheckThatPassedWithoutAConfirmedRegistrationIsLimited(): void {
		$cases = [
			'not-registered'       => 'not registered with the Woo-index yet',
			'registration-pending' => 'requested and not confirmed yet',
			'url-mismatch'         => 'does not match this address',
		];
		foreach ($cases as $reason => $says) {
			$status = 'skipped';
			if ($reason === 'url-mismatch') {
				$status = 'fail';
			}

			[$reported, $message] = $this->observations->wooReadiness(
				$this->readiness(['pass', 'skipped'], ['status' => $status, 'reason' => $reason])
			);
			$this->assertSame('limited', $reported, $reason);
			$this->assertStringContainsString($says, $message, $reason);
		}
	}//end testAReadinessCheckThatPassedWithoutAConfirmedRegistrationIsLimited()

	/**
	 * Some failing checks read limited, and no passing check reads error.
	 *
	 * @return void
	 */
	public function testAReadinessCheckWithFailuresIsLimitedOrAnError(): void {
		$this->assertSame(
			['limited', '1 of 3 readiness checks failed. The Woo-index section lists which.'],
			$this->observations->wooReadiness($this->readiness(['pass', 'fail', 'pass', 'skipped'], ['status' => 'pass']))
		);
		$this->assertSame(
			'error',
			$this->observations->wooReadiness($this->readiness(['fail', 'skipped', 'skipped'], ['status' => 'pass']))[0]
		);
	}//end testAReadinessCheckWithFailuresIsLimitedOrAnError()

	/**
	 * The two refusals of a readiness check each say what happened.
	 *
	 * @return void
	 */
	public function testAReadinessCheckThatCouldNotRunSaysWhy(): void {
		$this->assertSame('unconfigured', $this->observations->wooNotConfigured()[0]);
		$this->assertSame('error', $this->observations->wooCheckStopped()[0]);
	}//end testAReadinessCheckThatCouldNotRunSaysWhy()

	/**
	 * Every status any mapping answers is one integriq accepts, and no message carries an em-dash.
	 *
	 * @return void
	 */
	public function testEveryAnswerIsAStatusIntegriqAccepts(): void {
		$answers = [
			$this->observations->directorySync([]),
			$this->observations->directorySync($this->sync(2, ['https://x.example.nl'])),
			$this->observations->broadcast(['https://x.example.nl' => true]),
			$this->observations->broadcastFromLocalAddress(),
			$this->observations->wooReadiness($this->readiness([], null)),
			$this->observations->wooNotConfigured(),
			$this->observations->wooCheckStopped(),
		];

		foreach ($answers as [$status, $message]) {
			$this->assertContains($status, ['configured', 'limited', 'unconfigured', 'simulated', 'unavailable', 'error']);
			$this->assertStringNotContainsString("\u{2014}", $message);
			$this->assertNotSame('', $message);
		}
	}//end testEveryAnswerIsAStatusIntegriqAccepts()
}//end class
