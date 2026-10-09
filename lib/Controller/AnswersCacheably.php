<?php

/**
 * OpenCatalogi public cache headers trait.
 *
 * One rule for the cache headers of the public read answers (the publication
 * list, a publication, its attachments, the public search and a search
 * result), so a CDN or a browser may keep an anonymous answer for the time
 * the administrator set, and never keeps a signed-in one.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
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
 * @spec openspec/changes/operations-public-api-cache-headers/specs/operations-public-api-cache-headers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IUserSession;

/**
 * Cache headers for a public read answer.
 *
 * The host class provides `$this->request` (IRequest), `$this->container`
 * (ContainerInterface) and `$this->appName`.
 */
trait AnswersCacheably {
	/**
	 * Add the cache headers that fit the caller to a public read answer.
	 *
	 * - A signed-in caller: `Cache-Control: private, no-store` and no tag,
	 *   because the answer can hold records the public cannot read.
	 * - An anonymous caller and a 200 answer: `Cache-Control: public,
	 *   max-age=N, must-revalidate`, a weak `ETag` over the body as sent, and
	 *   `Vary: Origin, Accept-Language`. A matching `If-None-Match` turns the
	 *   answer into a 304 without a body.
	 * - An error answer, or a cache time of 0: unchanged.
	 *
	 * When the session or the configuration cannot be read, the caller is
	 * treated as signed in: a private answer cached publicly is the one
	 * mistake this must never make.
	 *
	 * The tag is computed over the body after OpenRegister's RBAC and the
	 * published check, so it cannot tell anyone that a hidden record changed.
	 *
	 * @param JSONResponse $response The answer.
	 *
	 * @return JSONResponse The answer with its cache headers, or a 304.
	 *
	 * @spec openspec/changes/operations-public-api-cache-headers/specs/operations-public-api-cache-headers/spec.md#requirement-anonymous-answers-of-the-public-api-may-be-cached-req-pac-001
	 */
	private function cacheableForCaller(JSONResponse $response): JSONResponse {
		if ($response->getStatus() !== Http::STATUS_OK) {
			return $response;
		}

		try {
			$signedIn = $this->container->get(IUserSession::class)->isLoggedIn();
			$seconds = (int)$this->container->get(IAppConfig::class)->getValueString(
				$this->appName,
				'public_api_cache_seconds',
				'60'
			);
		} catch (\Throwable $e) {
			$signedIn = true;
			$seconds = 0;
		}

		if ($signedIn === true) {
			$response->addHeader('Cache-Control', 'private, no-store');
			return $response;
		}

		if ($seconds <= 0) {
			return $response;
		}

		$etag = 'W/"' . sha1((string)json_encode($response->getData())) . '"';
		$cacheControl = 'public, max-age=' . $seconds . ', must-revalidate';

		$response->addHeader('Cache-Control', $cacheControl);
		$response->addHeader('ETag', $etag);
		$response->addHeader('Vary', 'Origin, Accept-Language');

		if ($this->ifNoneMatchHits(etag: $etag) === true) {
			$response->setStatus(Http::STATUS_NOT_MODIFIED);
			$response->setData([]);
		}

		return $response;

	}//end cacheableForCaller()

	/**
	 * Whether the request's If-None-Match names this tag (weak comparison).
	 *
	 * @param string $etag The weak tag of the answer.
	 *
	 * @return boolean True when the client already holds this answer.
	 *
	 * @spec openspec/changes/operations-public-api-cache-headers/specs/operations-public-api-cache-headers/spec.md#requirement-anonymous-answers-of-the-public-api-may-be-cached-req-pac-001
	 */
	private function ifNoneMatchHits(string $etag): bool {
		$header = trim($this->request->getHeader('If-None-Match'));
		if ($header === '') {
			return false;
		}

		$opaque = substr($etag, 2);
		foreach (explode(',', $header) as $candidate) {
			$candidate = trim($candidate);
			if (str_starts_with($candidate, 'W/') === true) {
				$candidate = substr($candidate, 2);
			}

			if ($candidate === $opaque || $candidate === '*') {
				return true;
			}
		}

		return false;

	}//end ifNoneMatchHits()
}//end trait
