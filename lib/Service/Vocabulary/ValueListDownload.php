<?php

/**
 * OpenCatalogi value-list download: one national list, fetched and rewritten for import.
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

use OCP\Http\Client\IClientService;
use RuntimeException;
use Throwable;

/**
 * Fetches a value list from its source and hands back the import document.
 *
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
 */
class ValueListDownload {

	/**
	 * The download timeout, in seconds.
	 *
	 * @var integer
	 */
	private const TIMEOUT = 30;

	/**
	 * Constructor.
	 *
	 * @param IClientService     $clients Downloads the list.
	 * @param TooiValueListGraph $graph   Turns the download into an import document.
	 */
	public function __construct(
		private readonly IClientService $clients,
		private readonly TooiValueListGraph $graph,
	) {
	}//end __construct()

	/**
	 * The import document and concept uris of the list at a URL.
	 *
	 * @param string $url       The source.
	 * @param string $schemeUri The uri the scheme is stored under.
	 *
	 * @return array{jsonLd: array{"@graph": array<int, array<string, mixed>>}, conceptUris: array<int, string>}
	 *
	 * @throws RuntimeException When it cannot be fetched or does not parse.
	 * @throws \InvalidArgumentException When it holds no scheme or no concept.
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
	 */
	public function fetch(string $url, string $schemeUri): array {
		try {
			$body = (string)$this->clients->newClient()->get($url, ['timeout' => self::TIMEOUT])->getBody();
		} catch (Throwable $e) {
			throw new RuntimeException('the download failed: ' . $e->getMessage(), 0, $e);
		}

		$decoded = json_decode($body, true);
		if ($decoded === null) {
			throw new RuntimeException('the download is not JSON');
		}

		return $this->graph->build(decoded: $decoded, schemeUri: $schemeUri);
	}//end fetch()
}//end class
