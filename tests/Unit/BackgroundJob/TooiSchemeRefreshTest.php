<?php

/**
 * Tests for the daily value-list refresh (REQ-WVC-002).
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenCatalogi\BackgroundJob\TooiSchemeRefresh;
use OCA\OpenCatalogi\Contract\VocabularyRegisterInterface;
use OCA\OpenCatalogi\Service\Vocabulary\TooiValueListGraph;
use OCA\OpenCatalogi\Service\Vocabulary\ValueListDownload;
use OCA\OpenCatalogi\Service\Vocabulary\ValueListRefresh;
use OCA\OpenCatalogi\Service\Vocabulary\ValueListRefreshState;
use OCA\OpenCatalogi\Service\Woo\PipelineIncidents;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The refresh against the recorded TOOI downloads of the informatiecategorieën.
 *
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
 */
#[CoversClass(TooiSchemeRefresh::class)]
#[CoversClass(ValueListRefresh::class)]
#[CoversClass(ValueListDownload::class)]
#[CoversClass(ValueListRefreshState::class)]
class TooiSchemeRefreshTest extends TestCase {

	/**
	 * The informatiecategorieën scheme.
	 */
	private const SCHEME = 'https://identifier.overheid.nl/tooi/def/thes/kern/wooInformatiecategorieen';

	/**
	 * The infocat018 concept version 4 of the list added.
	 */
	private const NEW_CONCEPT = 'https://identifier.overheid.nl/tooi/def/thes/kern/c_816e508d';

	/**
	 * The stored app config.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * The incidents raised.
	 *
	 * @var array<int, array{kind: string, subject: string, message: string}>
	 */
	private array $raised = [];

	/**
	 * The documents handed to the import.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $imported = [];

	/**
	 * The recorded download of the informatiecategorieën, version 4.
	 *
	 * @return string
	 */
	private static function recorded(): string {
		return (string)file_get_contents(__DIR__ . '/../../fixtures/tooi/scw_woo_informatiecategorieen_4.json');
	}//end recorded()

	/**
	 * A refresh of the one informatiecategorieën source.
	 *
	 * @param array<int, string>    $active   The scheme's active concept uris in the register.
	 * @param string|RuntimeException $download The download body, or the transport failure.
	 *
	 * @return ValueListRefresh
	 */
	private function refresh(array $active, string|RuntimeException $download): ValueListRefresh {
		$this->stored = [
			ValueListRefresh::SOURCES_KEY => (string)json_encode(
				[['key' => 'informatiecategorieen', 'scheme' => self::SCHEME, 'url' => 'https://example.test/ic.json']]
			),
		];

		$response = $this->createMock(originalClassName: IResponse::class);
		$client = $this->createMock(originalClassName: IClient::class);
		if ($download instanceof RuntimeException) {
			$client->method('get')->willThrowException($download);
		} else {
			$response->method('getBody')->willReturn($download);
			$client->method('get')->willReturn($response);
		}

		$clients = $this->createMock(originalClassName: IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$vocabulary = $this->createMock(originalClassName: VocabularyRegisterInterface::class);
		$vocabulary->method('activeConceptUris')->willReturn($active);
		$vocabulary->method('import')->willReturnCallback(
			function (array $jsonLd): array {
				$this->imported[] = $jsonLd;
				return ['scheme' => self::SCHEME, 'created' => 1, 'updated' => 0, 'unchanged' => 17, 'deprecated' => 0];
			}
		);

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->stored[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$key] = $value;
				return true;
			}
		);

		$incidents = $this->createMock(originalClassName: PipelineIncidents::class);
		$incidents->method('raise')->willReturnCallback(
			function (string $kind, string $subject, string $message): void {
				$this->raised[] = ['kind' => $kind, 'subject' => $subject, 'message' => $message];
			}
		);

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-25T03:00:00+00:00'));

		return new ValueListRefresh(
			download: new ValueListDownload(clients: $clients, graph: new TooiValueListGraph()),
			vocabulary: $vocabulary,
			state: new ValueListRefreshState(config: $config, time: $time),
			incidents: $incidents,
		);
	}//end refresh()

	/**
	 * The 17 concepts the register holds before the source added infocat018.
	 *
	 * @return array<int, string>
	 */
	private static function seventeen(): array {
		$uris = [];
		foreach (json_decode(self::recorded(), true) as $node) {
			$isConcept = in_array('http://www.w3.org/2004/02/skos/core#Concept', (array)($node['@type'] ?? []), true);
			if ($isConcept === true && $node['@id'] !== self::NEW_CONCEPT) {
				$uris[] = $node['@id'];
			}
		}

		return $uris;
	}//end seventeen()

	/**
	 * A concept the source added reaches the register, under the configured scheme.
	 *
	 * @return void
	 */
	public function testANewConceptFromTheSourceIsImported(): void {
		$state = $this->refresh(active: self::seventeen(), download: self::recorded())->refreshAll();

		$this->assertCount(expectedCount: 1, haystack: $this->imported);
		$graph = $this->imported[0]['@graph'];
		$this->assertSame(expected: self::SCHEME, actual: $graph[0]['@id']);
		$this->assertSame(expected: 'http://www.w3.org/2004/02/skos/core#ConceptScheme', actual: $graph[0]['@type']);
		$this->assertContains(needle: self::NEW_CONCEPT, haystack: array_column($graph, '@id'));
		$this->assertCount(expectedCount: 19, haystack: $graph, message: 'the scheme and its 18 concepts');

		$this->assertNull(actual: $state['informatiecategorieen']['error']);
		$this->assertSame(expected: 1, actual: $state['informatiecategorieen']['created']);
		$this->assertSame(expected: '2026-11-25T03:00:00+00:00', actual: $state['informatiecategorieen']['lastRefreshAt']);
		$this->assertSame(expected: $state, actual: json_decode($this->stored[ValueListRefresh::STATE_KEY], true));
		$this->assertSame(expected: [], actual: $this->raised);
	}//end testANewConceptFromTheSourceIsImported()

	/**
	 * An answer holding 2 of 40 concepts imports nothing and raises a failure.
	 *
	 * @return void
	 */
	public function testAMassDeprecationIsRefused(): void {
		$all = json_decode(self::recorded(), true);
		$short = array_values(
			array_filter(
				$all,
				static fn (array $node): bool => in_array('http://www.w3.org/2004/02/skos/core#Concept', (array)$node['@type'], true) === false
			)
		);
		$concepts = array_values(
			array_filter($all, static fn (array $node): bool => in_array('http://www.w3.org/2004/02/skos/core#Concept', (array)$node['@type'], true))
		);
		$short[] = $concepts[0];
		$short[] = $concepts[1];

		$others = array_map(callback: static fn (int $i): string => 'https://example.test/c_' . $i, array: range(start: 1, end: 38));
		$active = array_merge([$concepts[0]['@id'], $concepts[1]['@id']], $others);
		$state = $this->refresh(active: $active, download: (string)json_encode($short))->refreshAll();

		$this->assertSame(expected: [], actual: $this->imported, message: 'nothing is imported, so the 40 concepts stay active');
		$this->assertStringContainsString(needle: '38 of 40', haystack: (string)$state['informatiecategorieen']['error']);
		$this->assertCount(expectedCount: 1, haystack: $this->raised);
		$this->assertSame(expected: ValueListRefresh::INCIDENT_KIND, actual: $this->raised[0]['kind']);
		$this->assertSame(expected: 'informatiecategorieen', actual: $this->raised[0]['subject']);
	}//end testAMassDeprecationIsRefused()

	/**
	 * A third deprecated is still a refresh: the guard refuses only more than a third.
	 *
	 * @return void
	 */
	public function testAThirdDeprecatedIsStillImported(): void {
		$active = array_merge(self::seventeen(), array_map(static fn (int $i): string => 'https://example.test/c_' . $i, range(1, 8)));
		$this->refresh(active: $active, download: self::recorded())->refreshAll();

		$this->assertCount(expectedCount: 1, haystack: $this->imported, message: '8 of 25 missing is under a third');
	}//end testAThirdDeprecatedIsStillImported()

	/**
	 * A failed or unreadable download keeps the scheme and raises a failure.
	 *
	 * @return void
	 */
	public function testAFailedDownloadKeepsTheScheme(): void {
		$state = $this->refresh(active: self::seventeen(), download: new RuntimeException('connection reset'))->refreshAll();

		$this->assertSame(expected: [], actual: $this->imported);
		$this->assertStringContainsString(needle: 'connection reset', haystack: (string)$state['informatiecategorieen']['error']);
		$this->assertCount(expectedCount: 1, haystack: $this->raised);

		$this->raised = [];
		$state = $this->refresh(active: self::seventeen(), download: '<html>maintenance</html>')->refreshAll();
		$this->assertSame(expected: [], actual: $this->imported);
		$this->assertStringContainsString(needle: 'not JSON', haystack: (string)$state['informatiecategorieen']['error']);
		$this->assertCount(expectedCount: 1, haystack: $this->raised);
	}//end testAFailedDownloadKeepsTheScheme()

	/**
	 * Without configured sources the seeded TOOI downloads are used.
	 *
	 * @return void
	 */
	public function testTheSeededSourcesAreTheTooiDownloads(): void {
		$refresh = $this->refresh(active: [], download: self::recorded());
		$this->stored = [ValueListRefresh::SOURCES_KEY => 'not json'];

		$sources = $refresh->sources();
		$this->assertSame(expected: ValueListRefresh::DEFAULT_SOURCES, actual: $sources);
		$this->assertSame(expected: ['informatiecategorieen', 'documentsoorten', 'soortHandeling', 'talen'], actual: array_column($sources, 'key'));
		foreach ($sources as $source) {
			$this->assertStringStartsWith(prefix: 'https://repository.officiele-overheidspublicaties.nl/waardelijsten/', string: $source['url']);
			$recorded = __DIR__ . '/../../fixtures/tooi/' . basename($source['url']);
			$this->assertFileExists(filename: $recorded, message: 'each seeded source has its recorded answer');
		}
	}//end testTheSeededSourcesAreTheTooiDownloads()

	/**
	 * The job is in info.xml, runs daily and calls the refresh.
	 *
	 * @return void
	 */
	public function testTheJobIsRegistered(): void {
		$infoXml = (string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml');
		$this->assertStringContainsString(needle: '<job>OCA\\OpenCatalogi\\BackgroundJob\\TooiSchemeRefresh</job>', haystack: $infoXml);

		$refresh = $this->createMock(originalClassName: ValueListRefresh::class);
		$refresh->expects($this->once())->method('refreshAll')->willReturn([]);
		$job = new TooiSchemeRefresh(time: $this->createMock(originalClassName: ITimeFactory::class), refresh: $refresh);
		$this->assertSame(expected: 86400, actual: $job->getInterval());

		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, null);
	}//end testTheJobIsRegistered()
}//end class
