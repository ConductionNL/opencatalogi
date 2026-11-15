<?php

/**
 * A search answer whose results are not a list is refused explicitly.
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

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DcatService;
use OCA\OpenCatalogi\Service\SchemaOrgService;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The catalog node's dataset refs over a malformed search answer.
 *
 * @covers \OCA\OpenCatalogi\Service\SchemaOrgService
 * @uses   \OCA\OpenCatalogi\Service\CallerScope
 */
class SchemaOrgNonListResultTest extends TestCase {

	/**
	 * Results that are not a list.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function answers(): array {
		return [
			'results as text' => ['not a list'],
			'results as a number' => [42],
			'results as an object' => [new \stdClass()],
		];

	}//end answers()

	/**
	 * No refs, and a warning naming the catalog.
	 *
	 * @param mixed $results The `results` value of the search answer.
	 *
	 * @return void
	 */
	#[DataProvider('answers')]
	public function testANonListResultYieldsNoRefsAndAWarning(mixed $results): void {
		$objects = new class($results) {
			public function __construct(private mixed $results) {
			}

			public function searchObjectsPaginated(array $query = [], bool $_rbac = true, bool $_multitenancy = true, bool $deleted = false): array {
				return ['results' => $this->results];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$dcat = $this->createMock(DcatService::class);
		$dcat->method('resolveDefaults')->willReturn(['publisherName' => '', 'publisherUri' => '', 'license' => '', 'contactPoint' => '', 'organisation' => null]);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getBaseUrl')->willReturn('https://host');
		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		$node = (new SchemaOrgService($container, $dcat, $url, $logger))->buildCatalogNode(['title' => 'T', 'registers' => [1], 'schemas' => [11]], 'woo');

		$this->assertSame([], $node['dataset']);
		$this->assertSame([['[SchemaOrgService] Catalog dataset listing returned no list', ['catalog' => 'woo']]], $warnings);

	}//end testANonListResultYieldsNoRefsAndAWarning()
}//end class
