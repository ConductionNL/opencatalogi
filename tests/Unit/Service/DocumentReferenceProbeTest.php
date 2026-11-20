<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The reachability check of one reference: HEAD, a ranged GET when HEAD is
 * refused, and nothing at all for an address that is not public (REQ-PBR-003).
 *
 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-broken-references-are-shown-before-readers-find-them-req-pbr-003
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DocumentReferenceProbe;
use OCA\OpenCatalogi\Service\DocumentReferenceUrlPolicy;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Service\DocumentReferenceProbe
 * @uses   \OCA\OpenCatalogi\Service\DocumentReferenceUrlPolicy
 * @uses   \OCA\OpenCatalogi\Service\Federation\OutboundUrlGuard
 */
class DocumentReferenceProbeTest extends TestCase {

	private const URL = 'https://93.184.215.14/besluit.pdf';

	/**
	 * The requests the client was asked for.
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $requests = [];

	/**
	 * A probe whose client answers HEAD and GET with these statuses.
	 *
	 * @param int|\Throwable $head The HEAD answer.
	 * @param int|null $get The GET answer.
	 *
	 * @return DocumentReferenceProbe The probe.
	 */
	private function probe(int|\Throwable $head, ?int $get = null): DocumentReferenceProbe {
		$answer = function (int $status): IResponse {
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($status);
			return $response;
		};

		$client = $this->createMock(IClient::class);
		$client->method('head')->willReturnCallback(
			function (string $url, array $options) use ($head, $answer): IResponse {
				$this->requests[] = ['HEAD', $url, $options];
				if ($head instanceof \Throwable) {
					throw $head;
				}

				return $answer($head);
			}
		);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options) use ($get, $answer): IResponse {
				$this->requests[] = ['GET', $url, $options];
				return $answer((int)$get);
			}
		);

		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		return new DocumentReferenceProbe(clients: $clients, policy: new DocumentReferenceUrlPolicy());
	}//end probe()

	public function testAnAnsweringServerIsReachable(): void {
		$status = $this->probe(head: 200)->status(url: self::URL);

		$this->assertSame(200, $status);
		$this->assertTrue(DocumentReferenceProbe::isReachable(status: $status));
		$this->assertSame(['HEAD'], array_column($this->requests, 0));
		$this->assertSame(DocumentReferenceProbe::TIMEOUT, $this->requests[0][2]['timeout']);
		$this->assertFalse($this->requests[0][2]['http_errors']);
	}//end testAnAnsweringServerIsReachable()

	public function testAMovedFileIsUnreachableWithItsStatus(): void {
		$status = $this->probe(head: 404)->status(url: self::URL);

		$this->assertSame(404, $status);
		$this->assertFalse(DocumentReferenceProbe::isReachable(status: $status));
		$this->assertSame(['HEAD'], array_column($this->requests, 0));
	}//end testAMovedFileIsUnreachableWithItsStatus()

	public function testAServerThatRefusesHeadIsAskedForOneByte(): void {
		$status = $this->probe(head: 405, get: 206)->status(url: self::URL);

		$this->assertSame(206, $status);
		$this->assertTrue(DocumentReferenceProbe::isReachable(status: $status));
		$this->assertSame(['HEAD', 'GET'], array_column($this->requests, 0));
		$this->assertSame('bytes=0-0', $this->requests[1][2]['headers']['Range']);
	}//end testAServerThatRefusesHeadIsAskedForOneByte()

	public function testNoAnswerIsStatusZero(): void {
		$status = $this->probe(head: new \RuntimeException('timed out'))->status(url: self::URL);

		$this->assertSame(0, $status);
		$this->assertFalse(DocumentReferenceProbe::isReachable(status: $status));
	}//end testNoAnswerIsStatusZero()

	public function testAnAddressThatIsNotPublicIsNeverAsked(): void {
		$this->assertSame(0, $this->probe(head: 200)->status(url: 'https://10.0.0.12/besluit.pdf'));
		$this->assertSame([], $this->requests);
	}//end testAnAddressThatIsNotPublicIsNeverAsked()

	public function testOnlyTwoAndThreeHundredsAreReachable(): void {
		$this->assertSame(
			[false, true, true, true, false, false],
			array_map(static fn (int $status): bool => DocumentReferenceProbe::isReachable(status: $status), [199, 200, 302, 399, 400, 500])
		);
	}//end testOnlyTwoAndThreeHundredsAreReachable()
}//end class
