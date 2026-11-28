<?php

/**
 * Tests for the listener that adds the DCAT catalogue fetcher to OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#requirement-opencatalogi-registers-a-dcat-json-ld-fetcher-req-hfi-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Listener;

use OCA\OpenCatalogi\Harvest\DcatJsonLdFetcher;
use OCA\OpenCatalogi\Listener\RegisterSourceFetchersListener;
use OCA\OpenRegister\Service\Sync\RegisterSourceFetchersEvent;
use OCA\OpenRegister\Service\Sync\SourceFetcherRegistry;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The listener contributes the fetcher on OpenRegister's real registration event.
 */
class RegisterSourceFetchersListenerTest extends TestCase {

	protected function setUp(): void {
		if (class_exists(SourceFetcherRegistry::class) === false) {
			$this->markTestSkipped('OpenRegister\'s harvest classes are not next to the app.');
		}
	}

	private function fetcher(): DcatJsonLdFetcher {
		return new DcatJsonLdFetcher(
			container: $this->createMock(ContainerInterface::class),
			logger: $this->createMock(LoggerInterface::class)
		);
	}

	public function testTheTypeIsOfferedToForms(): void {
		$fetcher  = $this->fetcher();
		$registry = new SourceFetcherRegistry();

		(new RegisterSourceFetchersListener($fetcher))->handle(new RegisterSourceFetchersEvent($registry));

		$this->assertSame($fetcher, $registry->get('opencatalogi.dcat-jsonld'));

		$listed = array_values(array_filter(
			$registry->types(),
			static fn (array $type): bool => ($type['type'] ?? null) === 'opencatalogi.dcat-jsonld'
		));
		$this->assertCount(1, $listed);
		$this->assertSame('DCAT catalogue (JSON-LD)', $listed[0]['displayName']);
		$this->assertSame('opencatalogi', $listed[0]['application']);
	}

	public function testAnotherEventIsIgnored(): void {
		$registry = new SourceFetcherRegistry();

		(new RegisterSourceFetchersListener($this->fetcher()))->handle(new Event());

		$this->assertNull($registry->get('opencatalogi.dcat-jsonld'));
	}
}
