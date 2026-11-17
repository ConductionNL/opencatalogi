<?php

/**
 * Unit tests for MetadataSuggestionListener.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\BackgroundJob\MetadataSuggestionQueue;
use OCA\OpenCatalogi\BackgroundJob\SuggestPublicationMetadata;
use OCA\OpenCatalogi\Listener\MetadataSuggestionListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A new publication queues the rules floor; nothing else does.
 */
#[CoversClass(MetadataSuggestionListener::class)]
#[UsesClass(MetadataSuggestionQueue::class)]
final class MetadataSuggestionListenerTest extends TestCase {

	/**
	 * What the job list was given.
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $queued = [];

	/**
	 * The listener, with the publication register 7, suggestion schema 30 and catalogue schema 31,
	 * queueing through the real queue over the job list.
	 *
	 * @param IJobList|null $jobList A job list to use instead of the recording one.
	 *
	 * @return MetadataSuggestionListener
	 */
	private function listener(?IJobList $jobList=null): MetadataSuggestionListener {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => [
				'publication_register' => '7',
				'metadata_suggestion_schema' => '30',
				'catalog_schema' => '31',
			][$key] ?? ''
		);

		if ($jobList === null) {
			$jobList = $this->createMock(IJobList::class);
			$jobList->method('add')->willReturnCallback(
				function (string $job, mixed $argument): void {
					$this->queued[] = [$job, $argument];
				}
			);
		}

		return new MetadataSuggestionListener($config, new MetadataSuggestionQueue($jobList), $this->createMock(LoggerInterface::class));
	}//end listener()

	/**
	 * An object as OpenRegister hands it over.
	 *
	 * @param string $register Its register.
	 * @param string $schema Its schema.
	 * @param string $uuid Its uuid.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $register, string $schema, string $uuid='pub-1'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setRegister($register);
		$object->setSchema($schema);
		$object->setUuid($uuid);
		return $object;
	}//end object()

	public function testANewPublicationQueuesTheRulesFloor(): void {
		$this->listener()->handle(new ObjectCreatedEvent($this->object(register: '7', schema: '12')));

		$this->assertSame(
			[[SuggestPublicationMetadata::class, ['register' => '7', 'schema' => '12', 'id' => 'pub-1']]],
			$this->queued
		);
	}//end testANewPublicationQueuesTheRulesFloor()

	public function testOtherRegistersSuggestionsCataloguesAndUpdatesQueueNothing(): void {
		$listener = $this->listener();

		$listener->handle(new ObjectCreatedEvent($this->object(register: '8', schema: '12')));
		$listener->handle(new ObjectCreatedEvent($this->object(register: '7', schema: '30')));
		$listener->handle(new ObjectCreatedEvent($this->object(register: '7', schema: '31')));
		$listener->handle(new ObjectCreatedEvent($this->object(register: '7', schema: '12', uuid: '')));
		$listener->handle(new ObjectUpdatedEvent($this->object(register: '7', schema: '12'), $this->object(register: '7', schema: '12')));

		$this->assertSame([], $this->queued);
	}//end testOtherRegistersSuggestionsCataloguesAndUpdatesQueueNothing()

	public function testAFailingQueueDoesNotStopTheSave(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willThrowException(new \RuntimeException('database gone'));

		$this->listener(jobList: $jobList)->handle(new ObjectCreatedEvent($this->object(register: '7', schema: '12')));

		$this->addToAssertionCount(1);
	}//end testAFailingQueueDoesNotStopTheSave()
}//end class
