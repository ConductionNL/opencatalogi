<?php

/**
 * Unit tests for PublicationLinksRewrite and PublicationLinksRewriteService:
 * after a link setting changed, every publication whose pages differ is saved
 * with the pages computed now.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-carries-its-public-page-and-its-officer-page-req-pts-001
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenCatalogi\BackgroundJob\PublicationLinksRewrite;
use OCA\OpenCatalogi\Service\Publication\PublicationLinks;
use OCA\OpenCatalogi\Service\Publication\PublicationLinksRewriteService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\BackgroundJob\PublicationLinksRewrite
 * @covers \OCA\OpenCatalogi\Service\Publication\PublicationLinksRewriteService
 */
final class PublicationLinksRewriteTest extends TestCase {

	/**
	 * The objects saved, as [object, uuid].
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: string|null}>
	 */
	private array $saved = [];

	/**
	 * Only a publication whose pages differ is saved, with its other properties and the new pages; a failure is counted.
	 *
	 * @return void
	 */
	public function testOnlyPublicationsWithChangedPagesAreSaved(): void {
		$rows = [
			['title' => 'Old', 'publicUrl' => 'https://old.example/a', 'officerUrl' => 'https://oc/woo/a', '@self' => ['id' => 'a']],
			['title' => 'Current', 'publicUrl' => 'https://new.example/b', 'officerUrl' => 'https://oc/woo/b', '@self' => ['id' => 'b']],
			['title' => 'Never linked', '@self' => ['id' => 'c']],
			['title' => 'Breaks', '@self' => ['id' => 'boom']],
			'not a row',
		];

		$counts = $this->service(rows: $rows)->rewrite();

		self::assertSame(['checked' => 4, 'changed' => 2, 'failed' => 1], $counts);
		self::assertSame(
			[
				[['title' => 'Old', 'publicUrl' => 'https://new.example/a', 'officerUrl' => 'https://oc/woo/a'], 'a'],
				[['title' => 'Never linked', 'publicUrl' => 'https://new.example/c', 'officerUrl' => 'https://oc/woo/c'], 'c'],
			],
			$this->saved
		);
	}//end testOnlyPublicationsWithChangedPagesAreSaved()

	/**
	 * Without a configured publication register or schema nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutAPublicationSchemaNothingIsRead(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$service = new PublicationLinksRewriteService(
			links: $this->createMock(PublicationLinks::class),
			container: $container,
			config: $config,
			logger: $this->createMock(LoggerInterface::class)
		);

		self::assertSame(['checked' => 0, 'changed' => 0, 'failed' => 0], $service->rewrite());
	}//end testWithoutAPublicationSchemaNothingIsRead()

	/**
	 * The queued job runs the pass and logs its counts; a failing pass is logged as an error.
	 *
	 * @return void
	 */
	public function testTheJobRunsThePass(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info')->with(self::anything(), ['checked' => 1, 'changed' => 1, 'failed' => 0]);
		$job = new PublicationLinksRewrite(
			time: $this->createMock(ITimeFactory::class),
			rewrites: $this->service(rows: [['@self' => ['id' => 'a']]]),
			logger: $logger
		);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
		self::assertSame('a', $this->saved[0][1]);

		$failing = $this->createMock(PublicationLinksRewriteService::class);
		$failing->method('rewrite')->willThrowException(new RuntimeException('database is down'));
		$errors = $this->createMock(LoggerInterface::class);
		$errors->expects(self::once())->method('error')->with(self::stringContains('database is down'));
		$job = new PublicationLinksRewrite(time: $this->createMock(ITimeFactory::class), rewrites: $failing, logger: $errors);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end testTheJobRunsThePass()

	/**
	 * The pass over an object store listing these rows on one page; uuid `boom` fails to compute.
	 *
	 * @param array<int, mixed> $rows The listed publications.
	 *
	 * @return PublicationLinksRewriteService The service.
	 */
	private function service(array $rows): PublicationLinksRewriteService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			static function (array $query) use ($rows): array {
				self::assertSame(['register' => 'pubs', 'schema' => '12'], $query['@self']);
				return ['results' => ($query['_page'] === 1 ? $rows : [])];
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (): ObjectEntity {
				// Positional as PHPUnit hands them on: object, extend, register, schema, uuid.
				$args = func_get_args();
				self::assertSame(['pubs', '12'], [$args[2], $args[3]]);
				$this->saved[] = [$args[0], $args[4]];
				return new ObjectEntity();
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($objects);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'publication_register' => 'pubs',
				'publication_schema' => '12',
				default => $default,
			}
		);

		$links = $this->createMock(PublicationLinks::class);
		$links->method('for')->willReturnCallback(
			static function (string $uuid, string $schema): array {
				self::assertSame('12', $schema);
				if ($uuid === 'boom') {
					throw new RuntimeException('no catalogue register');
				}

				return ['publicUrl' => 'https://new.example/' . $uuid, 'officerUrl' => 'https://oc/woo/' . $uuid];
			}
		);

		return new PublicationLinksRewriteService(links: $links, container: $container, config: $config, logger: $this->createMock(LoggerInterface::class));
	}//end service()
}//end class
