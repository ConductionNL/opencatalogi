<?php

/**
 * Every place in lib/ that can send something out is either gated or a named inbound reader
 * (instance-staging-mode REQ-STG-002).
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/instance-staging-mode/tasks.md#task-2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Reads lib/ as text: a new HTTP client or gateway delivery outside the list fails here
 * until it asks the outbound gate or is named as a reader.
 *
 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-nothing-leaves-the-instance-in-staging-req-stg-002
 */
#[CoversNothing]
final class OutboundGateCoverageTest extends TestCase {

	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * What counts as a way out: constructing an HTTP client, injecting Nextcloud's client
	 * service, or dispatching the gateway delivery event.
	 */
	private const OUTBOUND = [
		'/new\s+(?:\\\\?GuzzleHttp\\\\)?Client\s*\(/',
		'/\bIClientService\b/',
		'/GatewayDeliveryRequestedEvent/',
	];

	/**
	 * The files that send, each with the gated methods that hold it in staging.
	 */
	private const GATED = [
		'Service/BroadcastService.php' => ["hold(channel: 'broadcast'"],
		'Service/Publication/NationalIndexService.php' => [
			"hold(channel: 'national-index', payload: \$notice)",
			"hold(channel: 'plooi'",
			"hold(channel: 'woo-registration'",
			"hold(channel: 'national-index', payload: ['channel' => \$channel",
		],
		'Service/DirectoryService.php' => ["hold(channel: 'directory-announce'"],
	];

	/**
	 * The files that only read from the outside: they fetch, they never hand anything over.
	 * DirectoryService also reads (a peer's listings); its one way out is gated above.
	 */
	private const READERS = [
		'Observability/SearchBackendHealthProvider.php' => 'pings the configured search backend for the health check',
		'Service/DirectoryService.php' => 'fetches peer directories and their listings',
		'Service/DonlRegistrationService.php' => 'reads how many of the feed\'s datasets data.overheid.nl lists',
		'Service/StandardsVersionService.php' => 'reads the published versions of the standards',
		'Service/WooReadinessService.php' => 'reads the catalogue\'s own public sitemap for the readiness check',
	];

	/**
	 * The lib/ files that mention a way out, relative to lib/.
	 *
	 * @return list<string>
	 */
	private static function outboundFiles(): array {
		$files = [];
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::LIB, RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$source = (string)file_get_contents($file->getPathname());
			foreach (self::OUTBOUND as $pattern) {
				if (preg_match($pattern, $source) === 1) {
					$files[] = substr($file->getPathname(), strlen(self::LIB) + 1);
					break;
				}
			}
		}

		sort($files);
		return $files;
	}//end outboundFiles()

	/**
	 * Every file with a way out is gated or a named reader, and nothing else has one.
	 *
	 * @return void
	 */
	public function testNoUngatedOutboundSiteExists(): void {
		$known = array_unique(array_merge(array_keys(self::GATED), array_keys(self::READERS)));
		sort($known);

		self::assertSame($known, self::outboundFiles(), 'A file in lib/ can send something out without asking the outbound gate: gate it or name it as a reader here.');
	}//end testNoUngatedOutboundSiteExists()

	/**
	 * Each gated file still asks the gate at every hand-over it is listed for.
	 *
	 * @return void
	 */
	public function testEveryGatedFileAsksTheGate(): void {
		foreach (self::GATED as $file => $holds) {
			$source = (string)file_get_contents(self::LIB . '/' . $file);
			foreach ($holds as $hold) {
				self::assertStringContainsString('dryRuns?->' . $hold, $source, $file . ' no longer holds ' . $hold);
			}
		}
	}//end testEveryGatedFileAsksTheGate()

	/**
	 * The pattern list finds what it is meant to find, so an empty scan cannot pass by accident.
	 *
	 * @return void
	 */
	public function testThePatternsMatchEachWayOut(): void {
		$samples = [
			'$this->client = new Client([]);',
			'new \\GuzzleHttp\\Client($config)',
			'private readonly IClientService $clientService',
			"'OCA\\Integriq\\Event\\GatewayDeliveryRequestedEvent'",
		];
		foreach ($samples as $sample) {
			$matched = array_filter(self::OUTBOUND, static fn (string $pattern): bool => preg_match($pattern, $sample) === 1);
			self::assertNotSame([], $matched, $sample);
		}

		self::assertSame(0, preg_match(self::OUTBOUND[0], '$client = $factory->newClient();'));
	}//end testThePatternsMatchEachWayOut()
}//end class
