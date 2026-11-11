<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins which broadcast URLs BroadcastService's outbound guard lets through
 * and which it refuses, with the refusal's message, in the order the checks
 * run. Only literal addresses, allowlisted hosts and a reserved `.invalid`
 * name are used, so no case depends on a reachable resolver.
 */

declare(strict_types=1);

namespace Unit\Service;

use InvalidArgumentException;
use OCA\OpenCatalogi\Service\BroadcastService;
use OCA\OpenCatalogi\Service\Federation\FederationHostPolicy;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BroadcastService's outbound URL guard.
 */
class BroadcastOutboundGuardTest extends TestCase {

	/**
	 * URLs and what the guard answers: 'allowed', or the refusal's message.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function urls(): array {
		$invalid = 'Invalid broadcast URL provided';
		$scheme = 'Broadcast URL scheme must be http or https';
		$name = 'Broadcast URL host is not allowed';
		$internal = 'Broadcast URL resolves to a disallowed (internal) address';
		$unresolved = 'Broadcast URL host could not be resolved';

		return [
			'no scheme' => ['peer.example.nl/api', $invalid],
			'no host' => ['https:///api', $invalid],
			'unparseable' => ['http://:80', $invalid],
			'ftp' => ['ftp://8.8.8.8/x', $scheme],
			'scheme in capitals' => ['HTTPS://8.8.8.8/api', 'allowed'],
			'a public IPv4 address' => ['https://8.8.8.8/api', 'allowed'],
			'a public IPv6 address' => ['https://[2001:4860:4860::8888]/api', 'allowed'],
			'localhost' => ['http://localhost/api', $name],
			'LOCALHOST' => ['http://LOCALHOST/api', $name],
			'a .local name' => ['http://printer.local/api', $name],
			'a .localhost name' => ['http://app.localhost/api', $name],
			'a private 10/8 address' => ['http://10.1.2.3/api', $internal],
			'a private 172.16/12 address' => ['http://172.20.0.1/api', $internal],
			'a 172.32 address' => ['http://172.32.0.1/api', 'allowed'],
			'a private 192.168/16 address' => ['http://192.168.1.1/api', $internal],
			'loopback' => ['http://127.0.0.1:8080/api', $internal],
			'link-local' => ['http://169.254.169.254/latest', $internal],
			'IPv6 loopback' => ['http://[::1]/api', $internal],
			'IPv6 unique local' => ['http://[fd00::1]/api', $internal],
			'IPv6 link-local' => ['http://[fe80::1]/api', $internal],
			'an allowlisted local name' => ['http://nextcloud-b/api', 'allowed'],
			'an allowlisted localhost' => ['http://dev.localhost/api', 'allowed'],
			'an allowlisted private address' => ['http://10.9.9.9/api', 'allowed'],
			'an unresolvable name' => ['https://no-such-peer.invalid/api', $unresolved],
		];

	}//end urls()

	/**
	 * The guard lets a URL through or refuses it with its message.
	 *
	 * @param string $url The broadcast URL.
	 * @param string $expected 'allowed', or the refusal's message.
	 *
	 * @return void
	 */
	#[DataProvider('urls')]
	public function testTheGuardAllowsOrRefuses(string $url, string $expected): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'local_federation_hosts' ? 'nextcloud-b, DEV.localhost,10.9.9.9' : $default)
		);
		$config->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default = 0): int => $default);

		$service = new BroadcastService(
			$this->createMock(IURLGenerator::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class),
			$config,
			new FederationHostPolicy($config)
		);

		$actual = 'allowed';
		try {
			(new \ReflectionMethod($service, 'assertSafeOutboundUrl'))->invoke($service, $url);
		} catch (InvalidArgumentException $e) {
			$actual = $e->getMessage();
		}

		$this->assertSame($expected, $actual);
	}//end testTheGuardAllowsOrRefuses()
}//end class
