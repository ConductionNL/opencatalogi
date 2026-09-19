<?php

/**
 * OpenCatalogi Notice Board Controller.
 *
 * The things an organisation puts in front of a reader and then has to be able
 * to take away again: the dated instance banner and the dismissal that hides
 * it for one user, and the notice boards with the notices on them.
 *
 * Both failures of the community surface apply here. Publishing what should
 * not be published: a notice with no board, no title or a period this app
 * cannot read is refused with the reason rather than stored and rendered
 * blank, and a board that offers comments without a moderator is refused
 * outright. Reporting as published what is not: a banner list this app cannot
 * read answers 503 rather than an empty list, because "no banners" and "the
 * banners could not be read" are not the same sentence.
 *
 * Split out of CommunityController on 2026-09-19. Every route keeps its URL
 * and its verb; only the route NAME changed, from `community#<action>` to
 * `noticeBoard#<action>`, and no code resolves these by name.
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
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\Catalogue\CatalogueUnreadableException;
use OCA\OpenCatalogi\Service\Community\BannerService;
use OCA\OpenCatalogi\Service\Community\NoticeBoardService;
use OCA\OpenCatalogi\Service\ServiceCatalogueService;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;

/**
 * The banners and the notice boards.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md
 */
class NoticeBoardController extends Controller {
	use ReadsOpenRegisterResults;
	use ResolvesRegisterConfiguration;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param IAppConfig $config App configuration.
	 * @param ContainerInterface $container Server container.
	 * @param IUserSession $userSession The current session.
	 * @param BannerService $bannerService The instance banner.
	 * @param NoticeBoardService $noticeService The notice boards.
	 * @param ServiceCatalogueService $objects The OpenRegister reader that refuses rather than defaulting.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly IUserSession $userSession,
		private readonly BannerService $bannerService,
		private readonly NoticeBoardService $noticeService,
		private readonly ServiceCatalogueService $objects,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

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
}//end class
