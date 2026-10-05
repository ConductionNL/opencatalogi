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

use OCA\OpenCatalogi\Service\Publication\CommentPeriodService;
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
	 * @param CommentPeriodService $periods Opens periods and derives their state.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly IL10N $l10n,
		private readonly IUserSession $userSession,
		private readonly CommentPeriodService $periods,
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
		$publication = trim((string)$this->request->getParam('publication', ''));
		$user = $this->userSession->getUser();
		$openedBy = '';
		if ($user !== null) {
			$openedBy = $user->getUID();
		}

		try {
			$period = $this->periods->open(
				publication: ['id' => $publication],
				termDays: (int)$this->request->getParam('termDays', 0),
				legalRemedy: trim((string)$this->request->getParam('legalRemedy', '')),
				announcementUrl: trim((string)$this->request->getParam('announcementUrl', '')),
				automaticWithdrawal: filter_var(
					$this->request->getParam('automaticWithdrawal', false),
					FILTER_VALIDATE_BOOLEAN
				),
				openedBy: $openedBy
			);
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

		$objectService = $this->objects();
		if ($objectService === null) {
			return new JSONResponse(
				data: ['error' => 'register-unreadable'],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
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

	}//end open()

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
			return new JSONResponse(
				data: ['error' => 'register-unreadable'],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		try {
			$config = $this->periodConfiguration();
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		try {
			$period = $this->asArray(
				object: $objectService->find(
					id: $id,
					register: $config['register'],
					schema: $config['schema']
				)
			);
		} catch (\Throwable $e) {
			return new JSONResponse(data: ['error' => 'unknown-period'], statusCode: Http::STATUS_NOT_FOUND);
		}

		if ($period === []) {
			return new JSONResponse(data: ['error' => 'unknown-period'], statusCode: Http::STATUS_NOT_FOUND);
		}

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

	}//end show()
}//end class
