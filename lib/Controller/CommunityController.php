<?php

/**
 * OpenCatalogi Community Controller.
 *
 * The public and community surface: the status page, subscriptions to it, the
 * Atom feed, the reader's vote, and the endpoint that renders this app's markup
 * the way this app renders it.
 *
 * The banners and the notice boards used to live here too. They are
 * NoticeBoardController now, and this sentence says so because the previous
 * paragraph still listed them here after they had gone.
 *
 * The two failures this surface has are the ones every publication surface has.
 * Publishing what should not be public: a draft never reaches the feed, an
 * individual vote never leaves this app, and an unconfirmed address is never a
 * recipient. Reporting as published what is not: a state nobody has touched is
 * shown as stale rather than as a confident green, and a page this app cannot
 * read answers a refusal rather than an empty list.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\Catalogue\CatalogueUnreadableException;
use OCA\OpenCatalogi\Service\Community\AtomFeedService;
use OCA\OpenCatalogi\Service\Community\MarkupRenderService;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\Community\StatusPageService;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\ServiceCatalogueService;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;

/**
 * The public and community surface.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md
 */
class CommunityController extends Controller {
	use AnswersCrossOriginRequests;
	use ReadsOpenRegisterResults;
	use ResolvesRegisterConfiguration;

	/**
	 * How many records the feed carries, newest first.
	 */
	private const FEED_SIZE = 50;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param IAppConfig $config App configuration.
	 * @param ContainerInterface $container Server container.
	 * @param IL10N $l10n Localisation.
	 * @param IUserSession $userSession The current session.
	 * @param StatusPageService $statusService The status page.
	 * @param AtomFeedService $feedService The catalogue feed.
	 * @param MarkupRenderService $markupService The markup renderer.
	 * @param ServiceCatalogueService $objects The OpenRegister reader that refuses rather than defaulting.
	 * @param CatalogiService $catalogi The catalogues, by slug.
	 * @param PublicationQueryService $queryService The anonymous catalogue read.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly IL10N $l10n,
		private readonly IUserSession $userSession,
		private readonly StatusPageService $statusService,
		private readonly AtomFeedService $feedService,
		private readonly MarkupRenderService $markupService,
		private readonly ServiceCatalogueService $objects,
		private readonly CatalogiService $catalogi,
		private readonly PublicationQueryService $queryService,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Add the CORS headers a public endpoint answers with.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return JSONResponse The response, with its headers.
	 */
	private function withCors(JSONResponse $response): JSONResponse {
		$response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
		$response->addHeader('Access-Control-Allow-Credentials', 'false');

		return $response;

	}//end withCors()

	/**
	 * Answer a CORS preflight.
	 *
	 * @return Response The preflight response.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
	 */
	#[AnonRateLimit(limit: 240, period: 60)]
	public function preflightedCors(): Response {
		$response = new Response();
		$response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
		$response->addHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
		$response->addHeader('Access-Control-Max-Age', '1728000');
		$response->addHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept');
		$response->addHeader('Access-Control-Allow-Credentials', 'false');

		return $response;

	}//end preflightedCors()

	/**
	 * Resolve a register and schema pair for one of this change's schemas.
	 *
	 * @param string $schemaKey The `<thing>_schema` config key.
	 *
	 * @return array<string, string> The register and schema identifiers.
	 */
	private function configurationFor(string $schemaKey): array {
		return $this->resolveRegisterConfiguration(registerKey: 'publication_register', schemaKey: $schemaKey);

	}//end configurationFor()

	/**
	 * Read every object of one schema.
	 *
	 * @param string $schemaKey The `<thing>_schema` config key.
	 * @param array<string, mixed> $filters Extra filters.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 *
	 * @throws CatalogueUnreadableException When OpenRegister cannot be reached.
	 */
	private function readAll(string $schemaKey, array $filters = []): array {
		$config = $this->configurationFor(schemaKey: $schemaKey);
		$query = $filters;
		$query['@self'] = ['register' => $config['register'], 'schema' => $config['schema']];
		$query['_limit'] = ($filters['_limit'] ?? 500);

		$result = $this->objects->getObjectService()->searchObjectsPaginated($query, _rbac: false, _multitenancy: false);

		$objects = [];
		foreach (($result['results'] ?? []) as $object) {
			$objects[] = $this->asArray(object: $object);
		}

		return $objects;

	}//end readAll()

	/**
	 * The public status page.
	 *
	 * Nothing here probes anything. The states are the states somebody set, and
	 * a state older than the configured staleness period is marked stale with
	 * the date it was last set, because a page that renders a confident green
	 * over an untouched fact is worse than no page.
	 *
	 * @return JSONResponse The components and their states.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-public-status-page-says-what-is-running-req-pcs-101
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function statusPage(): JSONResponse {
		try {
			$components = $this->readAll(schemaKey: 'service_status_schema');
		} catch (CatalogueUnreadableException $e) {
			return $this->withCors(
				response: new JSONResponse(
					data: [
						'error' => 'status-unreadable',
						'message' => $this->l10n->t('The status page could not be read, so this is not a report that everything is fine.'),
					],
					statusCode: Http::STATUS_SERVICE_UNAVAILABLE
				)
			);
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		$stalenessHours = $this->config->getValueInt(
			$this->appName,
			'status_staleness_hours',
			StatusPageService::DEFAULT_STALENESS_HOURS
		);

		return $this->withCors(
			response: new JSONResponse(
				$this->statusService->render(components: $components, stalenessHours: $stalenessHours)
			)
		);

	}//end statusPage()

	/**
	 * Set a component's state.
	 *
	 * @return JSONResponse The saved status.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-public-status-page-says-what-is-running-req-pcs-101
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function setStatus(): JSONResponse {
		$user = $this->userSession->getUser();
		$setBy = '';
		if ($user !== null) {
			$setBy = $user->getUID();
		}

		try {
			$status = $this->statusService->setState(
				component: trim((string)$this->request->getParam('component', '')),
				state: trim((string)$this->request->getParam('state', '')),
				message: (string)$this->request->getParam('message', ''),
				setBy: $setBy
			);
		} catch (\DomainException $e) {
			return new JSONResponse(
				data: ['error' => 'status-refused', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$config = $this->configurationFor(schemaKey: 'service_status_schema');
			$saved = $this->objects->getObjectService()->saveObject(
				object: $status,
				extend: [],
				register: $config['register'],
				schema: $config['schema']
			);
		} catch (CatalogueUnreadableException $e) {
			return new JSONResponse(data: ['error' => 'status-unreadable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		return new JSONResponse($this->asArray(object: $saved), Http::STATUS_CREATED);

	}//end setStatus()

	/**
	 * A catalogue's activity as an Atom feed.
	 *
	 * The records are what an anonymous reader may read under the publication
	 * schema's own read rules, inside this catalogue: the same check as
	 * `/api/{catalogSlug}`, evaluated as an anonymous reader for every caller,
	 * because the feed is public (decision 138). A draft is absent because no
	 * public read rule admits it, not because the feed has an opinion of its own.
	 * The notices are the ones on this catalogue's boards, inside their period.
	 *
	 * @param string $catalogSlug The catalogue.
	 *
	 * @return Response The Atom document, or a refusal.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
	 */
	#[AnonRateLimit(limit: 60, period: 60)]
	public function feed(string $catalogSlug): Response {
		$catalog = $this->catalogi->getCatalogBySlug($catalogSlug);
		if ($catalog === null) {
			return $this->withCors(
				response: new JSONResponse(
					data: ['error' => 'catalog-not-found', 'message' => $this->l10n->t('Catalog not found')],
					statusCode: Http::STATUS_NOT_FOUND
				)
			);
		}

		$catalogId = (string)($catalog['id'] ?? ($catalog['@self']['id'] ?? ''));

		try {
			$read = $this->queryService->readCatalogueAsAnonymous(
				catalog: $catalog,
				queryParams: ['_limit' => self::FEED_SIZE, '_order' => ['@self.updated' => 'desc']],
				objectService: $this->objects->getObjectService()
			);
			$records = [];
			foreach (($read['results'] ?? []) as $record) {
				$records[] = $this->asArray(object: $record);
			}

			$notices = $this->feedService->noticesOfCatalogue(
				catalogId: $catalogId,
				boards: $this->readAll(schemaKey: 'notice_board_schema'),
				notices: $this->readAll(schemaKey: 'notice_schema')
			);
		} catch (CatalogueUnreadableException $e) {
			return $this->withCors(
				response: new JSONResponse(
					data: [
						'error' => 'feed-unreadable',
						'message' => $this->l10n->t('The feed could not be assembled, so this is not an empty catalogue.'),
					],
					statusCode: Http::STATUS_SERVICE_UNAVAILABLE
				)
			);
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}//end try

		$entries = $this->feedService->entries(records: $records, notices: $notices);
		$atom = $this->feedService->toAtom(
			catalogTitle: (string)($catalog['title'] ?? $catalogSlug),
			selfUrl: $this->request->getRequestUri(),
			entries: $entries
		);

		$response = new DataDisplayResponse($atom, Http::STATUS_OK, ['Content-Type' => 'application/atom+xml; charset=UTF-8']);
		$response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());

		return $response;

	}//end feed()

	/**
	 * Render this app's markup the way this app renders it.
	 *
	 * No side effect and nothing stored: the request goes in, the HTML comes
	 * out, and no object is created or changed on the way.
	 *
	 * @return JSONResponse The rendered HTML.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @no-admin-idor-exempt the caller supplies markup text and nothing else. No
	 * identifier reaches a lookup, no object is read and none is written, so
	 * there is no direct object reference for a caller to substitute.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-client-renders-our-markup-the-way-we-render-it-req-pcs-107
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function renderMarkup(): JSONResponse {
		$markup = $this->request->getParam('markup', null);
		if (is_string($markup) === false) {
			return $this->withCors(
				response: new JSONResponse(
					data: ['error' => 'missing-markup', 'message' => $this->l10n->t('Send the markup to render.')],
					statusCode: Http::STATUS_BAD_REQUEST
				)
			);
		}

		return $this->withCors(response: new JSONResponse($this->markupService->render(markup: $markup)));

	}//end renderMarkup()
}//end class
