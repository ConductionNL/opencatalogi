<?php

/**
 * OpenCatalogi Instance Mode Controller
 *
 * Reads and changes the instance mode (production or staging).
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
 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-an-administrator-puts-the-instance-in-staging-req-stg-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use InvalidArgumentException;
use OCA\OpenCatalogi\Contract\InstanceModeSwitchInterface;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The instance mode endpoints of the admin settings (/api/settings/instance-mode).
 *
 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-an-administrator-puts-the-instance-in-staging-req-stg-001
 */
class InstanceModeController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The name of the app.
	 * @param IRequest $request The request object.
	 * @param IL10N $l10n The localization service.
	 * @param IUserSession $userSession The user session.
	 * @param InstanceModeSwitchInterface|null $instanceModes Reads and changes the instance mode; null refuses both endpoints.
	 *
	 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-an-administrator-puts-the-instance-in-staging-req-stg-001
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IL10N $l10n,
		private readonly IUserSession $userSession,
		private readonly ?InstanceModeSwitchInterface $instanceModes = null,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The instance mode, and the OpenRegister webhooks and integriq synchronisations
	 * OpenCatalogi does not stop in staging.
	 *
	 * @return JSONResponse `{mode, outside: {webhooks, synchronisations}}`.
	 *
	 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-an-administrator-puts-the-instance-in-staging-req-stg-001
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function getInstanceMode(): JSONResponse {
		if ($this->instanceModes === null) {
			return new JSONResponse(data: ['error' => 'The instance mode is not available.'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse($this->instanceModes->current());
	}//end getInstanceMode()

	/**
	 * Change the instance mode. The body carries `mode` (production or staging) and
	 * `confirmed: true`; the change is written to Nextcloud's audit log.
	 *
	 * CSRF is enforced, as for {@see SettingsController::update()}.
	 *
	 * @return JSONResponse The new and previous mode; 400 for an unknown mode or a missing confirmation.
	 *
	 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-an-administrator-puts-the-instance-in-staging-req-stg-001
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function updateInstanceMode(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null || $this->instanceModes === null) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Not logged in')], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->instanceModes->change(
				mode: (string)$this->request->getParam('mode', ''),
				confirmed: filter_var($this->request->getParam('confirmed', false), FILTER_VALIDATE_BOOLEAN),
				userId: $user->getUID()
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($result);
	}//end updateInstanceMode()
}//end class
