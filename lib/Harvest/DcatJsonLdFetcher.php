<?php

/**
 * Reads a DCAT catalogue published as JSON-LD for OpenRegister's harvest.
 *
 * OpenRegister owns the harvest: the source, its flow, change detection and
 * tombstones. This fetcher only reads the document a feed points at and hands
 * back every dataset in it, keyed by its `@id`. It reads the whole document in
 * one request, so OpenRegister never asks it for a single dataset.
 *
 * A document that cannot be fetched or parsed gives `complete: false` and no
 * items, so OpenRegister flags no harvested publication as gone.
 *
 * @category Harvest
 * @package  OCA\OpenCatalogi\Harvest
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#requirement-opencatalogi-registers-a-dcat-json-ld-fetcher-req-hfi-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Harvest;

use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Service\Sync\HarvestBatch;
use OCA\OpenRegister\Service\Sync\HarvestHttpClient;
use OCA\OpenRegister\Service\Sync\IBatchSourceFetcher;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The `opencatalogi.dcat-jsonld` source fetcher.
 *
 * @spec openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#requirement-opencatalogi-registers-a-dcat-json-ld-fetcher-req-hfi-001
 */
class DcatJsonLdFetcher implements IBatchSourceFetcher {

	/**
	 * The source type this fetcher serves.
	 */
	public const TYPE = 'opencatalogi.dcat-jsonld';

	/**
	 * The name forms list the type under.
	 */
	public const DISPLAY_NAME = 'DCAT catalogue (JSON-LD)';

	/**
	 * The reason a source slug cannot be read without integriq.
	 */
	public const NO_INTEGRIQ = 'integriq is not installed, so a source slug cannot be read';

	/**
	 * The integriq services that can read a registered source, in order.
	 *
	 * @var array<int, string>
	 */
	private const SOURCE_CALL_SERVICES = [
		'OCA\Integriq\Service\CallService',
		'OCA\OpenConnector\Service\CallService',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves integriq's source call when it is installed.
	 * @param LoggerInterface    $logger    Logger.
	 * @param DcatDatasetReader  $reader    Finds the datasets in a parsed document.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly DcatDatasetReader $reader = new DcatDatasetReader(),
	) {
	}//end __construct()

	/**
	 * Whether this fetcher serves the type.
	 *
	 * @param string $type The source type.
	 *
	 * @return bool True for `opencatalogi.dcat-jsonld`.
	 *
	 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-1.1
	 */
	public function supports(string $type): bool {
		return $type === self::TYPE;
	}//end supports()

	/**
	 * The source type.
	 *
	 * @return string The type.
	 *
	 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-1.1
	 */
	public function getType(): string {
		return self::TYPE;
	}//end getType()

	/**
	 * The name forms show for the type.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-1.1
	 */
	public function getDisplayName(): string {
		return self::DISPLAY_NAME;
	}//end getDisplayName()

	/**
	 * The config a feed carries: exactly one of `sourceUrl` and `sourceSlug`,
	 * and the catalogue its datasets land in.
	 *
	 * @return array<string, mixed> The JSON schema.
	 *
	 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-1.1
	 */
	public function getConfigSchema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'sourceUrl' => [
					'type' => 'string',
					'format' => 'uri',
					'title' => 'Source URL',
					'description' => 'The address of the DCAT catalogue in JSON-LD.',
				],
				'sourceSlug' => [
					'type' => 'string',
					'minLength' => 1,
					'title' => 'Source',
					'description' => 'An integriq source that answers with the DCAT catalogue.',
				],
				'targetCatalog' => [
					'type' => 'string',
					'format' => 'uuid',
					'title' => 'Catalogue',
					'description' => 'The catalogue the harvested datasets land in.',
				],
			],
			'required' => ['targetCatalog'],
			'oneOf' => [
				['required' => ['sourceUrl']],
				['required' => ['sourceSlug']],
			],
		];
	}//end getConfigSchema()

	/**
	 * Never asked: a batch fetcher hands its items over whole.
	 *
	 * @param Source      $source The source.
	 * @param string|null $since  The checkpoint.
	 *
	 * @return array<int, string> Nothing; it throws.
	 *
	 * @throws RuntimeException Always.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface's signature; a batch fetcher never reads them.
	 *
	 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-1.1
	 */
	public function gather(Source $source, ?string $since = null): array {
		throw new RuntimeException('The DCAT catalogue fetcher reads its datasets whole; ask gatherItems().');
	}//end gather()

	/**
	 * Never asked: a batch fetcher hands its items over whole.
	 *
	 * @param Source $source     The source.
	 * @param string $externalId The dataset.
	 *
	 * @return array<string, mixed> Nothing; it throws.
	 *
	 * @throws RuntimeException Always.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface's signature; a batch fetcher never reads the source.
	 *
	 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-1.1
	 */
	public function fetch(Source $source, string $externalId): array {
		throw new RuntimeException('The DCAT catalogue fetcher reads its datasets whole, not ' . $externalId . ' alone.');
	}//end fetch()

	/**
	 * Read the feed's document and return its datasets keyed by `@id`.
	 *
	 * @param Source            $source The feed.
	 * @param string|null       $since  Unused: a catalogue is always read whole.
	 * @param HarvestHttpClient $http   The guarded client for `sourceUrl`.
	 *
	 * @return HarvestBatch The datasets, the ones without `@id` as errors.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface hands a checkpoint a catalogue has no use for.
	 *
	 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-1.1
	 */
	public function gatherItems(Source $source, ?string $since, HarvestHttpClient $http): HarvestBatch {
		try {
			$document = $this->readDocument(config: ($source->getConfig() ?? []), http: $http);
		} catch (Throwable $e) {
			$this->logger->warning('[DcatJsonLdFetcher] ' . $e->getMessage());
			return new HarvestBatch(items: [], complete: false, errors: [['externalId' => null, 'message' => $e->getMessage()]]);
		}

		return $this->reader->batchOf(document: $document);
	}//end gatherItems()

	/**
	 * Read and parse the document the feed names.
	 *
	 * @param array<string, mixed> $config The feed config.
	 * @param HarvestHttpClient    $http   The guarded client.
	 *
	 * @return array<mixed> The parsed document.
	 *
	 * @throws RuntimeException When there is nothing to read, or it is not JSON.
	 */
	private function readDocument(array $config, HarvestHttpClient $http): array {
		$body = $this->rawDocument(config: $config, http: $http);
		if (is_array($body) === true) {
			return $body;
		}

		$decoded = json_decode($body, true);
		if (is_array($decoded) === false) {
			throw new RuntimeException('The source did not answer with a readable JSON-LD document.');
		}

		return $decoded;
	}//end readDocument()

	/**
	 * The document the feed names, as read: from its URL, else through
	 * integriq from its slug.
	 *
	 * @param array<string, mixed> $config The feed config.
	 * @param HarvestHttpClient    $http   The guarded client.
	 *
	 * @return string|array<mixed> The body, or the answer integriq already decoded.
	 *
	 * @throws RuntimeException When the feed names neither.
	 */
	private function rawDocument(array $config, HarvestHttpClient $http): string|array {
		$url = $config['sourceUrl'] ?? null;
		if (is_string($url) === true && $url !== '') {
			return $this->readUrl(url: $url, http: $http);
		}

		$slug = $config['sourceSlug'] ?? null;
		if (is_string($slug) === true && $slug !== '') {
			return $this->readSlug(slug: $slug);
		}

		throw new RuntimeException('The feed names neither a source URL nor a source slug.');
	}//end rawDocument()

	/**
	 * Read the document at a URL through OpenRegister's guarded client.
	 *
	 * @param string            $url  The address.
	 * @param HarvestHttpClient $http The guarded client.
	 *
	 * @return string The body.
	 *
	 * @throws RuntimeException When the source answers with an error status.
	 */
	private function readUrl(string $url, HarvestHttpClient $http): string {
		$response = $http->get(url: $url, headers: ['Accept' => 'application/ld+json, application/json']);
		$status   = $response->getStatusCode();
		if ($status >= 400) {
			throw new RuntimeException('The source answered with status ' . $status . '.');
		}

		return (string) $response->getBody();
	}//end readUrl()

	/**
	 * Read the document through integriq's registered source.
	 *
	 * @param string $slug The integriq source.
	 *
	 * @return string|array<mixed> The body, or the answer integriq already decoded.
	 *
	 * @throws RuntimeException When integriq is not installed or cannot read the source.
	 */
	private function readSlug(string $slug): string|array {
		$answer = $this->sourceCall()->call(source: $slug, endpoint: '', method: 'GET');

		if (is_object($answer) === true && method_exists($answer, 'getResponse') === true) {
			$answer = $answer->getResponse();
		}

		if (is_array($answer) === true && isset($answer['body']) === true && is_string($answer['body']) === true) {
			return $answer['body'];
		}

		if (is_string($answer) === true || is_array($answer) === true) {
			return $answer;
		}

		throw new RuntimeException('integriq answered something the DCAT catalogue fetcher cannot read for source ' . $slug . '.');
	}//end readSlug()

	/**
	 * Integriq's source call, or the reason there is none.
	 *
	 * @return object The call service.
	 *
	 * @throws RuntimeException When integriq is not installed.
	 */
	private function sourceCall(): object {
		foreach (self::SOURCE_CALL_SERVICES as $service) {
			try {
				return $this->container->get($service);
			} catch (Throwable) {
				continue;
			}
		}

		throw new RuntimeException(self::NO_INTEGRIQ);
	}//end sourceCall()
}//end class
