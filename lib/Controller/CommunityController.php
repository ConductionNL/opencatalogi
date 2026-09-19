<?php

/**
 * OpenCatalogi Community Controller.
 *
 * The public and community surface: the status page, subscriptions to it, the
 * instance banner, the notice boards, the Atom feed, the reader's vote, and the
 * endpoint that renders this app's markup the way this app renders it.
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
use OCA\OpenCatalogi\Service\Community\BannerService;
use OCA\OpenCatalogi\Service\Community\MarkupRenderService;
use OCA\OpenCatalogi\Service\Community\NoticeBoardService;
use OCA\OpenCatalogi\Service\Community\StatusPageService;
use OCA\OpenCatalogi\Service\Community\SubscriptionService;
use OCA\OpenCatalogi\Service\Community\VoteService;
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
	use ResolvesRegisterConfiguration;

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
	 * @param SubscriptionService $subscriptionService The subscriptions.
	 * @param BannerService $bannerService The instance banner.
	 * @param NoticeBoardService $noticeService The notice boards.
	 * @param AtomFeedService $feedService The catalogue feed.
	 * @param VoteService $voteService The reader's vote.
	 * @param MarkupRenderService $markupService The markup renderer.
	 * @param ServiceCatalogueService $objects The OpenRegister reader that refuses rather than defaulting.
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
		private readonly SubscriptionService $subscriptionService,
		private readonly BannerService $bannerService,
		private readonly NoticeBoardService $noticeService,
		private readonly AtomFeedService $feedService,
		private readonly VoteService $voteService,
		private readonly MarkupRenderService $markupService,
		private readonly ServiceCatalogueService $objects,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Resolve the Access-Control-Allow-Origin header value.
	 *
	 * @return string The header value.
	 */
	private function resolveAllowedOrigin(): string {
		$configured = trim($this->config->getValueString($this->appName, 'cors_allowed_origins', '*'));
		if ($configured === '' || $configured === '*') {
			return '*';
		}

		$allowlist = array_values(
			array_filter(array_map('trim', explode(',', $configured)), static fn (string $e): bool => $e !== '')
		);
		$callerOrigin = $this->request->getHeader('Origin');
		if ($callerOrigin !== '' && in_array($callerOrigin, $allowlist, true) === true) {
			return $callerOrigin;
		}

		return ($allowlist[0] ?? '*');

	}//end resolveAllowedOrigin()

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
	 * Ask to be told when a component changes state.
	 *
	 * The subscription is created unconfirmed and the one-time token goes to
	 * the address, never back to the caller: returning it here would let anyone
	 * confirm a subscription for an address that is not theirs.
	 *
	 * @return JSONResponse That the confirmation was sent.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-reader-subscribes-to-changes-on-the-status-page-req-pcs-102
	 */
	#[AnonRateLimit(limit: 10, period: 60)]
	public function subscribe(): JSONResponse {
		try {
			$requested = $this->subscriptionService->request(
				address: (string)$this->request->getParam('address', ''),
				scope: (string)$this->request->getParam('scope', 'status')
			);
		} catch (\DomainException $e) {
			return $this->withCors(
				response: new JSONResponse(
					data: ['error' => 'subscription-refused', 'message' => $e->getMessage()],
					statusCode: Http::STATUS_BAD_REQUEST
				)
			);
		}

		try {
			$config = $this->configurationFor(schemaKey: 'status_subscription_schema');
			$this->objects->getObjectService()->saveObject(
				object: $requested['subscription'],
				extend: [],
				register: $config['register'],
				schema: $config['schema']
			);
		} catch (CatalogueUnreadableException $e) {
			return $this->withCors(
				response: new JSONResponse(data: ['error' => 'status-unreadable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE)
			);
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		return $this->withCors(
			response: new JSONResponse(
				data: [
					'pending' => true,
					'message' => $this->l10n->t('Confirm the address before anything is sent to it.'),
				],
				statusCode: Http::STATUS_ACCEPTED
			)
		);

	}//end subscribe()

	/**
	 * The addresses a state change may be sent to.
	 *
	 * Admin only, and it exists because the confirmed set is the thing worth
	 * checking: an unconfirmed address that appears here is a way to send mail
	 * on somebody's behalf.
	 *
	 * @return JSONResponse The confirmed recipients for a scope.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-reader-subscribes-to-changes-on-the-status-page-req-pcs-102
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function subscriptionRecipients(): JSONResponse {
		try {
			$subscriptions = $this->readAll(schemaKey: 'status_subscription_schema');
		} catch (CatalogueUnreadableException $e) {
			return new JSONResponse(data: ['error' => 'status-unreadable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		$scope = (string)$this->request->getParam('scope', 'status');

		return new JSONResponse(
			[
				'scope' => $scope,
				'recipients' => $this->subscriptionService->recipients(subscriptions: $subscriptions, scope: $scope),
			]
		);

	}//end subscriptionRecipients()

	/**
	 * The banners the signed-in user should see right now.
	 *
	 * @return JSONResponse The banners.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-an-administrator-shows-a-dated-banner-to-every-user-req-pcs-103
	 */
	public function banners(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'not-logged-in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$banners = $this->readAll(schemaKey: 'instance_banner_schema');
		} catch (CatalogueUnreadableException $e) {
			return new JSONResponse(data: ['error' => 'banners-unreadable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		$dismissed = json_decode(
			$this->config->getValueString($this->appName, 'banner_dismissals_' . $user->getUID(), '[]'),
			true
		);
		if (is_array($dismissed) === false) {
			$dismissed = [];
		}

		return new JSONResponse(
			[
				'banners' => $this->bannerService->forUser(
					banners: $banners,
					dismissedIds: array_map('strval', $dismissed)
				),
			]
		);

	}//end banners()

	/**
	 * Remember that this user dismissed a banner.
	 *
	 * @return JSONResponse The dismissal.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-an-administrator-shows-a-dated-banner-to-every-user-req-pcs-103
	 */
	public function dismissBanner(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'not-logged-in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$bannerId = trim((string)$this->request->getParam('banner', ''));
		if ($bannerId === '') {
			return new JSONResponse(data: ['error' => 'missing-banner'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$key = 'banner_dismissals_' . $user->getUID();
		$dismissed = json_decode($this->config->getValueString($this->appName, $key, '[]'), true);
		if (is_array($dismissed) === false) {
			$dismissed = [];
		}

		$dismissed[] = $bannerId;
		$this->config->setValueString(
			$this->appName,
			$key,
			(string)json_encode(array_values(array_unique(array_map('strval', $dismissed))))
		);

		return new JSONResponse(['dismissed' => true]);

	}//end dismissBanner()

	/**
	 * Save a notice board.
	 *
	 * @return JSONResponse The board, or the refusal.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-catalogue-carries-a-notice-board-req-pcs-104
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function saveNoticeBoard(): JSONResponse {
		$board = $this->request->getParam('board', []);
		if (is_array($board) === false || $board === []) {
			return new JSONResponse(data: ['error' => 'missing-board'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		try {
			$checked = $this->noticeService->validateBoard(board: $board);
		} catch (\DomainException $e) {
			return new JSONResponse(
				data: ['error' => 'board-refused', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$config = $this->configurationFor(schemaKey: 'notice_board_schema');
			$saved = $this->objects->getObjectService()->saveObject(
				object: $checked,
				extend: [],
				register: $config['register'],
				schema: $config['schema'],
				uuid: (string)($board['id'] ?? '')
			);
		} catch (CatalogueUnreadableException $e) {
			return new JSONResponse(data: ['error' => 'board-unreadable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		$storedBoard = $this->asArray(object: $saved);

		return new JSONResponse(
			array_merge(
				$storedBoard,
				['commentsOffered' => $this->noticeService->commentsOffered(board: $storedBoard)]
			),
			Http::STATUS_CREATED
		);

	}//end saveNoticeBoard()

	/**
	 * Save a notice on a board.
	 *
	 * The write path REQ-PCS-104 asks for. A notice is checked before it is
	 * stored, so a notice with no board, no title or a period this app cannot
	 * read is refused with the reason rather than saved and rendered blank.
	 *
	 * @return JSONResponse The stored notice, or the refusal.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-catalogue-carries-a-notice-board-req-pcs-104
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function saveNotice(): JSONResponse {
		$notice = $this->request->getParam('notice', []);
		if (is_array($notice) === false || $notice === []) {
			return new JSONResponse(data: ['error' => 'missing-notice'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		try {
			$checked = $this->noticeService->validateNotice(notice: $notice);
		} catch (\DomainException $e) {
			return new JSONResponse(
				data: ['error' => 'notice-refused', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$config = $this->configurationFor(schemaKey: 'notice_schema');
			$saved = $this->objects->getObjectService()->saveObject(
				object: $checked,
				extend: [],
				register: $config['register'],
				schema: $config['schema'],
				uuid: (string)($notice['id'] ?? '')
			);
		} catch (CatalogueUnreadableException $e) {
			return new JSONResponse(data: ['error' => 'notice-unreadable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		$stored = $this->asArray(object: $saved);

		return new JSONResponse(
			array_merge($stored, ['current' => $this->noticeService->isCurrent(notice: $stored)]),
			Http::STATUS_CREATED
		);

	}//end saveNotice()

	/**
	 * A catalogue's activity as an Atom feed.
	 *
	 * The publication rules own the access decision and it runs per entry, so a
	 * draft is absent because it is not published and not because the feed has
	 * an opinion of its own.
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
		try {
			$rules = $this->readAll(schemaKey: 'publication_rule_schema');
			$notices = $this->readAll(schemaKey: 'notice_schema');
			$records = $this->readAll(schemaKey: 'publication_schema', filters: ['catalog' => $catalogSlug]);
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
		}

		$entries = $this->feedService->entries(records: $records, notices: $notices, rules: $rules);
		$atom = $this->feedService->toAtom(
			catalogTitle: $catalogSlug,
			selfUrl: $this->request->getRequestUri(),
			entries: $entries
		);

		$response = new DataDisplayResponse($atom, Http::STATUS_OK, ['Content-Type' => 'application/atom+xml; charset=UTF-8']);
		$response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());

		return $response;

	}//end feed()

	/**
	 * Cast a vote on a published record.
	 *
	 * @param string $id The record.
	 *
	 * @return JSONResponse The distribution, and whether this vote counted.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-reader-who-is-not-staff-votes-on-a-published-record-req-pcs-106
	 */
	#[AnonRateLimit(limit: 10, period: 60)]
	public function vote(string $id): JSONResponse {
		$value = trim((string)$this->request->getParam('value', ''));
		if ($value === '') {
			return $this->withCors(
				response: new JSONResponse(
					data: ['error' => 'missing-value', 'message' => $this->l10n->t('Say what you are voting.')],
					statusCode: Http::STATUS_BAD_REQUEST
				)
			);
		}

		try {
			$recordConfig = $this->configurationFor(schemaKey: 'publication_schema');
			$voteConfig = $this->configurationFor(schemaKey: 'record_vote_schema');
			$objectService = $this->objects->getObjectService();
			$record = $this->asArray(
				object: $objectService->find(
					id: $id,
					register: $recordConfig['register'],
					schema: $recordConfig['schema']
				)
			);
		} catch (CatalogueUnreadableException $e) {
			return $this->withCors(
				response: new JSONResponse(data: ['error' => 'record-unreadable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE)
			);
		} catch (\Throwable $e) {
			return $this->withCors(response: new JSONResponse(data: ['error' => 'not-found'], statusCode: Http::STATUS_NOT_FOUND));
		}

		if ($this->voteService->acceptsVotes(record: $record) === false) {
			// An unpublished record answers the same 404 as one that does not
			// exist: a different answer would let a reader confirm that a draft
			// exists.
			return $this->withCors(response: new JSONResponse(data: ['error' => 'not-found'], statusCode: Http::STATUS_NOT_FOUND));
		}

		$existing = $this->readAll(schemaKey: 'record_vote_schema', filters: ['record' => $id]);

		try {
			$outcome = $this->voteService->cast(
				recordId: $id,
				existingVotes: $existing,
				readerToken: $this->readerToken(),
				value: $value,
				allowedValues: array_map('strval', (array)($record['voteValues'] ?? []))
			);
		} catch (\DomainException $e) {
			return $this->withCors(
				response: new JSONResponse(
					data: ['error' => 'vote-refused', 'message' => $e->getMessage()],
					statusCode: Http::STATUS_BAD_REQUEST
				)
			);
		}

		if ($outcome['counted'] === true) {
			$objectService->saveObject(
				object: $outcome['vote'],
				extend: [],
				register: $voteConfig['register'],
				schema: $voteConfig['schema']
			);
			$existing[] = $outcome['vote'];
		}

		// Only the distribution leaves this app. The votes themselves, which
		// carry a reader hash that is stable across records, never do.
		return $this->withCors(
			response: new JSONResponse(
				array_merge(
					$this->voteService->distribution(votes: $existing),
					['counted' => $outcome['counted']]
				)
			)
		);

	}//end vote()

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

	/**
	 * The token that identifies a reader for the length of one vote.
	 *
	 * @return string The token.
	 */
	private function readerToken(): string {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			return 'user:' . $user->getUID();
		}

		$readerToken = (string)$this->request->getParam('readerToken', '');
		if ($readerToken !== '') {
			return 'token:' . $readerToken;
		}

		return 'address:' . (string)$this->request->getRemoteAddress();

	}//end readerToken()

	/**
	 * Normalise an OpenRegister result to a plain array.
	 *
	 * @param mixed $object The result.
	 *
	 * @return array<string, mixed> The properties.
	 */
	private function asArray(mixed $object): array {
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$object = $object->jsonSerialize();
		}

		if (is_array($object) === false) {
			return [];
		}

		if (isset($object['object']) === true && is_array($object['object']) === true) {
			$properties = $object['object'];
			$properties['id'] = ($object['id'] ?? ($properties['id'] ?? null));

			return $properties;
		}

		return $object;

	}//end asArray()
}//end class
