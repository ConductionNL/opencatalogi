<?php

/**
 * Unit tests for SuggestPublicationMetadata.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenCatalogi\BackgroundJob\SuggestPublicationMetadata;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\Woo\MetadataSuggestionStore;
use OCA\OpenCatalogi\Service\WooMetadataSuggestionService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The job reads the publication and what surrounds it, and stores each proposal as pending.
 */
#[CoversClass(SuggestPublicationMetadata::class)]
#[UsesClass(MetadataSuggestionStore::class)]
final class SuggestPublicationMetadataTest extends TestCase {

	/**
	 * What reached saveObject.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * What the rules floor was asked.
	 *
	 * @var array<int, array{0: array, 1: array}>
	 */
	private array $asked = [];

	/**
	 * The job over a doubled OpenRegister holding one publication and one catalogue.
	 *
	 * @param array<int, array<string, mixed>> $existing The suggestions already stored.
	 * @param bool $findThrows Whether reading the publication fails.
	 *
	 * @return SuggestPublicationMetadata
	 */
	private function job(array $existing=[], bool $findThrows=false): SuggestPublicationMetadata {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => [
				'publication_register' => '7',
				'metadata_suggestion_schema' => '30',
				'catalog_register' => '7',
				'catalog_schema' => '31',
			][$key] ?? ''
		);

		$objects = new class($existing, $findThrows) {
			/** @var array<int, array<string, mixed>> */
			public array $saved = [];

			/**
			 * @param array<int, array<string, mixed>> $existing
			 * @param bool $findThrows
			 */
			public function __construct(private array $existing, private bool $findThrows) {
			}

			public function find(string $id, string $register, string $schema): array {
				if ($this->findThrows === true) {
					throw new \RuntimeException('gone');
				}

				return ['id' => $id, 'title' => 'Besluit', 'wooCategory' => null];
			}

			public function searchObjectsPaginated(array $query, bool $_rbac, bool $_multitenancy): array {
				if ($query['@self']['schema'] === '31') {
					return ['results' => [
						['schemas' => [99], 'organization' => 'org-other'],
						['schemas' => [12, 13], 'organization' => 'org-1'],
					]];
				}

				return ['results' => $this->existing];
			}

			public function saveObject(array $object, ObjectSaveOptions $options, bool $_rbac, bool $_multitenancy): array {
				$this->saved[] = ['object' => $object, 'register' => $options->target->register, 'schema' => $options->target->schema, 'rbac' => $_rbac];
				return $object;
			}
		};

		$schemaMapper = new class {
			public function find(int $id): object {
				return new class {
					public function getSlug(): string {
						return 'besluit';
					}
				};
			}
		};
		$organisationMapper = new class {
			public function findByUuidFollowingMerge(string $uuid): object {
				return new class($uuid) {
					public function __construct(private string $uuid) {
					}

					public function getName(): string {
						return 'Gemeente ' . $this->uuid;
					}

					public function getTooi(): string {
						return 'gm0363';
					}
				};
			}
		};

		$lookup = $this->createMock(OpenRegisterLookup::class);
		$lookup->method('isInstalled')->willReturn(true);
		$lookup->method('objectService')->willReturn($objects);
		$lookup->method('installedService')->willReturnCallback(
			static fn (string $id): object => str_ends_with($id, 'SchemaMapper') === true ? $schemaMapper : $organisationMapper
		);

		/** @var WooMetadataSuggestionService&MockObject $rules */
		$rules = $this->createMock(WooMetadataSuggestionService::class);
		$rules->method('suggestFromRules')->willReturnCallback(
			function (array $publication, array $context): array {
				$this->asked[] = [$publication, $context];
				return [['field' => 'wooCategory', 'value' => 'c_1', 'valueLabel' => 'Besluiten', 'source' => 'rules', 'reason' => 'The schema is covered by one category.']];
			}
		);

		$this->saved = &$objects->saved;

		return new SuggestPublicationMetadata(
			$this->createMock(ITimeFactory::class),
			$rules,
			new MetadataSuggestionStore($config, $lookup),
			$lookup,
			$config,
			$this->createMock(LoggerInterface::class)
		);
	}//end job()

	/**
	 * Run the job's protected run().
	 *
	 * @param SuggestPublicationMetadata $job The job.
	 * @param mixed $argument Its argument.
	 *
	 * @return void
	 */
	private function runJob(SuggestPublicationMetadata $job, mixed $argument): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}//end runJob()

	public function testEachProposalIsStoredPendingOnThePublication(): void {
		$existing = [['field' => 'soortHandeling', 'value' => 'ontvangst']];
		$this->runJob($this->job(existing: $existing), ['register' => '7', 'schema' => '12', 'id' => 'pub-1']);

		$this->assertSame(
			[
				['id' => 'pub-1', 'title' => 'Besluit', 'wooCategory' => null],
				[
					'schemaSlug' => 'besluit',
					'catalogOrganization' => ['id' => 'org-1', 'name' => 'Gemeente org-1', 'tooiIdentifier' => 'gm0363'],
					'existing' => $existing,
				],
			],
			$this->asked[0]
		);
		$this->assertSame(
			[[
				'object' => [
					'publication' => 'pub-1',
					'field' => 'wooCategory',
					'value' => 'c_1',
					'valueLabel' => 'Besluiten',
					'source' => 'rules',
					'reason' => 'The schema is covered by one category.',
					'status' => 'pending',
				],
				'register' => '7',
				'schema' => '30',
				'rbac' => false,
			]],
			$this->saved
		);
	}//end testEachProposalIsStoredPendingOnThePublication()

	public function testAnIncompleteArgumentOrAnUnreadablePublicationStoresNothing(): void {
		$this->runJob($this->job(), ['register' => '7', 'schema' => '12']);
		$this->assertSame([], $this->asked);

		$this->runJob($this->job(findThrows: true), ['register' => '7', 'schema' => '12', 'id' => 'pub-1']);
		$this->assertSame([], $this->asked);
		$this->assertSame([], $this->saved);
	}//end testAnIncompleteArgumentOrAnUnreadablePublicationStoresNothing()
}//end class
