<?php

/**
 * OpenCatalogi comment period controller.
 *
 * Terinzagelegging. An administrator opens a public comment period on a
 * publication; anyone reads its state, its remedy and its reaction form.
 *
 * The state is derived at the read, so a scheduler that has not fired yet cannot
 * leave a closed period inviting comment. The three states are exposed here and
 * the portal screens that render them are portaliq's.
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
 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-a-publication-can-carry-one-bounded-public-comment-period-req-pcp-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\CommentPeriodRequest;
use OCA\OpenCatalogi\Contract\CommentPeriodServiceInterface;
use OCA\OpenCatalogi\Service\Publication\TermRollUnavailableException;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;

/**
 * Opens comment periods and answers their public state.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-a-publication-can-carry-one-bounded-public-comment-period-req-pcp-001
 */
class CommentPeriodController extends Controller {
	use ResolvesRegisterConfiguration;
	use ReadsOpenRegisterResults;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param ContainerInterface $container Server container.
	 * @param IL10N $l10n Localisation.
	 * @param IUserSession $userSession The current session.
	 * @param CommentPeriodServiceInterface $periods Opens periods and derives their state.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly IL10N $l10n,
		private readonly IUserSession $userSession,
		private readonly CommentPeriodServiceInterface $periods,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The register and schema the periods live in.
	 *
	 * @return array<string, string> The register and schema identifiers.
	 *
	 * @spec exclude pure config plumbing — resolves the configured period register.
	 */
	private function periodConfiguration(): array {
		return $this->resolveRegisterConfiguration(
			registerKey: 'publication_register',
			schemaKey: 'comment_period_schema'
		);

	}//end periodConfiguration()

	/**
	 * Resolve the consumed OpenRegister ObjectService.
	 *
	 * @return object|null The service, or null when OpenRegister is unavailable.
	 *
	 * @spec exclude pure framework plumbing — resolves the consumed OR ObjectService.
	 */
	private function objects(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			return null;
		}

	}//end objects()

	/**
	 * Open a public comment period on a publication.
	 *
	 * @return JSONResponse The period, or the refusal.
	 *
	 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-a-publication-can-carry-one-bounded-public-comment-period-req-pcp-001
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function open(): JSONResponse {
		try {
			$period = $this->periods->open(request: $this->openRequest(openedBy: $this->sessionUserId()));
		} catch (\DomainException $e) {
			return new JSONResponse(
				data: ['error' => 'period-refused', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		} catch (TermRollUnavailableException $e) {
			return new JSONResponse(
				data: ['error' => 'term-engine-unavailable', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		return $this->storePeriod(period: $period);

	}//end open()

	/**
	 * The uid of the signed-in user, or empty when nobody is signed in.
	 *
	 * @return string The uid.
	 *
	 * @spec exclude pure session plumbing for open().
	 */
	private function sessionUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();

	}//end sessionUserId()

	/**
	 * The period request read from the request parameters.
	 *
	 * @param string $openedBy The session user who opens the period.
	 *
	 * @return CommentPeriodRequest The request.
	 *
	 * @spec exclude pure request reading for open().
	 */
	private function openRequest(string $openedBy): CommentPeriodRequest {
		return new CommentPeriodRequest(
			publication: ['id' => trim((string)$this->request->getParam('publication', ''))],
			termDays: (int)$this->request->getParam('termDays', 0),
			legalRemedy: trim((string)$this->request->getParam('legalRemedy', '')),
			announcementUrl: trim((string)$this->request->getParam('announcementUrl', '')),
			automaticWithdrawal: filter_var(
				$this->request->getParam('automaticWithdrawal', false),
				FILTER_VALIDATE_BOOLEAN
			),
			openedBy: $openedBy
		);

	}//end openRequest()

	/**
	 * Store an opened period and answer it as created.
	 *
	 * @param array<string, mixed> $period The period to save.
	 *
	 * @return JSONResponse The stored period, or why it could not be stored.
	 *
	 * @spec exclude pure storage plumbing for open().
	 */
	private function storePeriod(array $period): JSONResponse {
		$objectService = $this->objects();
		if ($objectService === null) {
			return $this->registerUnreadableResponse();
		}

		try {
			$config = $this->periodConfiguration();
			$saved = $this->asArray(
				object: $objectService->saveObject(
					object: $period,
					register: $config['register'],
					schema: $config['schema'],
					_rbac: false,
					_multitenancy: false
				)
			);
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		return new JSONResponse($saved, Http::STATUS_CREATED);

	}//end storePeriod()

	/**
	 * Read a comment period: its state, its remedy, its form, its announcement.
	 *
	 * Public, because a comment period is public by definition: it is announced on
	 * the official announcement platform, and a period a reader cannot read is not
	 * one they can react to.
	 *
	 * @param string $id The period.
	 *
	 * @return JSONResponse The period as a reader sees it.
	 *
	 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-the-period-reports-one-of-three-states-req-pcp-004
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function show(string $id): JSONResponse {
		$objectService = $this->objects();
		if ($objectService === null) {
			return $this->registerUnreadableResponse();
		}

		try {
			$lookup = ['id' => $id] + $this->periodConfiguration();
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		$period = $this->findPeriod(objectService: $objectService, lookup: $lookup);
		if ($period === []) {
			return new JSONResponse(data: ['error' => 'unknown-period'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return $this->publicPeriodResponse(period: $period);

	}//end show()

	/**
	 * Find a period, or none when the register does not have it.
	 *
	 * @param object $objectService The consumed OpenRegister ObjectService.
	 * @param array<string, string> $lookup The period id, register and schema.
	 *
	 * @return array<string, mixed> The period, or an empty array.
	 *
	 * @spec exclude pure register reading for show().
	 */
	private function findPeriod(object $objectService, array $lookup): array {
		try {
			return $this->asArray(
				object: $objectService->find(
					id: $lookup['id'],
					register: $lookup['register'],
					schema: $lookup['schema']
				)
			);
		} catch (\Throwable $e) {
			return [];
		}

	}//end findPeriod()

	/**
	 * Answer a period as a reader sees it.
	 *
	 * @param array<string, mixed> $period The stored period.
	 *
	 * @return JSONResponse The public view, or 503 when its dates cannot be read.
	 *
	 * @spec exclude pure response shaping for show().
	 */
	private function publicPeriodResponse(array $period): JSONResponse {
		try {
			return new JSONResponse($this->periods->publicView(period: $period));
		} catch (\DomainException $e) {
			return new JSONResponse(
				data: [
					'error' => 'unreadable-period',
					'message' => $this->l10n->t('The dates of this comment period cannot be read.'),
				],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

	}//end publicPeriodResponse()

	/**
	 * The answer when OpenRegister cannot be reached.
	 *
	 * @return JSONResponse 503 naming the unreadable register.
	 *
	 * @spec exclude pure response shaping.
	 */
	private function registerUnreadableResponse(): JSONResponse {
		return new JSONResponse(
			data: ['error' => 'register-unreadable'],
			statusCode: Http::STATUS_SERVICE_UNAVAILABLE
		);

	}//end registerUnreadableResponse()
}//end class
