<?php

/**
 * OpenCatalogi Publication Versions.
 *
 * The answers of the version routes of `PublicationsController`: the list of a
 * publication's earlier public versions, one version, and one of its
 * documents. The controller asks only once its own public read answered the
 * publication; this class then checks that the record is public now.
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

use OCA\OpenCatalogi\Contract\PublicationStateServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationVersionServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IURLGenerator;

/**
 * Builds the answers of the publication version routes.
 *
 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
 */
class PublicationVersions {

	/**
	 * Constructor.
	 *
	 * @param PublicationVersionServiceInterface $versions The stored versions.
	 * @param PublicationStateServiceInterface $states Says whether a publication is public.
	 * @param IURLGenerator $urls Builds the version and document URLs.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly PublicationVersionServiceInterface $versions,
		private readonly PublicationStateServiceInterface $states,
		private readonly IURLGenerator $urls,
	) {

	}//end __construct()

	/**
	 * The publication's uuid when the public read answered it and it is public now, otherwise null.
	 *
	 * A signed-in caller may read a draft or a withdrawn record through the
	 * same read, so a record that carries its status must also be public now.
	 *
	 * @param Response $read The answer of `PublicationsController::show()`.
	 *
	 * @return string|null The publication uuid.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	public function publicUuid(Response $read): ?string {
		$data = null;
		if ($read instanceof JSONResponse && $read->getStatus() === Http::STATUS_OK) {
			$data = $read->getData();
		}

		if (is_array($data) === false
			|| (array_key_exists('status', $data) === true && $this->states->stateOf(publication: $data) !== PublicationStateServiceInterface::STATE_PUBLIC)
		) {
			return null;
		}

		$uuid = (string)($data['id'] ?? ($data['@self']['id'] ?? ''));
		if ($uuid === '') {
			return null;
		}

		return $uuid;

	}//end publicUuid()

	/**
	 * The answer for a public publication: its versions, one version, or one document of it.
	 *
	 * @param array{catalogSlug: string, id: string, uuid: string} $target The publication as addressed and its uuid.
	 * @param int|null $version The version asked for, null for the list.
	 * @param int|null $fileId The document asked for, null for the version itself.
	 *
	 * @return Response|null The answer, or null when there is no such version or document.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	public function answer(array $target, ?int $version, ?int $fileId): ?Response {
		$stored = $this->versions->versionsOf(publication: $target['uuid']);
		if ($version === null) {
			return $this->listAnswer(target: $target, stored: $stored);
		}

		$found = null;
		foreach ($stored as $candidate) {
			if ((int)($candidate['version'] ?? 0) === $version) {
				$found = $candidate;
			}
		}

		if ($found === null) {
			return null;
		}

		if ($fileId === null) {
			return $this->versionAnswer(target: $target, found: $found);
		}

		$file = $this->versions->documentOf(version: $found, fileId: $fileId);
		if ($file === null) {
			return null;
		}

		return new DataDownloadResponse((string)$file->getContent(), (string)$file->getName(), (string)$file->getMimetype());

	}//end answer()

	/**
	 * The list answer: number, moment superseded and URL of each version.
	 *
	 * @param array{catalogSlug: string, id: string, uuid: string} $target The publication.
	 * @param list<array<string, mixed>> $stored The stored versions, oldest first.
	 *
	 * @return JSONResponse The list.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	private function listAnswer(array $target, array $stored): JSONResponse {
		$results = array_map(
			fn (array $version): array => [
				'version' => (int)$version['version'],
				'supersededAt' => ($version['supersededAt'] ?? null),
				'url' => $this->link(route: 'version', target: $target, extra: ['version' => (int)$version['version']]),
			],
			$stored
		);

		return new JSONResponse(['results' => $results, 'total' => count($results)]);

	}//end listAnswer()

	/**
	 * One version: its fields, its documents with download URLs, and the current URL.
	 *
	 * @param array{catalogSlug: string, id: string, uuid: string} $target The publication.
	 * @param array<string, mixed> $found The stored version.
	 *
	 * @return JSONResponse The version.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	private function versionAnswer(array $target, array $found): JSONResponse {
		$number = (int)$found['version'];
		$documents = array_map(
			fn (array $document): array => [
				'title' => (string)($document['title'] ?? ''),
				'format' => (string)($document['format'] ?? ''),
				'downloadUrl' => $this->link(route: 'versionDocument', target: $target, extra: ['version' => $number, 'fileId' => (int)($document['fileId'] ?? 0)]),
			],
			array_values(array_filter((array)($found['documents'] ?? []), 'is_array'))
		);

		return new JSONResponse(
			[
				'version' => $number,
				'supersededAt' => ($found['supersededAt'] ?? null),
				'superseded' => true,
				'fields' => (array)($found['fields'] ?? []),
				'documents' => $documents,
				'current' => $this->link(route: 'show', target: $target, extra: []),
			]
		);

	}//end versionAnswer()

	/**
	 * An absolute URL of a publications route of this publication.
	 *
	 * @param string $route The publications action.
	 * @param array{catalogSlug: string, id: string, uuid: string} $target The publication.
	 * @param array<string, int> $extra The version and document parameters.
	 *
	 * @return string The URL.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	private function link(string $route, array $target, array $extra): string {
		return $this->urls->linkToRouteAbsolute(
			'opencatalogi.publications.' . $route,
			['catalogSlug' => $target['catalogSlug'], 'id' => $target['id']] + $extra
		);

	}//end link()
}//end class
