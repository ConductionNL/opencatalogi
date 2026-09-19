<?php

declare(strict_types=1);

namespace Unit\Service\Publication;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use OCA\OpenCatalogi\Service\Publication\DepublicationService;
use OCA\OpenCatalogi\Service\Publication\DocumentStampService;
use OCA\OpenCatalogi\Service\Publication\IndexUnreachableException;
use OCA\OpenCatalogi\Service\Publication\NationalIndexService;
use OCA\OpenCatalogi\Service\Publication\ObligationOverviewService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for depublication, the national channels, the stamp and the
 * obligation overview.
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\DepublicationService
 * @covers \OCA\OpenCatalogi\Service\Publication\NationalIndexService
 * @covers \OCA\OpenCatalogi\Service\Publication\DocumentStampService
 * @covers \OCA\OpenCatalogi\Service\Publication\ObligationOverviewService
 */
class DepublicationAndIndexTest extends TestCase {

	private MockObject&LoggerInterface $logger;
	private MockObject&ContainerInterface $container;

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->container = $this->createMock(ContainerInterface::class);

	}//end setUp()

	/**
	 * A moment.
	 *
	 * @param string $when The moment.
	 *
	 * @return DateTimeImmutable
	 */
	private function at(string $when): DateTimeImmutable {
		return new DateTimeImmutable($when, new DateTimeZone('UTC'));

	}//end at()

	/**
	 * An index service whose channels all answer.
	 *
	 * @return NationalIndexService&MockObject
	 */
	private function answeringIndex(): NationalIndexService {
		// onlyMethods against the real class, so a double cannot invent a
		// method the service lacks.
		$index = $this->getMockBuilder(NationalIndexService::class)
			->disableOriginalConstructor()
			->onlyMethods(['withdraw'])
			->getMock();
		$index->method('withdraw')->willReturnCallback(
			function (string $channel): array {
				return [
					'channel' => $channel,
					'acknowledgedAt' => '2026-09-18T10:00:00+00:00',
					'answer' => 'ok',
				];
			}
		);

		return $index;

	}//end answeringIndex()

	public function testOneActionTakesItDownEverywhereAndRecordsWhoAndWhy(): void {
		$service = new DepublicationService($this->answeringIndex(), $this->logger);

		$depublication = $service->depublish(
			publication: ['id' => 'p1'],
			reason: 'Een naam stond erin die er niet in hoorde.',
			depublishedBy: 'ambtenaar',
			channels: [NationalIndexService::CHANNEL_LOCAL, NationalIndexService::CHANNEL_WOO_INDEX],
			now: $this->at('2026-09-18T10:00:00+00:00')
		);

		$this->assertSame('ambtenaar', $depublication['depublishedBy']);
		$this->assertSame('Een naam stond erin die er niet in hoorde.', $depublication['reason']);
		$this->assertCount(2, $depublication['withdrawals']);
		$this->assertTrue($service->isComplete(depublication: $depublication));
		$this->assertSame([], $service->outstandingChannels(depublication: $depublication));

	}//end testOneActionTakesItDownEverywhereAndRecordsWhoAndWhy()

	/**
	 * The failure this guards: a channel that was never reached recorded as
	 * done, so an operator believes a document is gone from a harvester that
	 * still holds it.
	 */
	public function testAChannelThatCouldNotBeReachedIsOutstandingAndNotDone(): void {
		$index = $this->getMockBuilder(NationalIndexService::class)
			->disableOriginalConstructor()
			->onlyMethods(['withdraw'])
			->getMock();
		$index->method('withdraw')->willReturnCallback(
			function (string $channel): array {
				if ($channel === NationalIndexService::CHANNEL_WOO_INDEX) {
					throw new IndexUnreachableException('the gateway is not installed');
				}

				return ['channel' => $channel, 'acknowledgedAt' => '2026-09-18T10:00:00+00:00', 'answer' => 'ok'];
			}
		);

		$service = new DepublicationService($index, $this->logger);

		$depublication = $service->depublish(
			publication: ['id' => 'p1'],
			reason: 'Publicatiefout.',
			depublishedBy: 'ambtenaar',
			channels: [NationalIndexService::CHANNEL_LOCAL, NationalIndexService::CHANNEL_WOO_INDEX],
			now: $this->at('2026-09-18T10:00:00+00:00')
		);

		$this->assertFalse($service->isComplete(depublication: $depublication));
		$this->assertSame([NationalIndexService::CHANNEL_WOO_INDEX], $service->outstandingChannels(depublication: $depublication));

	}//end testAChannelThatCouldNotBeReachedIsOutstandingAndNotDone()

	public function testALateAcknowledgementClosesTheWithdrawal(): void {
		$service = new DepublicationService($this->answeringIndex(), $this->logger);
		$depublication = [
			'publication' => 'p1',
			'withdrawals' => [
				['channel' => 'national-woo-index', 'sentAt' => '2026-09-18T10:00:00+00:00', 'acknowledgedAt' => null],
			],
		];

		$updated = $service->recordAcknowledgement(
			depublication: $depublication,
			channel: 'national-woo-index',
			answer: 'verwijderd',
			now: $this->at('2026-09-19T09:00:00+00:00')
		);

		$this->assertTrue($service->isComplete(depublication: $updated));
		$this->assertSame('2026-09-19T09:00:00+00:00', $updated['withdrawals'][0]['acknowledgedAt']);

	}//end testALateAcknowledgementClosesTheWithdrawal()

	public function testADepublicationWithoutAReasonIsRefused(): void {
		$service = new DepublicationService($this->answeringIndex(), $this->logger);

		$this->expectException(DomainException::class);

		$service->depublish(publication: ['id' => 'p1'], reason: '  ', depublishedBy: 'a', channels: []);

	}//end testADepublicationWithoutAReasonIsRefused()

	public function testTheNoticeIsComposedForBothChannels(): void {
		$service = new NationalIndexService($this->container, $this->logger);

		$notices = $service->composeNotices(
			decision: [
				'id' => 'b1',
				'title' => 'Kapvergunning Dorpsstraat',
				'publicationText' => 'Tegen dit besluit kunt u bezwaar maken.',
				'publicationDate' => '2026-09-18',
				'responseDate' => '2026-10-30',
			],
			now: $this->at('2026-09-18T10:00:00+00:00')
		);

		$this->assertCount(2, $notices);
		$this->assertSame(NationalIndexService::CHANNEL_NATIONAL, $notices[0]['channel']);
		$this->assertSame(NationalIndexService::CHANNEL_LOCAL, $notices[1]['channel']);
		$this->assertSame('Kapvergunning Dorpsstraat', $notices[0]['subject']);
		$this->assertSame('Tegen dit besluit kunt u bezwaar maken.', $notices[0]['body']);

	}//end testTheNoticeIsComposedForBothChannels()

	/**
	 * With no gateway installed, delivery raises. It never answers "delivered":
	 * this app implements no transport, so it cannot have delivered anything.
	 */
	public function testWithNoGatewayADeliveryIsUnreachableAndNotSilentlyDone(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('not installed'));
		$service = new NationalIndexService($this->container, $this->logger);

		$this->expectException(IndexUnreachableException::class);

		$service->deliver(notice: ['channel' => NationalIndexService::CHANNEL_NATIONAL]);

	}//end testWithNoGatewayADeliveryIsUnreachableAndNotSilentlyDone()

	public function testRegisteringWithTheWooIndexWithoutAGatewayIsUnreachable(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('not installed'));
		$service = new NationalIndexService($this->container, $this->logger);

		$this->expectException(IndexUnreachableException::class);

		$service->registerWithWooIndex(publication: ['id' => 'p1']);

	}//end testRegisteringWithTheWooIndexWithoutAGatewayIsUnreachable()

	public function testAReaderVerifiesAPublishedDocumentAndAChangedOneFails(): void {
		$service = new DocumentStampService(signingKey: 'organisation-key', keyId: 'gemeente-2026');
		$metadata = ['id' => 'd1', 'title' => 'Besluit', 'publicationDate' => '2026-09-18', 'organisation' => 'Zuiderdorp'];

		$stamp = $service->stamp(documentBytes: 'de inhoud van het document', metadata: $metadata);

		$this->assertTrue($service->verify(documentBytes: 'de inhoud van het document', metadata: $metadata, stamp: $stamp)['valid']);

		$altered = $service->verify(documentBytes: 'de inhoud van het document.', metadata: $metadata, stamp: $stamp);
		$this->assertFalse($altered['valid']);
		$this->assertSame('does-not-match', $altered['reason']);

	}//end testAReaderVerifiesAPublishedDocumentAndAChangedOneFails()

	/**
	 * The stamp covers the publication metadata too: a correct document
	 * published under a false date is its own kind of falsehood.
	 */
	public function testChangedPublicationMetadataAlsoFailsTheCheck(): void {
		$service = new DocumentStampService(signingKey: 'organisation-key');
		$metadata = ['id' => 'd1', 'title' => 'Besluit', 'publicationDate' => '2026-09-18'];
		$stamp = $service->stamp(documentBytes: 'inhoud', metadata: $metadata);

		$metadata['publicationDate'] = '2026-01-01';

		$this->assertFalse($service->verify(documentBytes: 'inhoud', metadata: $metadata, stamp: $stamp)['valid']);

	}//end testChangedPublicationMetadataAlsoFailsTheCheck()

	/**
	 * With no key, stamping refuses. A stamp made with an empty key verifies
	 * against an empty key, so every reader would be told the document is
	 * authentic and nobody would have checked anything.
	 */
	public function testWithNoKeyStampingRefusesAndVerificationIsNotGreen(): void {
		$service = new DocumentStampService(signingKey: '');

		$this->assertNull($service->publishedKey());
		$this->assertSame('no-key', $service->verify(documentBytes: 'x', metadata: [], stamp: ['signature' => 'y'])['reason']);

		$this->expectException(DomainException::class);
		$service->stamp(documentBytes: 'x', metadata: []);

	}//end testWithNoKeyStampingRefusesAndVerificationIsNotGreen()

	public function testThePublishedKeyIsAFingerprintAndNotTheKey(): void {
		$service = new DocumentStampService(signingKey: 'organisation-key', keyId: 'gemeente-2026');
		$published = $service->publishedKey();

		$this->assertSame('gemeente-2026', $published['keyId']);
		$this->assertStringNotContainsString('organisation-key', (string)json_encode($published));

	}//end testThePublishedKeyIsAFingerprintAndNotTheKey()

	public function testASecondApplicationsObligationsAppearBesideThisApps(): void {
		$service = new ObligationOverviewService();

		$overview = $service->assemble(
			sources: [
				['appId' => 'opencatalogi', 'title' => 'OpenCatalogi'],
				['appId' => 'dossiq', 'title' => 'Dossiq'],
			],
			obligationsBySource: [
				'opencatalogi' => [['id' => 'o1', 'dueDate' => '2026-12-01', 'publishedAt' => '2026-09-01']],
				'dossiq' => [['id' => 'o2', 'dueDate' => '2026-08-01']],
			],
			now: $this->at('2026-09-18T00:00:00+00:00')
		);

		$this->assertSame(2, $overview['total']);
		$this->assertSame(1, $overview['published']);
		$this->assertSame(1, $overview['late']);
		$this->assertSame(['opencatalogi', 'dossiq'], array_column($overview['obligations'], 'source'));

	}//end testASecondApplicationsObligationsAppearBesideThisApps()

	/**
	 * A source that could not be read is named, never counted as a source with
	 * nothing to publish. An overview that swallowed it would report an
	 * organisation as compliant that is not.
	 */
	public function testASourceThatCouldNotBeReadIsNamedRatherThanCountedAsEmpty(): void {
		$service = new ObligationOverviewService();

		$overview = $service->assemble(
			sources: [
				['appId' => 'opencatalogi', 'title' => 'OpenCatalogi'],
				['appId' => 'dossiq', 'title' => 'Dossiq'],
			],
			obligationsBySource: [
				'opencatalogi' => [['id' => 'o1', 'dueDate' => '2026-12-01']],
				'dossiq' => new \RuntimeException('dossiq did not answer'),
			],
			now: $this->at('2026-09-18T00:00:00+00:00')
		);

		$this->assertSame(1, $overview['sourcesRead']);
		$this->assertSame(2, $overview['sourcesRegistered']);
		$this->assertSame('dossiq', $overview['unreadSources'][0]['appId']);
		$this->assertStringContainsString('did not answer', $overview['unreadSources'][0]['reason']);

	}//end testASourceThatCouldNotBeReadIsNamedRatherThanCountedAsEmpty()

	public function testAnObligationWithAnUnreadableDueDateIsUnknownAndNotPublished(): void {
		$service = new ObligationOverviewService();

		$this->assertSame(
			'unknown',
			$service->stateOf(obligation: ['dueDate' => 'ooit'], now: $this->at('2026-09-18T00:00:00+00:00'))
		);
		$this->assertSame(
			'unknown',
			$service->stateOf(obligation: [], now: $this->at('2026-09-18T00:00:00+00:00'))
		);

	}//end testAnObligationWithAnUnreadableDueDateIsUnknownAndNotPublished()
}//end class
