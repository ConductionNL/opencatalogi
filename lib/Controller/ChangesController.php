<?php

/**
 * OpenCatalogi changed-since controller.
 *
 * The public, read-only `GET /api/{catalogSlug}/changes?since=` route a re-user
 * polls to learn what changed and what was withdrawn in a catalogue. A thin
 * rendering layer: selection and mapping live in ChangesService.
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
 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Exception\MalformedChangesParameterException;
use OCA\OpenCatalogi\Service\ChangesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Controller for the changed-since read of a catalogue.
 *
 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md
 */
class ChangesController extends Controller {
	use AnswersCrossOriginReadRequests;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param ChangesService $changes The changed-since read.
	 * @param CatalogiServiceInterface $catalogiService Resolves a catalogue by slug.
	 * @param PublicationQueryServiceInterface $queryService The anonymous read-rule guard.
	 * @param IL10N $l10n Localization.
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param IAppConfig|null $appConfig App config for the CORS allowlist.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly ChangesService $changes,
		private readonly CatalogiServiceInterface $catalogiService,
		private readonly PublicationQueryServiceInterface $queryService,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly ?IAppConfig $appConfig = null,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * CORS preflight for the changed-since route.
	 *
	 * @return Response The preflight answer.
	 *
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-re-user-asks-what-changed-since-a-moment-oai-006
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function preflightedCors(): Response {
		return $this->readOnlyPreflight();
	}//end preflightedCors()

	/**
	 * What changed and what was withdrawn in a catalogue since a moment.
	 *
	 * @param string $catalogSlug The catalogue slug.
	 *
	 * @return JSONResponse `{changed, withdrawn, since, until, next}`; 400 naming the
	 *                      parameter it cannot read; 404 for an unknown catalogue;
	 *                      503 when OpenRegister cannot read anonymously.
	 *
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-re-user-asks-what-changed-since-a-moment-oai-006
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-withdrawn-record-is-reported-not-shown-oai-007
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function changes(string $catalogSlug): JSONResponse {
		try {
			$since = $this->changes->parseSince(since: $this->request->getParam('since'));

			$catalog = $this->catalogiService->getCatalogBySlug($catalogSlug);
			if ($catalog === null) {
				return $this->withCors(response: new JSONResponse(['error' => $this->l10n->t('Catalog not found')], Http::STATUS_NOT_FOUND));
			}

			// The same guard the catalogue list and the DCAT feed apply: an anonymous
			// reader never reaches a schema without read rules.
			$catalog = $this->queryService->applyCatalogReadRuleGuard(catalog: $catalog);

			$cursor = $this->request->getParam('cursor');
			if (is_string($cursor) === false) {
				$cursor = null;
			}

			$answer = $this->changes->changesSince(catalog: $catalog, catalogSlug: $catalogSlug, since: $since, cursor: $cursor);

			return $this->withCors(response: new JSONResponse($answer, Http::STATUS_OK));
		} catch (MalformedChangesParameterException $e) {
			return $this->withCors(
				response: new JSONResponse(
					[
						'error' => $this->l10n->t('%s must be an ISO 8601 date, such as 2026-04-01T00:00:00Z.', [$e->getParameter()]),
						'parameter' => $e->getParameter(),
					],
					Http::STATUS_BAD_REQUEST
				)
			);
		} catch (RuntimeException $e) {
			$this->logger->warning('[ChangesController::changes] Cannot read anonymously', ['error' => $e->getMessage()]);
			$unavailable = new JSONResponse(['error' => $this->l10n->t('Search backend is not available.')], Http::STATUS_SERVICE_UNAVAILABLE);
			return $this->withCors(response: $unavailable);
		} catch (\Throwable $e) {
			$this->logger->error('[ChangesController::changes] Failed to read changes', ['catalogSlug' => $catalogSlug, 'error' => $e->getMessage()]);
			return $this->withCors(response: new JSONResponse(['error' => $this->l10n->t('Internal server error')], Http::STATUS_INTERNAL_SERVER_ERROR));
		}//end try

	}//end changes()

	/**
	 * Add the read-only CORS headers.
	 *
	 * @param JSONResponse $response The answer.
	 *
	 * @return JSONResponse The answer with CORS headers.
	 */
	private function withCors(JSONResponse $response): JSONResponse {
		$this->withCorsHeaders(response: $response);
		return $response;
	}//end withCors()
}//end class
