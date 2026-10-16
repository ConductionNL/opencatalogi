<?php

/**
 * OpenCatalogi developer.overheid.nl publiccode harvest Controller.
 *
 * The five admin endpoints behind the developer.overheid.nl harvest section of
 * the admin settings: read the status, set the harvest up, switch it on or off,
 * and run it now. A thin layer over PubliccodeHarvestService with harvest key
 * `developer-overheid`. Every method is admin only (or delegated through the
 * OpenCatalogi settings section) and keeps CSRF on: the writes go into
 * integriq, change who a flow runs as, or start outbound requests to the
 * register. A refused action answers 409 with a static localised message and
 * a slug; any other failure is logged and answers a static 500. Exception text
 * never reaches the response.
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
 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Exception\HarvestNotReadyException;
use OCA\OpenCatalogi\Service\PubliccodeHarvestService;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Admin endpoints for the developer.overheid.nl publiccode harvest.
 *
 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
 */
class DeveloperOverheidHarvestController extends Controller {

	/**
	 * The harvest key this controller drives.
	 *
	 * @var string
	 */
	private const HARVEST = PubliccodeHarvestService::HARVEST_DEVELOPER_OVERHEID;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param PubliccodeHarvestService $harvest The harvest's status and actions.
	 * @param IL10N $l10n Localises the refusal messages.
	 * @param LoggerInterface $logger Records failures, which the response does not carry.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PubliccodeHarvestService $harvest,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The harvest's status. Reads only; makes no outbound request and reads no
	 * credential.
	 *
	 * @return JSONResponse The status, or {message, error} on failure.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-009-the-api-key-never-touches-opencatalogi
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function status(): JSONResponse {
		try {
			return new JSONResponse(data: $this->harvest->status(harvest: self::HARVEST), statusCode: Http::STATUS_OK);
		} catch (HarvestNotReadyException $e) {
			return $this->refused(exception: $e);
		} catch (Throwable $e) {
			return $this->failed(exception: $e, action: 'status');
		}
	}//end status()

	/**
	 * Write the harvest's synchronization into integriq.
	 *
	 * @return JSONResponse What was created and updated, or {message, error}.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function setup(): JSONResponse {
		try {
			return new JSONResponse(data: $this->harvest->setUp(harvest: self::HARVEST), statusCode: Http::STATUS_OK);
		} catch (HarvestNotReadyException $e) {
			return $this->refused(exception: $e);
		} catch (Throwable $e) {
			return $this->failed(exception: $e, action: 'setup');
		}
	}//end setup()

	/**
	 * Switch the harvest on. The calling administrator adopts the flow.
	 *
	 * @return JSONResponse The flow status after the switch, or {message, error}.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function enable(): JSONResponse {
		try {
			return new JSONResponse(data: $this->harvest->setEnabled(enabled: true, harvest: self::HARVEST), statusCode: Http::STATUS_OK);
		} catch (HarvestNotReadyException $e) {
			return $this->refused(exception: $e);
		} catch (Throwable $e) {
			return $this->failed(exception: $e, action: 'enable');
		}
	}//end enable()

	/**
	 * Switch the harvest off.
	 *
	 * @return JSONResponse The flow status after the switch, or {message, error}.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function disable(): JSONResponse {
		try {
			return new JSONResponse(data: $this->harvest->setEnabled(enabled: false, harvest: self::HARVEST), statusCode: Http::STATUS_OK);
		} catch (HarvestNotReadyException $e) {
			return $this->refused(exception: $e);
		} catch (Throwable $e) {
			return $this->failed(exception: $e, action: 'disable');
		}
	}//end disable()

	/**
	 * Queue a run now.
	 *
	 * @return JSONResponse The queued run, or {message, error}.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function run(): JSONResponse {
		try {
			return new JSONResponse(data: $this->harvest->runNow(harvest: self::HARVEST), statusCode: Http::STATUS_OK);
		} catch (HarvestNotReadyException $e) {
			return $this->refused(exception: $e);
		} catch (Throwable $e) {
			return $this->failed(exception: $e, action: 'run');
		}
	}//end run()

	/**
	 * Answer a refused precondition: 409 with a static message for its slug.
	 *
	 * @param HarvestNotReadyException $exception The refusal.
	 *
	 * @return JSONResponse The conflict.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 */
	private function refused(HarvestNotReadyException $exception): JSONResponse {
		$error = $exception->getError();
		$message = match ($error) {
			HarvestNotReadyException::NEEDS_INTEGRIQ => $this->l10n->t(
				'The developer.overheid.nl harvest needs integriq. Install and enable integriq first.'
			),
			HarvestNotReadyException::NEEDS_OPENREGISTER => $this->l10n->t(
				'The developer.overheid.nl harvest needs OpenRegister. Install and enable OpenRegister first.'
			),
			HarvestNotReadyException::FLOW_NOT_IMPORTED => $this->l10n->t(
				'The harvest flow is not imported yet. Reimport the OpenCatalogi configuration first.'
			),
			HarvestNotReadyException::NOT_SET_UP => $this->l10n->t('Set the harvest up before you run it.'),
			HarvestNotReadyException::SWITCHED_OFF => $this->l10n->t('Switch the harvest on before you run it.'),
			default => $this->l10n->t('The harvest is not ready for that yet.'),
		};

		return new JSONResponse(data: ['message' => $message, 'error' => $error], statusCode: Http::STATUS_CONFLICT);
	}//end refused()

	/**
	 * Answer an unexpected failure: log it, return a static 500.
	 *
	 * @param Throwable $exception The failure.
	 * @param string $action The endpoint that failed, for the log.
	 *
	 * @return JSONResponse The server error.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 */
	private function failed(Throwable $exception, string $action): JSONResponse {
		$this->logger->error(
			'OpenCatalogi: developer.overheid.nl harvest ' . $action . ' failed',
			['exception' => $exception]
		);

		return new JSONResponse(
			data: [
				'message' => $this->l10n->t('The developer.overheid.nl harvest could not do that. The server log has the details.'),
				'error' => 'harvest-failed',
			],
			statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end failed()
}//end class
