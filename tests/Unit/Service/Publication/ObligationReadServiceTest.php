<?php

/**
 * Unit tests for ObligationReadService.
 *
 * Every listener answers the real ObligationsRequestedEvent. A faked event
 * would hide a wrong accessor, which is the failure the woo build rules name.
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

namespace Unit\Service\Publication;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenCatalogi\Event\ObligationsRequestedEvent;
use OCA\OpenCatalogi\Service\Publication\ObligationOverviewService;
use OCA\OpenCatalogi\Service\Publication\ObligationReadService;
use OCA\OpenCatalogi\Service\Publication\UnreadableRuleException;
use OCA\OpenCatalogi\Service\ServiceCatalogueService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * A dispatcher that calls the listeners it was given, in order, as the server's does.
 */
class ListeningDispatcher implements IEventDispatcher {

	/** @var array<int, callable> */
	public array $listeners = [];

	public function addListener(string $eventName, callable $listener, int $priority = 0): void {
		$this->listeners[] = $listener;
	}

	public function removeListener(string $eventName, callable $listener): void {
	}

	public function addServiceListener(string $eventName, string $className, int $priority = 0): void {
	}

	public function hasListeners(string $eventName): bool {
		return $this->listeners !== [];
	}

	public function dispatch(string $eventName, Event $event): void {
		$this->dispatchTyped($event);
	}

	public function dispatchTyped(Event $event): void {
		foreach ($this->listeners as $listener) {
			$listener($event);
		}
	}
}

/**
 * An OpenRegister ObjectService double: the obligationSource rows, and every save.
 */
class ObligationSourceObjects {

	/** @var array<int, array<string, mixed>> */
	public array $queries = [];

	/** @var array<int, array<string, mixed>> */
	public array $saved = [];

	/**
	 * @param array<int, array<string, mixed>> $sources The stored sources.
	 */
	public function __construct(private array $sources) {
	}

	public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
		$this->queries[] = $query;
		return ['results' => $this->sources, 'total' => count($this->sources)];
	}

	public function saveObject(array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
		$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, 'rbac' => $_rbac];
		return $object;
	}
}

/**
 * @covers \OCA\OpenCatalogi\Service\Publication\ObligationReadService
 * @covers \OCA\OpenCatalogi\Event\ObligationsRequestedEvent
 * @covers \OCA\OpenCatalogi\Service\Publication\ObligationOverviewService
 */
class ObligationReadServiceTest extends TestCase {

	private ListeningDispatcher $dispatcher;

	protected function setUp(): void {
		$this->dispatcher = new ListeningDispatcher();

	}//end setUp()

	/**
	 * The service under test over the given stored sources.
	 *
	 * @param ObligationSourceObjects $objects The OpenRegister double.
	 * @param string $schema The configured obligationSource schema.
	 *
	 * @return ObligationReadService
	 */
	private function service(ObligationSourceObjects $objects, string $schema = '12'): ObligationReadService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => match ($key) {
				'publication_register' => '3',
				'obligation_source_schema' => $schema,
				default => $default,
			}
		);
		$reader = $this->getMockBuilder(ServiceCatalogueService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getObjectService'])
			->getMock();
		$reader->method('getObjectService')->willReturn($objects);

		return new ObligationReadService(
			dispatcher: $this->dispatcher,
			objects: $reader,
			config: $config,
			overview: new ObligationOverviewService()
		);

	}//end service()

	/**
	 * Three stored sources: one that answers, one that throws, one nobody listens for.
	 *
	 * @return ObligationSourceObjects
	 */
	private function threeSources(): ObligationSourceObjects {
		return new ObligationSourceObjects(
			[
				['id' => 'u-dossiq', 'appId' => 'dossiq', 'title' => 'Zaken', 'enabled' => true],
				['id' => 'u-filinq', 'appId' => 'filinq', 'title' => 'Documenten', 'enabled' => true],
				['id' => 'u-stil', 'appId' => 'stilleapp', 'title' => 'Stil', 'enabled' => true],
				['id' => 'u-uit', 'appId' => 'uitgezet', 'title' => 'Uit', 'enabled' => false],
			]
		);

	}//end threeSources()

	/**
	 * One source answers with three obligations, one throws and one has no
	 * reader. The answering source's obligations are listed; the other two are
	 * unread with their reason and are not counted as zero.
	 *
	 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
	 */
	public function testOneSourceAnswersOneThrowsAndOneHasNoReader(): void {
		$this->dispatcher->addListener(
			ObligationsRequestedEvent::class,
			static function (Event $event): void {
				if ($event instanceof ObligationsRequestedEvent && $event->getAppId() === 'dossiq') {
					$event->setObligations(
						[
							['title' => 'Besluit A', 'publishedAt' => '2026-09-01T00:00:00+00:00'],
							['title' => 'Besluit B', 'dueDate' => '2026-09-01T00:00:00+00:00'],
							['title' => 'Besluit C', 'dueDate' => '2026-12-01T00:00:00+00:00'],
						]
					);
				}
			}
		);
		$this->dispatcher->addListener(
			ObligationsRequestedEvent::class,
			static function (Event $event): void {
				if ($event instanceof ObligationsRequestedEvent && $event->getAppId() === 'filinq') {
					throw new \RuntimeException('Filinq is in maintenance.');
				}
			}
		);
		$objects = $this->threeSources();

		$overview = $this->service(objects: $objects)->read(now: new DateTimeImmutable('2026-10-01T00:00:00+00:00', new DateTimeZone('UTC')));

		$this->assertSame(['Besluit A', 'Besluit B', 'Besluit C'], array_column($overview['obligations'], 'title'));
		$this->assertSame(['dossiq', 'dossiq', 'dossiq'], array_column($overview['obligations'], 'source'));
		$this->assertSame(1, $overview['published']);
		$this->assertSame(1, $overview['late']);
		$this->assertSame(1, $overview['sourcesRead']);
		$this->assertSame(3, $overview['sourcesRegistered'], 'a switched-off source is left out entirely');

		$unread = array_column($overview['unreadSources'], 'reason', 'appId');
		$this->assertSame(['filinq', 'stilleapp'], array_keys($unread));
		$this->assertSame('Filinq is in maintenance.', $unread['filinq']);
		$this->assertSame(ObligationReadService::NO_READER, $unread['stilleapp']);

	}//end testOneSourceAnswersOneThrowsAndOneHasNoReader()

	/**
	 * A source that answered gets its `lastReadAt`; one that did not keeps the
	 * old moment, because it was not read.
	 *
	 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
	 */
	public function testOnlyASourceThatWasReadGetsItsLastReadMoment(): void {
		$this->dispatcher->addListener(
			ObligationsRequestedEvent::class,
			static function (Event $event): void {
				if ($event instanceof ObligationsRequestedEvent && $event->getAppId() === 'dossiq') {
					$event->setObligations([]);
				}
			}
		);
		$objects = $this->threeSources();

		$overview = $this->service(objects: $objects)->read(now: new DateTimeImmutable('2026-10-01T08:00:00+00:00'));

		$this->assertSame(1, $overview['sourcesRead'], 'an empty answer is an answer: that source has nothing to publish');
		$this->assertCount(1, $objects->saved);
		$save = $objects->saved[0];
		$this->assertSame('u-dossiq', $save['uuid']);
		$this->assertSame('2026-10-01T08:00:00+00:00', $save['object']['lastReadAt']);
		$this->assertSame('3', (string)$save['register']);
		$this->assertSame('12', (string)$save['schema']);

	}//end testOnlyASourceThatWasReadGetsItsLastReadMoment()

	/**
	 * The sources are read from the configured register and schema, and
	 * without a configured schema the service refuses rather than reporting an
	 * organisation with no sources as compliant.
	 *
	 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
	 */
	public function testWithoutAConfiguredSourceSchemaTheOverviewRefuses(): void {
		$objects = $this->threeSources();

		$this->expectException(UnreadableRuleException::class);

		$this->service(objects: $objects, schema: '')->read();

	}//end testWithoutAConfiguredSourceSchemaTheOverviewRefuses()

	/**
	 * The stored sources are read from the configured register and schema.
	 *
	 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
	 */
	public function testTheSourcesComeFromTheConfiguredSchema(): void {
		$objects = $this->threeSources();

		$this->service(objects: $objects)->read();

		$this->assertSame(['register' => '3', 'schema' => '12'], $objects->queries[0]['@self']);

	}//end testTheSourcesComeFromTheConfiguredSchema()

	/**
	 * The saved `lastReadAt` is valid under the real `obligationSource` fragment.
	 *
	 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
	 */
	public function testTheSavedSourceFitsTheRealSchemaFragment(): void {
		$this->dispatcher->addListener(
			ObligationsRequestedEvent::class,
			static function (Event $event): void {
				if ($event instanceof ObligationsRequestedEvent) {
					$event->setObligations([]);
				}
			}
		);
		$objects = $this->threeSources();
		$this->service(objects: $objects)->read(now: new DateTimeImmutable('2026-10-01T08:00:00+00:00'));

		$fragment = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../../lib/Settings/register.d/publication-inspection-and-the-national-indexes.json'),
			true
		)['components']['schemas']['obligationSource'];

		foreach ($objects->saved as $save) {
			$object = $save['object'];
			foreach ($fragment['required'] as $required) {
				$this->assertArrayHasKey($required, $object);
			}

			foreach (array_keys($object) as $property) {
				if ($property === 'id') {
					continue;
				}

				$this->assertArrayHasKey($property, $fragment['properties'], 'the schema declares ' . $property);
			}

			$this->assertSame('date-time', $fragment['properties']['lastReadAt']['format']);
			$this->assertNotFalse(DateTimeImmutable::createFromFormat(DATE_ATOM, $object['lastReadAt']));
		}

		$this->assertCount(3, $objects->saved);

	}//end testTheSavedSourceFitsTheRealSchemaFragment()

	/**
	 * The event carries the source it asks, and an answer only once set.
	 *
	 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
	 */
	public function testTheEventIsUnansweredUntilAListenerSetsObligations(): void {
		$event = new ObligationsRequestedEvent(appId: 'dossiq');

		$this->assertSame('dossiq', $event->getAppId());
		$this->assertFalse($event->isAnswered());
		$this->assertSame([], $event->getObligations());

		$event->setObligations([['title' => 'Besluit'], 'geen rij']);

		$this->assertTrue($event->isAnswered());
		$this->assertSame([['title' => 'Besluit']], $event->getObligations());

	}//end testTheEventIsUnansweredUntilAListenerSetsObligations()
}//end class
