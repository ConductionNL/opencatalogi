<?php

/**
 * OpenCatalogi Publication Version Controller.
 *
 * Serves the earlier public versions of a corrected publication at their own
 * URLs, so a reader who cited a version still finds it. Every answer first
 * asks the publication through the same public read as `GET
 * /api/{catalogSlug}/{id}`: a publication that is no longer public has no
 * versions to show.
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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The public versions of a publication.
 *
 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
 */
class PublicationVersionController extends Controller {
	use AnswersCrossOriginReadRequests;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param PublicationsController $publications The public read of one publication.
	 * @param PublicationVersionServiceInterface $versions The stored versions.
	 * @param PublicationStateServiceInterface $states Says whether a publication is public.
	 * @param IURLGenerator $urls Builds the version and document URLs.
	 * @param IL10N $l10n Localisation.
	 * @param LoggerInterface $logger Logs a version that cannot be read.
	 * @param IAppConfig|null $appConfig The CORS origin setting.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly PublicationsController $publications,
		private readonly PublicationVersionServiceInterface $versions,
		private readonly PublicationStateServiceInterface $states,
		private readonly IURLGenerator $urls,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly ?IAppConfig $appConfig = null,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The versions of a public publication: number, when it was superseded, and its URL.
	 *
	 * @param string $catalogSlug The catalogue slug.
	 * @param string $id The publication id.
	 *
	 * @return Response The list, or 404 when the publication is not public.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(string $catalogSlug, string $id): Response {
		$publication = $this->publicPublication(catalogSlug: $catalogSlug, id: $id);
		if ($publication === null) {
			return $this->notFound();
		}

		try {
			$results = array_map(
				fn (array $version): array => [
					'version' => (int)$version['version'],
					'supersededAt' => ($version['supersededAt'] ?? null),
					'url' => $this->versionUrl(catalogSlug: $catalogSlug, id: $id, version: (int)$version['version']),
				],
				$this->versions->versionsOf(publication: $publication)
			);
		} catch (Throwable $e) {
			return $this->failed(error: $e);
		}

		return $this->withCorsHeaders(response: new JSONResponse(['results' => $results, 'total' => count($results)]));

	}//end index()

	/**
	 * One earlier version: its fields, its documents with their download URLs, and the current URL.
	 *
	 * @param string $catalogSlug The catalogue slug.
	 * @param string $id The publication id.
	 * @param string $version The version number.
	 *
	 * @return Response The version, or 404.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function show(string $catalogSlug, string $id, string $version): Response {
		try {
			$found = $this->publicVersion(catalogSlug: $catalogSlug, id: $id, version: (int)$version);
		} catch (Throwable $e) {
			return $this->failed(error: $e);
		}

		if ($found === null) {
			return $this->notFound();
		}

		$documents = array_map(
			fn (array $document): array => [
				'title' => (string)($document['title'] ?? ''),
				'format' => (string)($document['format'] ?? ''),
				'downloadUrl' => $this->urls->linkToRouteAbsolute(
					'opencatalogi.publicationVersion.document',
					['catalogSlug' => $catalogSlug, 'id' => $id, 'version' => (int)$version, 'fileId' => (int)($document['fileId'] ?? 0)]
				),
			],
			array_values(array_filter((array)($found['documents'] ?? []), 'is_array'))
		);

		return $this->withCorsHeaders(
			response: new JSONResponse(
				[
					'version' => (int)$found['version'],
					'supersededAt' => ($found['supersededAt'] ?? null),
					'superseded' => true,
					'fields' => (array)($found['fields'] ?? []),
					'documents' => $documents,
					'current' => $this->urls->linkToRouteAbsolute('opencatalogi.publications.show', ['catalogSlug' => $catalogSlug, 'id' => $id]),
				]
			)
		);

	}//end show()

	/**
	 * One document of an earlier version, as a download.
	 *
	 * @param string $catalogSlug The catalogue slug.
	 * @param string $id The publication id.
	 * @param string $version The version number.
	 * @param string $fileId The id of the copy kept on the version.
	 *
	 * @return Response The file, or 404.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function document(string $catalogSlug, string $id, string $version, string $fileId): Response {
		try {
			$found = $this->publicVersion(catalogSlug: $catalogSlug, id: $id, version: (int)$version);
			$file = null;
			if ($found !== null) {
				$file = $this->versions->documentOf(version: $found, fileId: (int)$fileId);
			}

			if ($file === null) {
				return $this->notFound();
			}

			return $this->withCorsHeaders(
				response: new DataDownloadResponse((string)$file->getContent(), (string)$file->getName(), (string)$file->getMimetype())
			);
		} catch (Throwable $e) {
			return $this->failed(error: $e);
		}

	}//end document()

	/**
	 * Answer the CORS preflight of the version routes.
	 *
	 * @return Response The preflight answer.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function preflightedCors(): Response {
		return $this->readOnlyPreflight();

	}//end preflightedCors()

	/**
	 * The stored version of a public publication, or null.
	 *
	 * @param string $catalogSlug The catalogue slug.
	 * @param string $id The publication id.
	 * @param int $version The version number.
	 *
	 * @return array<string, mixed>|null The version.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	private function publicVersion(string $catalogSlug, string $id, int $version): ?array {
		$publication = $this->publicPublication(catalogSlug: $catalogSlug, id: $id);
		if ($publication === null || $version < 1) {
			return null;
		}

		foreach ($this->versions->versionsOf(publication: $publication) as $stored) {
			if ((int)($stored['version'] ?? 0) === $version) {
				return $stored;
			}
		}

		return null;

	}//end publicVersion()

	/**
	 * The publication's uuid when the public read answers it and it is public, otherwise null.
	 *
	 * The read is `PublicationsController::show()` itself, so the versions are
	 * never more reachable than the publication. A signed-in caller may read a
	 * draft there, so a record that carries its status must also be public now.
	 *
	 * @param string $catalogSlug The catalogue slug.
	 * @param string $id The publication id.
	 *
	 * @return string|null The publication uuid.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	private function publicPublication(string $catalogSlug, string $id): ?string {
		$response = $this->publications->show(catalogSlug: $catalogSlug, id: $id);
		$data = null;
		if ($response instanceof JSONResponse && $response->getStatus() === Http::STATUS_OK) {
			$data = $response->getData();
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

	}//end publicPublication()

	/**
	 * The URL of one version.
	 *
	 * @param string $catalogSlug The catalogue slug.
	 * @param string $id The publication id.
	 * @param int $version The version number.
	 *
	 * @return string The URL.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	private function versionUrl(string $catalogSlug, string $id, int $version): string {
		return $this->urls->linkToRouteAbsolute(
			'opencatalogi.publicationVersion.show',
			['catalogSlug' => $catalogSlug, 'id' => $id, 'version' => $version]
		);

	}//end versionUrl()

	/**
	 * The 404 answer: the publication is not public, or it has no such version.
	 *
	 * @return Response The answer.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	private function notFound(): Response {
		return $this->withCorsHeaders(
			response: new JSONResponse(
				['error' => 'unknown-version', 'message' => $this->l10n->t('This version does not exist or is not public.')],
				Http::STATUS_NOT_FOUND
			)
		);

	}//end notFound()

	/**
	 * The 500 answer, with the reason logged and not sent.
	 *
	 * @param Throwable $error What went wrong.
	 *
	 * @return Response The answer.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	private function failed(Throwable $error): Response {
		$this->logger->error('[PublicationVersionController] A version could not be read: ' . $error->getMessage());

		return $this->withCorsHeaders(
			response: new JSONResponse(
				['error' => 'version-unavailable', 'message' => $this->l10n->t('The earlier versions cannot be shown right now.')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			)
		);

	}//end failed()
}//end class
