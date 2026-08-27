<?php

/**
 * Unit tests for FederationHostPolicy.
 *
 * @category Tests
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Federation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service\Federation;

use OCA\OpenCatalogi\Service\Federation\FederationHostPolicy;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The one place that decides whether a federation URL is reachable by a peer.
 *
 * WHY EVERY BRANCH IS EXERCISED HERE RATHER THAN THROUGH BroadcastService.
 *
 * This class answers a single question with no side effects, and the cost of
 * getting it wrong is asymmetric: a false "remote" publishes an unroutable
 * address into a production directory, where it is unresolvable for every peer
 * and nothing errors. A false "local" merely declines to federate, which is
 * visible and recoverable. So the negative cases are worth stating one by one.
 */
class FederationHostPolicyTest extends TestCase {
	/**
	 * A policy whose allowlist is empty — the production default.
	 *
	 * @return FederationHostPolicy The policy under test.
	 */
	private function policy(string $allowlist = ''): FederationHostPolicy {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') use ($allowlist) {
					return ($key === 'local_federation_hosts') ? $allowlist : $default;
				}
			);

		return new FederationHostPolicy($config);
	}//end policy()


	/**
	 * Addresses no peer could resolve.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function localUrlProvider(): array {
		return [
			'localhost'            => ['http://localhost/apps/opencatalogi/api/directory'],
			'localhost with port'  => ['http://localhost:8080/apps/opencatalogi/api/directory'],
			'loopback v4'          => ['http://127.0.0.1/apps/opencatalogi/api/directory'],
			'all-zeroes'           => ['http://0.0.0.0/apps/opencatalogi/api/directory'],
			'private 10/8'         => ['http://10.0.0.5/api/directory'],
			'private 192.168/16'   => ['http://192.168.1.10/api/directory'],
			'private 172.16/12'    => ['http://172.16.4.4/api/directory'],
			'mdns .local'          => ['http://my-laptop.local/api/directory'],
			// An address we cannot parse is one we cannot reason about, and a
			// value we cannot reason about must never be handed to a peer as
			// our own address.
			'unparseable'          => ['not-a-url'],
			'empty'                => [''],
		];
	}//end localUrlProvider()


	/**
	 * @dataProvider localUrlProvider
	 */
	public function testLocalAddressesAreRefused(string $url): void {
		$this->assertTrue(
			$this->policy()->isLocalUrl($url),
			$url . ' must be treated as local — advertising it would publish an address no peer can resolve.'
		);
	}//end testLocalAddressesAreRefused()


	/**
	 * Addresses a peer can actually reach.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function remoteUrlProvider(): array {
		return [
			'national directory' => ['https://directory.opencatalogi.nl/apps/opencatalogi/api/directory'],
			'public host'        => ['https://opencatalogi.example.org/apps/opencatalogi/api/directory'],
			// Public IP: routable, so not local. Guards against a range check
			// that is too eager and quietly stops real federation.
			'public ipv4'        => ['https://203.0.113.10/api/directory'],
			// `.localdomain` is not `.local`; a suffix check that matched it
			// would refuse a legitimate host.
			'not dot-local'      => ['https://host.localdomain.example.org/api/directory'],
		];
	}//end remoteUrlProvider()


	/**
	 * @dataProvider remoteUrlProvider
	 */
	public function testRoutableAddressesAreAllowed(string $url): void {
		$this->assertFalse(
			$this->policy()->isLocalUrl($url),
			$url . ' is routable and must not be refused — over-refusing silently stops federation.'
		);
	}//end testRoutableAddressesAreAllowed()


	/**
	 * The dev-only allowlist re-enables a docker rig, and nothing more.
	 *
	 * Two instances on a private network must be able to federate. The escape
	 * hatch that permits it must not become a blanket "allow every local
	 * address", or it stops being an escape hatch and becomes the absence of
	 * the guard.
	 */
	public function testAllowlistIsExactAndNotABlanketPermit(): void {
		$policy = $this->policy('nc-fed-1,nc-fed-2');

		$this->assertFalse($policy->isLocalUrl('http://nc-fed-1/apps/opencatalogi/api/directory'));
		$this->assertFalse($policy->isLocalUrl('http://nc-fed-2:9081/apps/opencatalogi/api/directory'));

		// Allowlisting one host does not lift the guard for the others.
		$this->assertTrue($policy->isLocalUrl('http://localhost/apps/opencatalogi/api/directory'));
		$this->assertTrue($policy->isLocalUrl('http://10.0.0.5/api/directory'));

		// WHAT THIS POLICY CANNOT DECIDE, STATED RATHER THAN IMPLIED.
		//
		// A bare hostname it has never heard of is treated as REMOTE, because
		// nothing about `nc-fed-3` distinguishes an internal docker alias from a
		// real public host — no lookup is performed. Only PROVABLY local
		// addresses are refused: literal loopback names, private and reserved IP
		// ranges, and the `.local` mDNS suffix.
		//
		// The practical consequence is worth knowing: an instance whose
		// `overwrite.cli.url` is a bare internal hostname will still advertise
		// it. The guard closes the overwhelmingly common case — a laptop left on
		// `http://localhost` — not every possible one.
		$this->assertFalse(
			$policy->isLocalUrl('http://nc-fed-3/apps/opencatalogi/api/directory'),
			'an unknown bare hostname is indistinguishable from a public one without a lookup'
		);
	}//end testAllowlistIsExactAndNotABlanketPermit()


	/**
	 * The allowlist is matched case-insensitively, because hostnames are.
	 */
	public function testAllowlistIgnoresCaseAndSurroundingWhitespace(): void {
		$policy = $this->policy(' NC-Fed-1 , nc-fed-2 ');

		$this->assertFalse($policy->isLocalUrl('http://nc-fed-1/api/directory'));
		$this->assertFalse($policy->isLocalUrl('http://NC-FED-1/api/directory'));
	}//end testAllowlistIgnoresCaseAndSurroundingWhitespace()


	/**
	 * An empty allowlist is the production default and must permit nothing.
	 */
	public function testEmptyAllowlistPermitsNothing(): void {
		$policy = $this->policy('');

		$this->assertFalse($policy->isAllowlistedFederationHost('localhost'));
		$this->assertFalse($policy->isAllowlistedFederationHost('nc-fed-1'));
	}//end testEmptyAllowlistPermitsNothing()
}//end class
