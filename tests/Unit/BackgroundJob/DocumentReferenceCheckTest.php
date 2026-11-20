<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The daily reachability check of document references (REQ-PBR-003): a 200,
 * a 404 and a 405-then-206 source, each recorded with the run's check time.
 *
 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-broken-references-are-shown-before-readers-find-them-req-pbr-003
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenCatalogi\BackgroundJob\DocumentReferenceCheck;
use OCA\OpenCatalogi\Service\DocumentReferenceProbe;
use OCA\OpenCatalogi\Service\DocumentReferences;
use OCA\OpenCatalogi\Service\DocumentReferenceUrlPolicy;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenCatalogi\BackgroundJob\DocumentReferenceCheck
 * @uses   \OCA\OpenCatalogi\Service\DocumentReferenceProbe
 * @uses   \OCA\OpenCatalogi\Service\DocumentReferenceUrlPolicy
 * @uses   \OCA\OpenCatalogi\Service\Federation\OutboundUrlGuard
 */
class DocumentReferenceCheckTest extends TestCase {

	/**
	 * HEAD and GET status per URL path.
	 */
	private const SOURCES = [
		'/fine.pdf' => ['HEAD' => 200],
		'/moved.pdf' => ['HEAD' => 404],
		'/no-head.pdf' => ['HEAD' => 405, 'GET' => 206],
	];

	public function testEachReferenceIsRecordedWithWhatItsSourceAnswered(): void {
		$client = $this->createMock(IClient::class);
		$answer = function (string $method, string $url): IResponse {
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn(self::SOURCES[(string)parse_url($url, PHP_URL_PATH)][$method]);
			return $response;
		};
		$client->method('head')->willReturnCallback(static fn (string $url): IResponse => $answer('HEAD', $url));
		$client->method('get')->willReturnCallback(static fn (string $url): IResponse => $answer('GET', $url));
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$references = $this->createMock(DocumentReferences::class);
		$references->expects($this->once())->method('inWindow')->with(DocumentReferenceCheck::PER_RUN_CAP)->willReturn(
			[
				['id' => 'a', 'url' => 'https://93.184.215.14/fine.pdf'],
				['id' => 'b', 'url' => 'https://93.184.215.14/moved.pdf'],
				['id' => 'c', 'url' => 'https://93.184.215.14/no-head.pdf'],
			]
		);
		$recorded = [];
		$references->method('recordCheck')->willReturnCallback(
			static function (array $reference, bool $reachable, int $status, string $checkedAt) use (&$recorded): bool {
				$recorded[] = [$reference['id'], $reachable, $status, $checkedAt];
				return true;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-13T03:00:00+00:00'));

		$job = new DocumentReferenceCheck(
			time: $time,
			references: $references,
			probe: new DocumentReferenceProbe(clients: $clients, policy: new DocumentReferenceUrlPolicy()),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertSame(['checked' => 3, 'unreachable' => 1], $job->checkAll());
		$this->assertSame(
			[
				['a', true, 200, '2026-11-13T03:00:00+00:00'],
				['b', false, 404, '2026-11-13T03:00:00+00:00'],
				['c', true, 206, '2026-11-13T03:00:00+00:00'],
			],
			$recorded
		);
	}//end testEachReferenceIsRecordedWithWhatItsSourceAnswered()

	public function testItRunsDailyAndNotInParallel(): void {
		$job = new DocumentReferenceCheck(
			time: $this->createMock(ITimeFactory::class),
			references: $this->createMock(DocumentReferences::class),
			probe: $this->createMock(DocumentReferenceProbe::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertSame(86400, (new \ReflectionProperty($job, 'interval'))->getValue($job));
		$this->assertFalse($job->getAllowParallelRuns());
	}//end testItRunsDailyAndNotInParallel()

	public function testTheJobIsRegistered(): void {
		$info = (string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml');

		$this->assertStringContainsString('<job>' . DocumentReferenceCheck::class . '</job>', $info);
	}//end testTheJobIsRegistered()
}//end class
