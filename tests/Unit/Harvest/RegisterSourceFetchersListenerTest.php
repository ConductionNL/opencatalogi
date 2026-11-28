<?php

/**
 * Tests for the listener that adds the DCAT catalogue fetcher to OpenRegister.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Harvest
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
 *
 * @spec openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#requirement-opencatalogi-registers-a-dcat-json-ld-fetcher-req-hfi-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Harvest;

use OCA\OpenCatalogi\Harvest\DcatJsonLdFetcher;
use OCA\OpenCatalogi\Harvest\RegisterSourceFetchersListener;
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

	/**
	 * Skip where OpenRegister's harvest classes are not next to the app.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		if (class_exists(SourceFetcherRegistry::class) === false) {
			$this->markTestSkipped(message: 'OpenRegister\'s harvest classes are not next to the app.');
		}

	}//end setUp()

	/**
	 * The fetcher under test.
	 *
	 * @return DcatJsonLdFetcher
	 */
	private function fetcher(): DcatJsonLdFetcher {
		return new DcatJsonLdFetcher(
			container: $this->createMock(originalClassName: ContainerInterface::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

	}//end fetcher()

	/**
	 * The type is listed with its display name, owned by this app.
	 *
	 * @return void
	 */
	public function testTheTypeIsOfferedToForms(): void {
		$fetcher  = $this->fetcher();
		$registry = new SourceFetcherRegistry();

		$listener = new RegisterSourceFetchersListener(fetcher: $fetcher);
		$listener->handle(event: new RegisterSourceFetchersEvent(registry: $registry));

		$this->assertSame(expected: $fetcher, actual: $registry->get(type: 'opencatalogi.dcat-jsonld'));

		$listed = array_values(
			array_filter(
				$registry->types(),
				static fn (array $type): bool => ($type['type'] ?? null) === 'opencatalogi.dcat-jsonld'
			)
		);
		$this->assertCount(expectedCount: 1, haystack: $listed);
		$this->assertSame(expected: 'DCAT catalogue (JSON-LD)', actual: $listed[0]['displayName']);
		$this->assertSame(expected: 'opencatalogi', actual: $listed[0]['application']);

	}//end testTheTypeIsOfferedToForms()

	/**
	 * Any other event leaves the registry alone.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$registry = new SourceFetcherRegistry();

		$listener = new RegisterSourceFetchersListener(fetcher: $this->fetcher());
		$listener->handle(event: new Event());

		$this->assertNull(actual: $registry->get(type: 'opencatalogi.dcat-jsonld'));

	}//end testAnotherEventIsIgnored()
}//end class
