<?php

/**
 * Tests for raising Woo pipeline incidents.
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

namespace Unit\Service\Woo;

use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Service\SchemaObjects;
use OCA\OpenCatalogi\Service\Woo\PipelineIncidents;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * OpenRegister's ObjectService is a recording double: it answers the incidents
 * the test seeds and records what is saved.
 *
 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-a-pipeline-failure-reaches-a-named-group-req-wno-004
 */
#[CoversClass(PipelineIncidents::class)]
#[UsesClass(SchemaObjects::class)]
class PipelineIncidentsTest extends TestCase {

	/**
	 * Now: 2026-11-17T12:00:00Z.
	 */
	private const NOW = 1794916800;

	/**
	 * The ObjectService double.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the service over the given stored incidents.
	 *
	 * @param list<array<string, mixed>> $stored The incidents OpenRegister holds.
	 * @param bool                       $fail   Whether saving throws.
	 *
	 * @return PipelineIncidents
	 */
	private function incidents(array $stored, bool $fail = false): PipelineIncidents {
		$this->objects = new class($stored, $fail) {
			/** @var list<array<string, mixed>> */
			public array $saved = [];
			/** @var list<array<string, mixed>> */
			public array $queries = [];

			/**
			 * @param list<array<string, mixed>> $stored The stored incidents.
			 * @param bool $fail Whether a save throws.
			 */
			public function __construct(private array $stored, private bool $fail) {
			}

			/**
			 * @param array<string, mixed> $query The query.
			 * @return array<string, mixed>
			 */
			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->queries[] = $query;
				return ['results' => $this->stored];
			}

			/**
			 * @param array<string, mixed> $object The object.
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, mixed $options = null, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->fail === true) {
					throw new \RuntimeException('database is read-only');
				}

				$this->saved[] = $object;
				return $object + ['id' => 'new-incident'];
			}
		};

		$openRegister = $this->createMock(OpenRegisterLookupInterface::class);
		$openRegister->method('registerConfiguration')
			->with('woo_register', 'woo_pipeline_incident_schema')
			->willReturn(['register' => 'publication', 'schema' => 'wooPipelineIncident']);
		$openRegister->method('installedObjectService')->willReturn($this->objects);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$this->logger = $this->createMock(LoggerInterface::class);

		return new PipelineIncidents(openRegister: $openRegister, time: $time, logger: $this->logger);
	}//end incidents()

	/**
	 * A stored incident.
	 *
	 * @param string      $at         When it was raised.
	 * @param string|null $resolvedAt When it was resolved.
	 * @param string      $message    Its message.
	 *
	 * @return array<string, mixed>
	 */
	private function stored(string $at, ?string $resolvedAt = null, string $message = 'PLOOI answered 502'): array {
		return [
			'id' => 'old',
			'kind' => 'plooi-delivery',
			'subject' => 'pub-1',
			'message' => $message,
			'at' => $at,
			'resolvedAt' => $resolvedAt,
		];
	}//end stored()

	public function testARaisedIncidentIsStored(): void {
		$this->incidents(stored: [])->raise(kind: 'plooi-delivery', subject: 'pub-1', message: 'PLOOI answered 502');

		$this->assertSame(
			[['kind' => 'plooi-delivery', 'subject' => 'pub-1', 'message' => 'PLOOI answered 502', 'at' => '2026-11-17T12:00:00+00:00']],
			$this->objects->saved
		);
		$this->assertSame(['register' => 'publication', 'schema' => 'wooPipelineIncident'], $this->objects->queries[0]['@self']);
		$this->assertSame('plooi-delivery', $this->objects->queries[0]['kind']);
		$this->assertSame('pub-1', $this->objects->queries[0]['subject']);
	}//end testARaisedIncidentIsStored()

	public function testAnIdenticalOpenIncidentIsCoalesced(): void {
		$this->incidents(stored: [$this->stored(at: '2026-11-17T01:00:00+00:00')])
			->raise(kind: 'plooi-delivery', subject: 'pub-1', message: 'PLOOI answered 502');

		$this->assertSame([], $this->objects->saved);
	}//end testAnIdenticalOpenIncidentIsCoalesced()

	public function testAnIncidentOlderThanADayIsNotCoalesced(): void {
		$this->incidents(stored: [$this->stored(at: '2026-11-16T11:59:59+00:00')])
			->raise(kind: 'plooi-delivery', subject: 'pub-1', message: 'PLOOI answered 502');

		$this->assertCount(1, $this->objects->saved);
	}//end testAnIncidentOlderThanADayIsNotCoalesced()

	public function testAResolvedIncidentIsNotCoalesced(): void {
		$this->incidents(stored: [$this->stored(at: '2026-11-17T01:00:00+00:00', resolvedAt: '2026-11-17T02:00:00+00:00')])
			->raise(kind: 'plooi-delivery', subject: 'pub-1', message: 'PLOOI answered 502');

		$this->assertCount(1, $this->objects->saved);
	}//end testAResolvedIncidentIsNotCoalesced()

	public function testADifferentMessageIsNotCoalesced(): void {
		$this->incidents(stored: [$this->stored(at: '2026-11-17T01:00:00+00:00', message: 'PLOOI answered 400')])
			->raise(kind: 'plooi-delivery', subject: 'pub-1', message: 'PLOOI answered 502');

		$this->assertCount(1, $this->objects->saved);
	}//end testADifferentMessageIsNotCoalesced()

	public function testAnIncidentThatCannotBeStoredIsLoggedNotThrown(): void {
		$incidents = $this->incidents(stored: [], fail: true);
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('plooi-delivery pub-1: PLOOI answered 502'));

		$incidents->raise(kind: 'plooi-delivery', subject: 'pub-1', message: 'PLOOI answered 502');

		$this->assertSame([], $this->objects->saved);
	}//end testAnIncidentThatCannotBeStoredIsLoggedNotThrown()
}//end class
