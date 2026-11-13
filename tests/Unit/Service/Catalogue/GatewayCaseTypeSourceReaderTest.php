<?php

/**
 * Unit tests for GatewayCaseTypeSourceReader's public reads.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Catalogue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Catalogue;

use OCA\OpenCatalogi\Service\Catalogue\ExternalCatalogueUnreachableException;
use OCA\OpenCatalogi\Service\Catalogue\GatewayCaseTypeSourceReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Reading case type definitions from an external catalogue through the gateway.
 */
#[CoversClass(GatewayCaseTypeSourceReader::class)]
final class GatewayCaseTypeSourceReaderTest extends TestCase {

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Wire the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A gateway answering $answer (or throwing it) and recording each call.
	 *
	 * @param mixed $answer What call() returns, or a Throwable it throws.
	 *
	 * @return object
	 */
	private function gateway(mixed $answer): object {
		return new class($answer) {
			/**
			 * Every call as [source, endpoint, method].
			 *
			 * @var list<array{0:string,1:string,2:string}>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $answer The answer.
			 */
			public function __construct(private readonly mixed $answer) {
			}

			/**
			 * Answer a call.
			 *
			 * @param string $source   The source.
			 * @param string $endpoint The endpoint.
			 * @param string $method   The HTTP method.
			 *
			 * @return mixed
			 */
			public function call(string $source, string $endpoint, string $method): mixed {
				$this->calls[] = [$source, $endpoint, $method];
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};
	}//end gateway()

	/**
	 * A reader whose container offers the gateway under the given service ids.
	 *
	 * @param object|null          $gateway  The gateway, or null for none.
	 * @param array<int,string>    $services The ids it is offered under.
	 *
	 * @return GatewayCaseTypeSourceReader
	 */
	private function reader(?object $gateway, array $services = ['OCA\Integriq\Service\CallService']): GatewayCaseTypeSourceReader {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($gateway, $services): object {
				if ($gateway !== null && in_array($id, $services, true) === true) {
					return $gateway;
				}

				throw new RuntimeException('not installed: ' . $id);
			}
		);

		return new GatewayCaseTypeSourceReader(container: $container, logger: $this->logger);
	}//end reader()

	/**
	 * A definition is read from its own endpoint, its external id URL-encoded.
	 *
	 * @return void
	 */
	public function testFetchDefinitionAsksForTheEncodedId(): void {
		$gateway = $this->gateway('{"identificatie":"VERG 1","omschrijving":"Vergunning"}');

		$definition = $this->reader($gateway)->fetchDefinition(sourceId: 'src-1', externalId: 'VERG 1/a');

		self::assertSame(['identificatie' => 'VERG 1', 'omschrijving' => 'Vergunning'], $definition);
		self::assertSame([['src-1', 'zaaktypen/VERG%201%2Fa', 'GET']], $gateway->calls);
	}//end testFetchDefinitionAsksForTheEncodedId()

	/**
	 * A paged answer yields its first result; the older gateway is used when the new one is absent.
	 *
	 * @return void
	 */
	public function testFetchDefinitionTakesTheFirstResultFromTheOlderGateway(): void {
		$response = new class() {
			/**
			 * The raw response.
			 *
			 * @return array<string,mixed>
			 */
			public function getResponse(): array {
				return ['body' => '{"results":[{"id":"first"},{"id":"second"}]}', 'status' => 200];
			}
		};
		$gateway = $this->gateway($response);

		$definition = $this->reader($gateway, ['OCA\OpenConnector\Service\CallService'])->fetchDefinition(sourceId: 's', externalId: 'x');

		self::assertSame(['id' => 'first'], $definition);
	}//end testFetchDefinitionTakesTheFirstResultFromTheOlderGateway()

	/**
	 * An empty answer, or an empty page, is unreadable for that id.
	 *
	 * @return void
	 */
	public function testFetchDefinitionRefusesAnEmptyAnswer(): void {
		foreach (['{"results":[]}', '{}'] as $answer) {
			try {
				$this->reader($this->gateway($answer))->fetchDefinition(sourceId: 's', externalId: 'ZT-9');
				self::fail('An empty answer was accepted.');
			} catch (ExternalCatalogueUnreachableException $e) {
				self::assertSame('The external case type catalogue answered nothing readable for ZT-9.', $e->getMessage());
			}
		}
	}//end testFetchDefinitionRefusesAnEmptyAnswer()

	/**
	 * listDefinitions keeps only the array entries of the results, or of a bare list.
	 *
	 * @return void
	 */
	public function testListDefinitionsKeepsTheArrayEntries(): void {
		$paged = $this->gateway(['results' => [['id' => 'a'], 'junk', ['id' => 'b']]]);
		self::assertSame([['id' => 'a'], ['id' => 'b']], $this->reader($paged)->listDefinitions(sourceId: 's'));
		self::assertSame([['s', 'zaaktypen', 'GET']], $paged->calls);

		$bare = $this->gateway('[{"id":"c"},7]');
		self::assertSame([['id' => 'c']], $this->reader($bare)->listDefinitions(sourceId: 's'));
	}//end testListDefinitionsKeepsTheArrayEntries()

	/**
	 * Results that are not a list are refused.
	 *
	 * @return void
	 */
	public function testListDefinitionsRefusesResultsThatAreNotAList(): void {
		$this->expectException(ExternalCatalogueUnreachableException::class);
		$this->expectExceptionMessage('The external case type catalogue answered something this app cannot read.');

		$this->reader($this->gateway(['results' => 'none']))->listDefinitions(sourceId: 's');
	}//end testListDefinitionsRefusesResultsThatAreNotAList()

	/**
	 * A failing gateway call is logged and refused with its cause attached.
	 *
	 * @return void
	 */
	public function testAFailingCallIsLoggedAndRefused(): void {
		$cause = new RuntimeException('connection refused');
		$this->logger->expects(self::once())->method('warning')
			->with('[GatewayCaseTypeSourceReader] The gateway call failed: connection refused');

		try {
			$this->reader($this->gateway($cause))->listDefinitions(sourceId: 's');
			self::fail('A failing call was not refused.');
		} catch (ExternalCatalogueUnreachableException $e) {
			self::assertSame('The external case type catalogue could not be reached: connection refused', $e->getMessage());
			self::assertSame($cause, $e->getPrevious());
		}
	}//end testAFailingCallIsLoggedAndRefused()

	/**
	 * Without any gateway installed the reader says so.
	 *
	 * @return void
	 */
	public function testWithoutAGatewayTheReaderSaysSo(): void {
		$this->logger->expects(self::once())->method('warning')
			->with('[GatewayCaseTypeSourceReader] No gateway is installed, so no external catalogue can be asked.');
		$this->expectException(ExternalCatalogueUnreachableException::class);
		$this->expectExceptionMessage('The external case type catalogue cannot be asked: the gateway is not installed.');

		$this->reader(null)->fetchDefinition(sourceId: 's', externalId: 'x');
	}//end testWithoutAGatewayTheReaderSaysSo()
}//end class
