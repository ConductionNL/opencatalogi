<?php

/**
 * Tests for PublicationQuality: one publication's score, read under RBAC (PQM-001).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-2.5
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Contract\DcatServiceInterface;
use OCA\OpenCatalogi\Contract\QualityServiceInterface;
use OCA\OpenCatalogi\Service\PublicationQuality;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(PublicationQuality::class)]
final class PublicationQualityTest extends TestCase {

	private DcatServiceInterface&MockObject $dcat;
	private QualityServiceInterface&MockObject $quality;
	private ContainerInterface&MockObject $container;
	private LoggerInterface&MockObject $logger;

	/** @var list<array{where: array<string, string>, rbac: bool}> */
	public array $searches = [];

	/** @var array<string, array<string, mixed>> readable publications by "register/schema/uuid" */
	public array $readable = [];

	private const CATALOG = ['id' => 'cat-1', 'registers' => ['7', ['id' => 8]], 'schemas' => ['21', ['id' => 22]]];

	protected function setUp(): void {
		$this->dcat = $this->createMock(DcatServiceInterface::class);
		$this->quality = $this->createMock(QualityServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$objects = new class($this) {
			public function __construct(private PublicationQualityTest $test) {
			}

			public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$where = $query['@self'];
				$this->test->searches[] = ['where' => $where, 'rbac' => $_rbac];
				$key = $where['register'] . '/' . $where['schema'] . '/' . $where['uuid'];
				if ($key === '8/21/p-boom') {
					throw new RuntimeException('register down');
				}

				return isset($this->test->readable[$key]) === true ? [$this->test->readable[$key]] : [];
			}
		};
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objects);
	}

	private function service(): PublicationQuality {
		return new PublicationQuality($this->dcat, $this->quality, $this->container, $this->logger);
	}

	public function testScoresTheDatasetTheReadablePublicationRendersAs(): void {
		$publication = ['id' => 'p-1', 'title' => 'Besluit', 'status' => 'concept'];
		$this->readable['8/21/p-1'] = $publication;
		$node = ['@type' => 'dcat:Dataset', 'dct:title' => 'Besluit'];
		$score = ['total' => 40, 'dimensions' => ['findability' => 50], 'missing' => ['dct:license', 'dcat:theme']];
		$this->dcat->expects($this->once())->method('buildPublicationDataset')->with(self::CATALOG, 'woo', $publication)->willReturn($node);
		$this->quality->expects($this->once())->method('scoreDataset')->with($node)->willReturn($score);

		$this->assertSame($score, $this->service()->score(catalog: self::CATALOG, catalogSlug: 'woo', id: 'p-1'));
		$this->assertSame(
			[
				['where' => ['register' => '7', 'schema' => '21', 'uuid' => 'p-1'], 'rbac' => true],
				['where' => ['register' => '7', 'schema' => '22', 'uuid' => 'p-1'], 'rbac' => true],
				['where' => ['register' => '8', 'schema' => '21', 'uuid' => 'p-1'], 'rbac' => true],
			],
			$this->searches
		);
	}

	public function testAPublicationTheCallerCannotReadIsNotScored(): void {
		$this->dcat->expects($this->never())->method('buildPublicationDataset');
		$this->quality->expects($this->never())->method('scoreDataset');

		$this->assertNull($this->service()->score(catalog: self::CATALOG, catalogSlug: 'woo', id: 'p-other'));
		$this->assertCount(4, $this->searches);
	}

	public function testAFailedReadIsLoggedAndTheSearchGoesOn(): void {
		$this->readable['8/22/p-boom'] = ['id' => 'p-boom'];
		$this->logger->expects($this->once())->method('warning')->with('[PublicationQuality] publication read failed', ['error' => 'register down']);
		$this->dcat->method('buildPublicationDataset')->willReturn([]);
		$this->quality->method('scoreDataset')->willReturn(['total' => 0, 'dimensions' => [], 'missing' => []]);

		$this->assertSame(['total' => 0, 'dimensions' => [], 'missing' => []], $this->service()->score(catalog: self::CATALOG, catalogSlug: 'woo', id: 'p-boom'));
	}

	public function testNoIdOrNoOpenRegisterGivesNothing(): void {
		$this->assertNull($this->service()->score(catalog: self::CATALOG, catalogSlug: 'woo', id: ''));
		$this->assertSame([], $this->searches);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not installed'));
		$this->container = $container;
		$this->assertNull($this->service()->score(catalog: self::CATALOG, catalogSlug: 'woo', id: 'p-1'));
	}
}
