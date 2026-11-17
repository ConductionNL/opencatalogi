<?php

/**
 * OpenCatalogi search-backend health check.
 *
 * Escape-hatch health check for the OpenRegister AppHost observability engine
 * (ADR-040): the declarative check set covers the database and the filesystem,
 * not an external search backend, so this provider adds `search_backend` to
 * the generic /api/health response. With the default `search_backend` app
 * config (`database`) search runs on the database, which the declarative
 * `database` check already covers, and the check passes. With `elasticsearch`
 * the configured `search_backend_url` is pinged with a 3-second timeout; a
 * failure degrades the response (HTTP 200, status degraded) rather than
 * failing it, because search falls back to the database.
 *
 * @category Observability
 * @package  OCA\OpenCatalogi\Observability
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
 * @spec openspec/changes/prometheus-metrics/tasks.md#2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Observability;

use OCA\OpenRegister\AppHost\IHealthCheckProvider;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The `search_backend` check of the health response.
 *
 * @spec openspec/changes/prometheus-metrics/tasks.md#2
 */
class SearchBackendHealthProvider implements IHealthCheckProvider {

	/**
	 * The check id in the health response's `checks` map.
	 */
	public const CHECK_ID = 'search_backend';

	/**
	 * The search backend when nothing is configured.
	 */
	public const DATABASE = 'database';

	/**
	 * The external search backend.
	 */
	public const ELASTICSEARCH = 'elasticsearch';

	/**
	 * The ping timeout, in seconds.
	 */
	public const TIMEOUT_SECONDS = 3;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $appConfig     Reads `search_backend` and `search_backend_url`.
	 * @param IClientService  $clientService Pings the search backend.
	 * @param LoggerInterface $logger        Logs why a ping failed; the response never carries it.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IClientService $clientService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The configured search backend type.
	 *
	 * @return string `elasticsearch` or `database`.
	 *
	 * @spec openspec/changes/prometheus-metrics/tasks.md#2
	 */
	public function backendType(): string {
		$type = trim($this->appConfig->getValueString('opencatalogi', 'search_backend', self::DATABASE));
		if ($type === self::ELASTICSEARCH) {
			return self::ELASTICSEARCH;
		}

		return self::DATABASE;

	}//end backendType()

	/**
	 * Run the search-backend check.
	 *
	 * @return array<string, array{ok: bool, severity: string, message?: string}>
	 *
	 * @spec openspec/changes/prometheus-metrics/tasks.md#2
	 */
	public function check(): array {
		if ($this->backendType() === self::DATABASE) {
			return [self::CHECK_ID => ['ok' => true, 'severity' => 'degraded']];
		}

		$url = trim($this->appConfig->getValueString('opencatalogi', 'search_backend_url', ''));
		if ($url === '') {
			return [self::CHECK_ID => self::failure(message: 'elasticsearch: no address configured')];
		}

		try {
			$response = $this->clientService->newClient()->get(
				$url,
				['timeout' => self::TIMEOUT_SECONDS, 'connect_timeout' => self::TIMEOUT_SECONDS, 'http_errors' => false]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[OpenCatalogi\\Health] search backend ping failed: ' . $e->getMessage(),
				['app' => 'opencatalogi']
			);
			return [self::CHECK_ID => self::failure(message: 'elasticsearch: unreachable')];
		}

		$status = $response->getStatusCode();
		if ($status >= 200 && $status < 300) {
			return [self::CHECK_ID => ['ok' => true, 'severity' => 'degraded']];
		}

		return [self::CHECK_ID => self::failure(message: 'elasticsearch: HTTP ' . $status)];

	}//end check()

	/**
	 * A failed, degrading result.
	 *
	 * @param string $message A generic reason, never internals.
	 *
	 * @return array{ok: bool, severity: string, message: string}
	 */
	private static function failure(string $message): array {
		return ['ok' => false, 'severity' => 'degraded', 'message' => $message];

	}//end failure()
}//end class
