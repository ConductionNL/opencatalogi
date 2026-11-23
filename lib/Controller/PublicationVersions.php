<?php

/**
 * OpenCatalogi Publication Versions.
 *
 * The answers of the version routes: the list of a publication's earlier
 * public versions, one version, and one of its documents. Every answer first
 * asks the publication through `PublicationsController::show()` itself, so a
 * version is never more reachable than the publication it belongs to.
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
 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\PublicationVersionServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IURLGenerator;
use Throwable;

/**
 * Builds the answers of the publication version routes.
 *
 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
 */
class PublicationVersions {

	/**
	 * Constructor.
	 *
	 * @param PublicationsController $publications The public read of one publication.
	 * @param PublicationVersionServiceInterface $versions The stored versions.
	 * @param IURLGenerator $urls Builds the version and document URLs.
	 * @param IL10N $l10n Localisation.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly PublicationsController $publications,
		private readonly PublicationVersionServiceInterface $versions,
		private readonly IURLGenerator $urls,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * The answer to a version route: the list, one version, one document, 404 or 500.
	 *
	 * @param array{catalogSlug: string, id: string, version?: int, fileId?: int} $asked The route's parameters.
	 *
	 * @return Response The answer.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	public function respond(array $asked): Response {
		try {
			$answer = $this->answer(asked: $asked);
		} catch (Throwable) {
			// The version service logged the reason; the caller gets none of it.
			return new JSONResponse(
				['error' => 'version-unavailable', 'message' => $this->l10n->t('The earlier versions cannot be shown right now.')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		if ($answer === null) {
			return new JSONResponse(
				['error' => 'unknown-version', 'message' => $this->l10n->t('This version does not exist or is not public.')],
				Http::STATUS_NOT_FOUND
			);
		}

		return $answer;

	}//end respond()

	/**
	 * The answer for a publication that is public now, or null.
	 *
	 * @param array{catalogSlug: string, id: string, version?: int, fileId?: int} $asked The route's parameters.
	 *
	 * @return Response|null The answer, or null when the publication is not public or has no such version or document.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	private function answer(array $asked): ?Response {
		$asked['uuid'] = $this->publicUuid(asked: $asked);
		if ($asked['uuid'] === null) {
			return null;
		}

		$stored = $this->versions->versionsOf(publication: $asked['uuid']);
		if (isset($asked['version']) === false) {
			return $this->listAnswer(asked: $asked, stored: $stored);
		}

		$found = null;
		foreach ($stored as $candidate) {
			if ((int)($candidate['version'] ?? 0) === $asked['version']) {
				$found = $candidate;
			}
		}

		if ($found === null || isset($asked['fileId']) === false) {
			return $this->versionAnswer(asked: $asked, found: $found);
		}

		$file = $this->versions->documentOf(version: $found, fileId: $asked['fileId']);
		if ($file === null) {
			return null;
		}

		return new DataDownloadResponse((string)$file->getContent(), (string)$file->getName(), (string)$file->getMimetype());

	}//end answer()

	/**
	 * The publication's uuid when its public read answers it and it is public now.
	 *
	 * @param array{catalogSlug: string, id: string} $asked The route's parameters.
	 *
	 * @return string|null The uuid.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	private function publicUuid(array $asked): ?string {
		$read = $this->publications->show(catalogSlug: $asked['catalogSlug'], id: $asked['id']);
		if (($read instanceof JSONResponse) === false || $read->getStatus() !== Http::STATUS_OK || is_array($read->getData()) === false) {
			return null;
		}

		return $this->versions->publicUuidOf(record: $read->getData());

	}//end publicUuid()

	/**
	 * The list answer: number, moment superseded and URL of each version.
	 *
	 * @param array{catalogSlug: string, id: string} $asked The route's parameters.
	 * @param list<array<string, mixed>> $stored The stored versions, oldest first.
	 *
	 * @return JSONResponse The list.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	private function listAnswer(array $asked, array $stored): JSONResponse {
		$results = array_map(
			fn (array $version): array => [
				'version' => (int)$version['version'],
				'supersededAt' => ($version['supersededAt'] ?? null),
				'url' => $this->link(route: 'publicationVersion.show', parameters: $this->address(asked: $asked) + ['version' => (int)$version['version']]),
			],
			$stored
		);

		return new JSONResponse(['results' => $results, 'total' => count($results)]);

	}//end listAnswer()

	/**
	 * One version: its fields, its documents with download URLs, and the current URL; null when there is none.
	 *
	 * @param array{catalogSlug: string, id: string, version: int} $asked The route's parameters.
	 * @param array<string, mixed>|null $found The stored version.
	 *
	 * @return JSONResponse|null The version.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	private function versionAnswer(array $asked, ?array $found): ?JSONResponse {
		if ($found === null) {
			return null;
		}

		$address = $this->address(asked: $asked) + ['version' => $asked['version']];
		$documents = array_map(
			fn (array $document): array => [
				'title' => (string)($document['title'] ?? ''),
				'format' => (string)($document['format'] ?? ''),
				'downloadUrl' => $this->link(route: 'publicationVersion.document', parameters: $address + ['fileId' => (int)($document['fileId'] ?? 0)]),
			],
			array_values(array_filter((array)($found['documents'] ?? []), 'is_array'))
		);

		return new JSONResponse(
			[
				'version' => $asked['version'],
				'supersededAt' => ($found['supersededAt'] ?? null),
				'superseded' => true,
				'fields' => (array)($found['fields'] ?? []),
				'documents' => $documents,
				'current' => $this->link(route: 'publications.show', parameters: $this->address(asked: $asked)),
			]
		);

	}//end versionAnswer()

	/**
	 * The catalogue slug and id the publication was addressed by.
	 *
	 * @param array{catalogSlug: string, id: string} $asked The route's parameters.
	 *
	 * @return array{catalogSlug: string, id: string} The address.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	private function address(array $asked): array {
		return ['catalogSlug' => $asked['catalogSlug'], 'id' => $asked['id']];

	}//end address()

	/**
	 * An absolute URL of an OpenCatalogi route.
	 *
	 * @param string $route The route, without the app prefix.
	 * @param array<string, int|string> $parameters The route parameters.
	 *
	 * @return string The URL.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	private function link(string $route, array $parameters): string {
		return $this->urls->linkToRouteAbsolute('opencatalogi.' . $route, $parameters);

	}//end link()
}//end class
