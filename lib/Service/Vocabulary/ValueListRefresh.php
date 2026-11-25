<?php

/**
 * OpenCatalogi value-list refresh: each scheme from its national source, guarded.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Vocabulary
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
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Vocabulary;

use DateTimeInterface;
use DateTimeZone;
use OCA\OpenCatalogi\Contract\AppIdentity;
use OCA\OpenCatalogi\Contract\VocabularyRegisterInterface;
use OCA\OpenCatalogi\Service\Woo\PipelineIncidents;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Downloads each value list OpenCatalogi uses and imports it into OpenRegister's
 * vocabulary register, so a new TOOI entry is usable the next day without a release.
 *
 * A failed or unreadable download imports nothing, and neither does one that would
 * deprecate more than a third of the scheme's active concepts: a truncated answer
 * must not empty a list that publications point at. Both keep the scheme as it is,
 * store the reason and raise a pipeline incident.
 */
class ValueListRefresh {

	/**
	 * The app config key of the sources, a JSON list of `{key, scheme, url}`.
	 *
	 * @var string
	 */
	public const SOURCES_KEY = 'tooi_scheme_sources';

	/**
	 * The app config key of the last result per scheme.
	 *
	 * @var string
	 */
	public const STATE_KEY = 'tooi_scheme_refresh';

	/**
	 * The incident kind of a refresh that imported nothing.
	 *
	 * @var string
	 */
	public const INCIDENT_KIND = 'value-list-refresh';

	/**
	 * The download location of the TOOI repository.
	 *
	 * @var string
	 */
	private const REPOSITORY = 'https://repository.officiele-overheidspublicaties.nl/waardelijsten/';

	/**
	 * The seeded sources: the versions DiWoo 0.9.8 binds, as the TOOI publication lists their JSON downloads.
	 *
	 * @var array<int, array{key: string, scheme: string, url: string}>
	 */
	public const DEFAULT_SOURCES = [
		[
			'key' => 'informatiecategorieen',
			'scheme' => 'https://identifier.overheid.nl/tooi/def/thes/kern/wooInformatiecategorieen',
			'url' => self::REPOSITORY . 'scw_woo_informatiecategorieen/4/json/scw_woo_informatiecategorieen_4.json',
		],
		[
			'key' => 'documentsoorten',
			'scheme' => 'https://identifier.overheid.nl/tooi/set/ccw_plooi_documentsoorten',
			'url' => self::REPOSITORY . 'ccw_plooi_documentsoorten/7/json/ccw_plooi_documentsoorten_7.json',
		],
		[
			'key' => 'soortHandeling',
			'scheme' => 'https://identifier.overheid.nl/tooi/set/ccw_plooi_documenthandelingen',
			'url' => self::REPOSITORY . 'ccw_plooi_documenthandelingen/2/json/ccw_plooi_documenthandelingen_2.json',
		],
		[
			'key' => 'talen',
			'scheme' => 'https://identifier.overheid.nl/tooi/def/thes/xtrn/taal',
			'url' => self::REPOSITORY . 'scw_talen/1/json/scw_talen_1.json',
		],
	];

	/**
	 * The download timeout, in seconds.
	 *
	 * @var integer
	 */
	private const TIMEOUT = 30;

	/**
	 * Constructor.
	 *
	 * @param IClientService              $clients    Downloads the lists.
	 * @param VocabularyRegisterInterface $vocabulary OpenRegister's vocabulary register.
	 * @param TooiValueListGraph          $graph      Turns a download into an import document.
	 * @param IAppConfig                  $config     Holds the sources and the results.
	 * @param PipelineIncidents           $incidents  Tells the incident group about a refusal.
	 * @param ITimeFactory                $time       The clock.
	 * @param LoggerInterface             $logger     The logger.
	 */
	public function __construct(
		private readonly IClientService $clients,
		private readonly VocabularyRegisterInterface $vocabulary,
		private readonly TooiValueListGraph $graph,
		private readonly IAppConfig $config,
		private readonly PipelineIncidents $incidents,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refresh every source and store the result per scheme.
	 *
	 * @return array<string, array{lastRefreshAt: string, created: int, updated: int, deprecated: int, error: string|null}> The results.
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
	 */
	public function refreshAll(): array {
		$state = $this->state();
		foreach ($this->sources() as $source) {
			$state[$source['key']] = $this->refresh(source: $source);
		}

		$this->config->setValueString(
			app: AppIdentity::APP_ID,
			key: self::STATE_KEY,
			value: (string)json_encode($state, JSON_UNESCAPED_SLASHES)
		);

		return $state;
	}//end refreshAll()

	/**
	 * The sources: the configured list when it is a valid one, else the seeded one.
	 *
	 * @return array<int, array{key: string, scheme: string, url: string}> The sources.
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
	 */
	public function sources(): array {
		$configured = json_decode($this->config->getValueString(app: AppIdentity::APP_ID, key: self::SOURCES_KEY, default: ''), true);
		if (is_array($configured) === false || $configured === []) {
			return self::DEFAULT_SOURCES;
		}

		$sources = [];
		foreach ($configured as $source) {
			if (is_array($source) === true
				&& is_string($source['key'] ?? null) === true
				&& is_string($source['scheme'] ?? null) === true
				&& is_string($source['url'] ?? null) === true
			) {
				$sources[] = ['key' => $source['key'], 'scheme' => $source['scheme'], 'url' => $source['url']];
			}
		}

		if ($sources === []) {
			return self::DEFAULT_SOURCES;
		}

		return $sources;
	}//end sources()

	/**
	 * Refresh one scheme: download, guard, import.
	 *
	 * @param array{key: string, scheme: string, url: string} $source The source.
	 *
	 * @return array{lastRefreshAt: string, created: int, updated: int, deprecated: int, error: string|null} The result.
	 */
	private function refresh(array $source): array {
		$result = ['lastRefreshAt' => $this->now(), 'created' => 0, 'updated' => 0, 'deprecated' => 0, 'error' => null];

		try {
			$built = $this->graph->build(decoded: $this->download(url: $source['url']), schemeUri: $source['scheme']);
			$this->guardMassDeprecation(schemeUri: $source['scheme'], incoming: $built['conceptUris']);
			$counts = $this->vocabulary->import(jsonLd: $built['jsonLd']);
		} catch (Throwable $e) {
			$result['error'] = $e->getMessage();
			$this->incidents->raise(
				kind: self::INCIDENT_KIND,
				subject: $source['key'],
				message: 'The value list ' . $source['key'] . ' was not refreshed and stays as it was: ' . $e->getMessage()
			);
			$this->logger->warning('[ValueListRefresh] ' . $source['key'] . ' kept: ' . $e->getMessage());
			return $result;
		}

		$result['created'] = (int)($counts['created'] ?? 0);
		$result['updated'] = (int)($counts['updated'] ?? 0);
		$result['deprecated'] = (int)($counts['deprecated'] ?? 0);

		return $result;
	}//end refresh()

	/**
	 * Refuse an import that would deprecate more than a third of the active concepts.
	 *
	 * @param string             $schemeUri The scheme.
	 * @param array<int, string> $incoming  The concept uris of the download.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When it would.
	 */
	private function guardMassDeprecation(string $schemeUri, array $incoming): void {
		$active = $this->vocabulary->activeConceptUris(schemeUri: $schemeUri);
		$missing = array_diff($active, $incoming);
		if ($active !== [] && (count($missing) * 3) > count($active)) {
			throw new RuntimeException(
				'the download would deprecate ' . count($missing) . ' of ' . count($active) . ' active concepts'
			);
		}
	}//end guardMassDeprecation()

	/**
	 * The decoded download.
	 *
	 * @param string $url The source.
	 *
	 * @return mixed The decoded JSON.
	 *
	 * @throws RuntimeException When it cannot be fetched or does not parse.
	 */
	private function download(string $url): mixed {
		try {
			$body = (string)$this->clients->newClient()->get($url, ['timeout' => self::TIMEOUT])->getBody();
		} catch (Throwable $e) {
			throw new RuntimeException('the download failed: ' . $e->getMessage(), 0, $e);
		}

		$decoded = json_decode($body, true);
		if ($decoded === null) {
			throw new RuntimeException('the download is not JSON');
		}

		return $decoded;
	}//end download()

	/**
	 * The stored results, so a scheme not refreshed this run keeps its last one.
	 *
	 * @return array<string, mixed> The results.
	 */
	private function state(): array {
		$state = json_decode($this->config->getValueString(app: AppIdentity::APP_ID, key: self::STATE_KEY, default: ''), true);
		if (is_array($state) === false) {
			return [];
		}

		return $state;
	}//end state()

	/**
	 * The current moment as ISO 8601.
	 *
	 * @return string
	 */
	private function now(): string {
		return $this->time->getDateTime('now', new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
	}//end now()
}//end class
