<?php

/**
 * The data.overheid.nl registration of the DCAT feed and the evidence that it is harvested.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;

/**
 * Keeps the data.overheid.nl (DONL) registration beside the Woo-index registration.
 *
 * The operator records requested and registered by hand (DCAT-NPF-001: there
 * is no push). The app records every fetch of a DCAT feed whose user agent
 * matches the configured harvester pattern, and a daily job asks the DONL CKAN
 * API how many datasets it holds from our source. The status says harvested
 * only on that evidence, never on the operator's word alone.
 *
 * The count is asked with CKAN's `package_search` action, `rows=0` and a filter
 * query on DONL's `source_catalog` field, and read from `result.count`
 * (CKAN API documentation, ckan/ckan doc/api/index.rst; the field is in
 * dataoverheid/ckanext-dataoverheid, logic/schemas/dcat_ap_donl.py).
 *
 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002
 */
class DonlRegistrationService {

	/**
	 * The app id the values are stored under.
	 */
	private const APP = 'opencatalogi';

	/**
	 * The app config key of each stored value, by its name in the status.
	 */
	public const KEYS = [
		'status' => 'donl_registration_status',
		'requestedAt' => 'donl_registration_requested_at',
		'registeredAt' => 'donl_registration_registered_at',
		'lastFetchAt' => 'donl_last_fetch_at',
		'lastCheckAt' => 'donl_last_check_at',
		'donlDatasetCount' => 'donl_dataset_count',
		'donlCountAt' => 'donl_dataset_count_at',
		'feedDatasetCount' => 'donl_feed_dataset_count',
		'lastError' => 'donl_last_error',
	];

	/**
	 * The operator's settings: the CKAN API root, our source and the harvester's user agent.
	 */
	public const SETTINGS = [
		'apiUrl' => 'donl_api_url',
		'source' => 'donl_source',
		'harvesterPattern' => 'donl_harvester_pattern',
	];

	/**
	 * The statuses the operator can record.
	 */
	public const STATUSES = ['not_requested', 'requested', 'registered'];

	/**
	 * Seconds the DONL API may take.
	 */
	public const TIMEOUT_SECONDS = 20;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config Where the registration and the evidence are kept.
	 * @param IClientService $clients The HTTP client for the DONL API.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly IClientService $clients,
	) {
	}//end __construct()

	/**
	 * The registration, the evidence and whether that evidence says harvested.
	 *
	 * @return array<string, mixed> The stored values, the operator's settings and `harvested`.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002
	 */
	public function status(): array {
		$status = [];
		foreach (array_merge(self::KEYS, self::SETTINGS) as $name => $key) {
			$status[$name] = $this->config->getValueString(self::APP, $key, '');
		}

		if (in_array($status['status'], self::STATUSES, true) === false) {
			$status['status'] = 'not_requested';
		}

		foreach (['donlDatasetCount', 'feedDatasetCount'] as $count) {
			$status[$count] = $this->countOrNull(value: $status[$count]);
		}

		$status['harvested'] = self::isHarvested(status: $status);

		return $status;
	}//end status()

	/**
	 * Whether the evidence says data.overheid.nl harvests the feed.
	 *
	 * A recorded harvester fetch or a DONL count above zero; the registration status alone never is.
	 *
	 * @param array<string, mixed> $status A status as `status()` answers it.
	 *
	 * @return bool True when harvested.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002
	 */
	public static function isHarvested(array $status): bool {
		if (($status['lastFetchAt'] ?? '') !== '') {
			return true;
		}

		return is_int($status['donlDatasetCount'] ?? null) === true && $status['donlDatasetCount'] > 0;
	}//end isHarvested()

	/**
	 * Record the operator's registration status and settings.
	 *
	 * Requested and registered get today's date when they are first recorded.
	 *
	 * @param array<string, mixed> $input `status` and any of `apiUrl`, `source`, `harvesterPattern`.
	 * @param DateTimeInterface|null $now The moment, now when omitted.
	 *
	 * @return array<string, mixed> The status after recording.
	 *
	 * @throws InvalidArgumentException On an unknown status, an API URL that is not https, or a pattern that does not compile.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002
	 */
	public function record(array $input, ?DateTimeInterface $now = null): array {
		$settings = $this->validSettings(input: $input);
		$status = (string)($input['status'] ?? $this->status()['status']);
		if (in_array($status, self::STATUSES, true) === false) {
			throw new InvalidArgumentException('Unknown registration status.');
		}

		foreach ($settings as $name => $value) {
			$this->config->setValueString(self::APP, self::SETTINGS[$name], $value);
		}

		$this->config->setValueString(self::APP, self::KEYS['status'], $status);
		$this->stampOnce(name: self::dateOf(status: $status), now: $now);

		return $this->status();
	}//end record()

	/**
	 * Record a fetch of a DCAT feed when the user agent is the DONL harvester.
	 *
	 * Without a configured pattern nothing is recorded: the user agent alone is never guessed.
	 *
	 * @param string|null $userAgent The request's user agent.
	 * @param DateTimeInterface|null $now The moment, now when omitted.
	 *
	 * @return bool True when the fetch was recorded.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002
	 */
	public function recordFetch(?string $userAgent, ?DateTimeInterface $now = null): bool {
		$pattern = $this->config->getValueString(self::APP, self::SETTINGS['harvesterPattern'], '');
		if (self::matches(pattern: $pattern, subject: (string)$userAgent) === false) {
			return false;
		}

		$this->config->setValueString(self::APP, self::KEYS['lastFetchAt'], self::moment(now: $now));
		return true;
	}//end recordFetch()

	/**
	 * Ask the DONL API how many datasets it holds from our source, and store the answer.
	 *
	 * On any failure the last count and its date stay and `lastError` names the failure.
	 *
	 * @param int|null $feedDatasetCount The number of datasets our feed carries, when known.
	 * @param DateTimeInterface|null $now The moment, now when omitted.
	 *
	 * @return array<string, mixed> The status after the check.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002
	 */
	public function check(?int $feedDatasetCount, ?DateTimeInterface $now = null): array {
		$moment = self::moment(now: $now);
		$this->config->setValueString(self::APP, self::KEYS['lastCheckAt'], $moment);
		if ($feedDatasetCount !== null) {
			$this->config->setValueString(self::APP, self::KEYS['feedDatasetCount'], (string)$feedDatasetCount);
		}

		try {
			$count = $this->askDonl();
		} catch (\Throwable $e) {
			$this->config->setValueString(self::APP, self::KEYS['lastError'], $e->getMessage());
			return $this->status();
		}

		$this->config->setValueString(self::APP, self::KEYS['donlDatasetCount'], (string)$count);
		$this->config->setValueString(self::APP, self::KEYS['donlCountAt'], $moment);
		$this->config->setValueString(self::APP, self::KEYS['lastError'], '');

		return $this->status();
	}//end check()

	/**
	 * The `package_search` URL that counts the datasets of our source.
	 *
	 * @param string $apiUrl The CKAN API root, such as `https://example.org/api/3`.
	 * @param string $source The value of DONL's `source_catalog` for our feed.
	 *
	 * @return string The URL.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002
	 */
	public static function countUrl(string $apiUrl, string $source): string {
		$query = http_build_query(['fq' => 'source_catalog:"' . addcslashes($source, '"\\') . '"', 'rows' => 0]);
		return rtrim($apiUrl, '/') . '/action/package_search?' . $query;
	}//end countUrl()

	/**
	 * The DONL count for our source.
	 *
	 * @return int The count.
	 *
	 * @throws \RuntimeException When the source is not configured or the API does not answer a count.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002
	 */
	private function askDonl(): int {
		$apiUrl = $this->config->getValueString(self::APP, self::SETTINGS['apiUrl'], '');
		$source = $this->config->getValueString(self::APP, self::SETTINGS['source'], '');
		if ($apiUrl === '' || $source === '') {
			throw new \RuntimeException('The data.overheid.nl API URL or source is not configured.');
		}

		$response = $this->clients->newClient()->get(
			self::countUrl(apiUrl: $apiUrl, source: $source),
			['timeout' => self::TIMEOUT_SECONDS, 'http_errors' => false, 'allow_redirects' => false]
		);
		$body = json_decode((string)$response->getBody(), true);
		$count = ($body['result']['count'] ?? null);
		if ($response->getStatusCode() !== 200 || is_int($count) === false) {
			throw new \RuntimeException('The data.overheid.nl API answered HTTP ' . $response->getStatusCode() . ' without a count.');
		}

		return $count;
	}//end askDonl()

	/**
	 * The operator's settings from the input, checked.
	 *
	 * @param array<string, mixed> $input The input.
	 *
	 * @return array<string, string> The settings given, by name.
	 *
	 * @throws InvalidArgumentException On an API URL that is not https or a pattern that does not compile.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002
	 */
	private function validSettings(array $input): array {
		$settings = array_map('strval', array_intersect_key($input, self::SETTINGS));
		$apiUrl = ($settings['apiUrl'] ?? '');
		if ($apiUrl !== '' && str_starts_with(strtolower($apiUrl), 'https://') === false) {
			throw new InvalidArgumentException('The data.overheid.nl API URL must be an https URL.');
		}

		$pattern = ($settings['harvesterPattern'] ?? '');
		if ($pattern !== '' && @preg_match(self::delimited(pattern: $pattern), '') === false) {
			throw new InvalidArgumentException('The harvester pattern is not a valid regular expression.');
		}

		return $settings;
	}//end validSettings()

	/**
	 * Store now under a date key unless it already holds a date.
	 *
	 * @param string|null $name The status name of the date key, null for none.
	 * @param DateTimeInterface|null $now The moment, now when omitted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002
	 */
	private function stampOnce(?string $name, ?DateTimeInterface $now): void {
		if ($name === null || $this->config->getValueString(self::APP, self::KEYS[$name], '') !== '') {
			return;
		}

		$this->config->setValueString(self::APP, self::KEYS[$name], self::moment(now: $now));
	}//end stampOnce()

	/**
	 * The date key a status sets.
	 *
	 * @param string $status The status.
	 *
	 * @return string|null `requestedAt`, `registeredAt` or null.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002
	 */
	private static function dateOf(string $status): ?string {
		return ['requested' => 'requestedAt', 'registered' => 'registeredAt'][$status] ?? null;
	}//end dateOf()

	/**
	 * A stored count as an int, null when none is stored.
	 *
	 * @param string $value The stored value.
	 *
	 * @return int|null The count.
	 *
	 * @spec exclude Data-shape helper; no independent domain behavior.
	 */
	private function countOrNull(string $value): ?int {
		if ($value === '' || ctype_digit($value) === false) {
			return null;
		}

		return (int)$value;
	}//end countOrNull()

	/**
	 * Whether a user agent matches the operator's pattern.
	 *
	 * @param string $pattern The pattern, without delimiters.
	 * @param string $subject The user agent.
	 *
	 * @return bool True on a match; false for an empty pattern or subject, and when the pattern does not compile.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002
	 */
	private static function matches(string $pattern, string $subject): bool {
		if ($pattern === '' || $subject === '') {
			return false;
		}

		return @preg_match(self::delimited(pattern: $pattern), $subject) === 1;
	}//end matches()

	/**
	 * A pattern wrapped in delimiters, case-insensitive.
	 *
	 * @param string $pattern The pattern.
	 *
	 * @return string The delimited pattern.
	 *
	 * @spec exclude Data-shape helper; no independent domain behavior.
	 */
	private static function delimited(string $pattern): string {
		return '~' . str_replace('~', '\~', $pattern) . '~i';
	}//end delimited()

	/**
	 * A moment as an ATOM string in UTC.
	 *
	 * @param DateTimeInterface|null $now The moment, now when omitted.
	 *
	 * @return string The moment.
	 *
	 * @spec exclude Data-shape helper; no independent domain behavior.
	 */
	private static function moment(?DateTimeInterface $now): string {
		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$moment = DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone('UTC'));
		}

		return $moment->format(DateTimeInterface::ATOM);
	}//end moment()
}//end class
