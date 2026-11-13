<?php

/**
 * OpenCatalogi Document Reference Probe.
 *
 * Asks a source system whether a referenced document still answers: a HEAD
 * request, and a ranged GET of one byte when the server refuses HEAD.
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
 *
 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-broken-references-are-shown-before-readers-find-them-req-pbr-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCP\Http\Client\IClientService;
use Throwable;

/**
 * One reachability check of one URL.
 *
 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-broken-references-are-shown-before-readers-find-them-req-pbr-003
 */
class DocumentReferenceProbe {

	/**
	 * Seconds a request may take.
	 */
	public const TIMEOUT = 10;

	/**
	 * Statuses that mean the server does not do HEAD, so a ranged GET is tried.
	 */
	private const HEAD_REFUSED = [405, 501];

	/**
	 * Constructor.
	 *
	 * @param IClientService $clients Nextcloud's HTTP client, which also refuses local addresses.
	 * @param DocumentReferenceUrlPolicy $policy Refuses an address that is not public.
	 */
	public function __construct(
		private readonly IClientService $clients,
		private readonly DocumentReferenceUrlPolicy $policy,
	) {
	}//end __construct()

	/**
	 * The status the source answered with; 0 when it did not answer or may not be asked.
	 *
	 * @param string $url The document's URL.
	 *
	 * @return int The HTTP status.
	 *
	 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-broken-references-are-shown-before-readers-find-them-req-pbr-003
	 */
	public function status(string $url): int {
		if ($this->policy->refusal(url: $url) !== null) {
			return 0;
		}

		$client = $this->clients->newClient();
		$options = ['timeout' => self::TIMEOUT, 'http_errors' => false, 'allow_redirects' => ['max' => 3]];

		try {
			$status = $client->head($url, $options)->getStatusCode();
			if (in_array($status, self::HEAD_REFUSED, true) === false) {
				return $status;
			}

			return $client->get($url, array_merge($options, ['headers' => ['Range' => 'bytes=0-0'], 'stream' => true]))->getStatusCode();
		} catch (Throwable $e) {
			return 0;
		}
	}//end status()

	/**
	 * Whether a status means the document is there.
	 *
	 * @param int $status The HTTP status.
	 *
	 * @return bool True for 2xx and 3xx.
	 *
	 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-broken-references-are-shown-before-readers-find-them-req-pbr-003
	 */
	public static function isReachable(int $status): bool {
		return $status >= 200 && $status < 400;
	}//end isReachable()
}//end class
