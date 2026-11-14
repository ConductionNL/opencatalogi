<?php

/**
 * Unit tests for CompletenessSweep and CompletenessSweepService: a publication
 * whose publication date passed since the previous sweep is evaluated and
 * saved when its completeness changed, and the job is registered.
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
 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-records-whether-it-is-complete-and-public-req-pts-002
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use DateTime;
use DateTimeImmutable;
use OCA\OpenCatalogi\BackgroundJob\CompletenessSweep;
use OCA\OpenCatalogi\Service\Publication\CompletenessService;
use OCA\OpenCatalogi\Service\Publication\CompletenessSweepService;
use OCA\OpenCatalogi\Service\Publication\PublicationStateService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\BackgroundJob\CompletenessSweep
 * @covers \OCA\OpenCatalogi\Service\Publication\CompletenessSweepService
 * @uses   \OCA\OpenCatalogi\Service\Publication\CompletenessService
 * @uses   \OCA\OpenCatalogi\Service\Publication\PublicationStateService
 */
final class CompletenessSweepTest extends TestCase {

	/**
	 * The sweep's moment.
	 *
	 * @var string
	 */
	private const NOW = '2026-11-14T10:00:00+00:00';

	/**
	 * The objects saved, as [object, uuid].
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: string|null}>
	 */
	private array $saved = [];

	/**
	 * A publication whose date passed a minute ago, every file shared, stored incomplete: stored complete.
	 *
	 * @return void
	 */
	public function testAPublicationWhoseDatePassedIsMarkedComplete(): void {
		$due = ['title' => 'Due', 'publicationDate' => '2026-11-14T09:59:00+00:00', 'completeness' => 'incomplete'];
		$job = $this->job(rows: ['pub-1' => $due], files: [['share_token' => 'a']], mark: '2026-11-14T09:45:00+00:00');

		$this->runJob(job: $job);

		self::assertCount(1, $this->saved);
		[$object, $uuid] = $this->saved[0];
		self::assertSame('pub-1', $uuid);
		self::assertSame('complete', $object['completeness']);
		self::assertSame('2026-11-14T10:00:00+00:00', $object['completeAt']);
		self::assertSame('Due', $object['title']);
	}//end testAPublicationWhoseDatePassedIsMarkedComplete()

	/**
	 * Publications outside the window, and an unchanged one, are not saved; a failing one is counted.
	 *
	 * @return void
	 */
	public function testOnlyChangedPublicationsInTheWindowAreSaved(): void {
		$rows = [
			'old'       => ['publicationDate' => '2026-11-01T00:00:00+00:00', 'completeness' => 'incomplete'],
			'future'    => ['publicationDate' => '2026-11-14T10:05:00+00:00'],
			'unchanged' => ['publicationDate' => '2026-11-14T09:50:00+00:00', 'completeness' => 'complete', 'completeAt' => '2026-11-14T09:50:00+00:00'],
			'withdrawn' => ['publicationDate' => '2026-11-01T00:00:00+00:00', 'depublicationDate' => '2026-11-14T09:55:00+00:00', 'completeness' => 'complete'],
		];
		$service = $this->service(rows: $rows, files: [['share_token' => 'a']]);

		$counts = $service->sweep(since: new DateTimeImmutable('2026-11-14T09:45:00+00:00'), now: new DateTimeImmutable(self::NOW));

		self::assertSame(['checked' => 2, 'changed' => 1, 'failed' => 0], $counts);
		self::assertSame('withdrawn', $this->saved[0][1]);
		self::assertSame('incomplete', $this->saved[0][0]['completeness']);

		$failing = $this->service(rows: ['due' => ['publicationDate' => '2026-11-14T09:50:00+00:00']], files: new RuntimeException('gone'));
		self::assertSame(['checked' => 1, 'changed' => 0, 'failed' => 1], $failing->sweep(since: new DateTimeImmutable('2026-11-14T09:45:00+00:00'), now: new DateTimeImmutable(self::NOW)));
	}//end testOnlyChangedPublicationsInTheWindowAreSaved()

	/**
	 * Without a configured publication schema nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutAPublicationSchemaNothingIsRead(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$service = new CompletenessSweepService(
			completeness: new CompletenessService(states: new PublicationStateService(), container: $container),
			container: $container,
			config: $config,
			logger: $this->createMock(LoggerInterface::class)
		);

		self::assertSame(['checked' => 0, 'changed' => 0, 'failed' => 0], $service->sweep(since: new DateTimeImmutable('-1 hour'), now: new DateTimeImmutable()));
	}//end testWithoutAPublicationSchemaNothingIsRead()

	/**
	 * The job is registered in appinfo/info.xml and runs every fifteen minutes.
	 *
	 * @return void
	 */
	public function testTheJobIsRegistered(): void {
		$info = simplexml_load_file(dirname(__DIR__, 3) . '/appinfo/info.xml');
		$jobs = array_map('strval', iterator_to_array($info->{'background-jobs'}->job, false));
		self::assertContains(CompletenessSweep::class, $jobs);

		$job = $this->job(rows: [], files: [], mark: '');
		self::assertSame(900, $job->getInterval());
	}//end testTheJobIsRegistered()

	/**
	 * The job remembers this sweep, and sweeps fifteen minutes back without a mark.
	 *
	 * @return void
	 */
	public function testTheJobMovesItsMarkOnlyAfterASweep(): void {
		$config = null;
		$job    = $this->job(rows: [], files: [], mark: '', config: $config);
		$config->expects(self::once())->method('setValueString')->with('opencatalogi', CompletenessSweep::LAST_RUN_KEY, self::NOW);

		$this->runJob(job: $job);
	}//end testTheJobMovesItsMarkOnlyAfterASweep()

	/**
	 * Run the job's protected run().
	 *
	 * @param CompletenessSweep $job The job.
	 *
	 * @return void
	 */
	private function runJob(CompletenessSweep $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * The job over the sweep service, with this mark.
	 *
	 * @param array<string, array<string, mixed>>               $rows   The stored publications by uuid.
	 * @param array<int, array<string, mixed>>|RuntimeException $files  The files every publication has, or the failure.
	 * @param string                                            $mark   The previous sweep, '' for none.
	 * @param IAppConfig|null                                   $config Receives the config double.
	 *
	 * @return CompletenessSweep The job.
	 */
	private function job(array $rows, array|RuntimeException $files, string $mark, ?IAppConfig &$config = null): CompletenessSweep {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime(self::NOW));

		$service = $this->service(rows: $rows, files: $files, mark: $mark, config: $config);

		return new CompletenessSweep(time: $time, sweeps: $service, config: $config, logger: $this->createMock(LoggerInterface::class));
	}//end job()

	/**
	 * The sweep service over an object store holding these publications.
	 *
	 * @param array<string, array<string, mixed>>               $rows   The stored publications by uuid.
	 * @param array<int, array<string, mixed>>|RuntimeException $files  The files, or the failure.
	 * @param string                                            $mark   The previous sweep, '' for none.
	 * @param IAppConfig|null                                   $config Receives the config double.
	 *
	 * @return CompletenessSweepService The service.
	 */
	private function service(array $rows, array|RuntimeException $files, string $mark = '', ?IAppConfig &$config = null): CompletenessSweepService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			static function (array $query) use ($rows): array {
				self::assertSame(['register' => 'pubs', 'schema' => '12'], $query['@self']);
				if ($query['_page'] > 1) {
					return ['results' => []];
				}

				$listed = [];
				foreach ($rows as $uuid => $data) {
					$listed[] = $data + ['@self' => ['id' => $uuid]];
				}

				return ['results' => $listed];
			}
		);
		$objects->method('find')->willReturnCallback(
			static function ($id) use ($rows): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject($rows[$id]);
				return $entity;
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

		$mapper = $this->createMock(FileMapper::class);
		if ($files instanceof RuntimeException) {
			$mapper->method('getFilesForObject')->willThrowException($files);
		} else {
			$mapper->method('getFilesForObject')->willReturn($files);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => ($id === CompletenessService::FILE_MAPPER ? $mapper : $objects)
		);

		/** @var IAppConfig&MockObject $config */
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'publication_register' => 'pubs',
				'publication_schema' => '12',
				CompletenessSweep::LAST_RUN_KEY => $mark,
				default => $default,
			}
		);

		return new CompletenessSweepService(
			completeness: new CompletenessService(states: new PublicationStateService(), container: $container),
			container: $container,
			config: $config,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end service()
}//end class
