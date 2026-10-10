<?php

/**
 * OpenCatalogi Publication Disclosure Controller.
 *
 * The outward-facing side of a publication. Which collections publish and on
 * what conditions (REQ-PIN-108), the official notice that announces a decision
 * to the national platform and the local channel (REQ-PIN-107). The
 * verifiable stamp (REQ-PIN-109) was removed on 2026-09-29: nothing stamped a
 * document, so its two public endpoints could only ever answer "no key".
 *
 * Reporting as published what is not is the failure this surface has to avoid.
 * An announcement whose channel could not be reached answers 502 and names the
 * channel, never 200 with only the deliveries, because an operator reading a
 * partial announcement as a complete one stops looking.
 *
 * Split out of PublicationRulesController on 2026-09-19. Every route keeps its
 * URL and its verb; only the route NAME changed, from
 * `publicationRules#<action>` to `publicationDisclosure#<action>`, and no code
 * resolves these by name.
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
 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\Publication\IndexUnreachableException;
use OCA\OpenCatalogi\Service\Publication\NationalIndexService;
use OCA\OpenCatalogi\Service\Publication\PublishedCollectionsService;
use OCA\OpenCatalogi\Service\Publication\UnreadableRuleException;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * What is published outward, how it is announced, and how a reader checks it.
 *
 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md
 */
class PublicationDisclosureController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param NationalIndexService $indexService The national channels.
	 * @param PublishedCollectionsService $collectionsService The configured published set.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly NationalIndexService $indexService,
		private readonly PublishedCollectionsService $collectionsService,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Compose the official notice for both channels and hand it to the gateway.
	 *
	 * @return JSONResponse What was composed and what each destination answered.
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-official-notices-reach-the-national-platform-and-the-local-channel-req-pin-107
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function announce(): JSONResponse {
		$decision = $this->request->getParam('decision', []);
		if (is_array($decision) === false || $decision === []) {
			return new JSONResponse(data: ['error' => 'missing-decision'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$notices = $this->indexService->composeNotices(decision: $decision);
		$deliveries = [];
		$unreachable = [];

		foreach ($notices as $notice) {
			try {
				$deliveries[] = $this->indexService->deliver(notice: $notice);
			} catch (IndexUnreachableException $e) {
				$unreachable[] = [
					'channel' => (string)($notice['channel'] ?? ''),
					'reason' => $e->getMessage(),
				];
			}
		}

		// The notices are returned whether or not they were delivered, and an
		// undelivered channel is named. Answering 200 with only the deliveries
		// would let an operator read a partial announcement as a complete one.
		$statusCode = Http::STATUS_BAD_GATEWAY;
		if ($unreachable === []) {
			$statusCode = Http::STATUS_OK;
		}

		return new JSONResponse(
			data: [
				'notices' => $notices,
				'delivered' => $deliveries,
				'unreachable' => $unreachable,
				'complete' => ($unreachable === []),
			],
			statusCode: $statusCode
		);

	}//end announce()

	/**
	 * Read or write which collections publish, and on what conditions.
	 *
	 * @return JSONResponse The configured set.
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-which-collections-are-published-and-on-what-conditions-is-configured-req-pin-108
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function publishedCollections(): JSONResponse {
		try {
			return new JSONResponse(['collections' => $this->collectionsService->collections()]);
		} catch (UnreadableRuleException $e) {
			return new JSONResponse(
				data: ['error' => 'unreadable-configuration', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

	}//end publishedCollections()

	/**
	 * Save which collections publish.
	 *
	 * @return JSONResponse The outcome.
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-which-collections-are-published-and-on-what-conditions-is-configured-req-pin-108
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function savePublishedCollections(): JSONResponse {
		$collections = $this->request->getParam('collections', []);
		if (is_array($collections) === false) {
			return new JSONResponse(data: ['error' => 'missing-collections'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$outcome = $this->collectionsService->save(collections: array_values($collections));

		if ($outcome['saved'] === false) {
			return new JSONResponse(
				data: ['error' => 'invalid-collections', 'errors' => $outcome['errors']],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(['saved' => true]);

	}//end savePublishedCollections()

}//end class
