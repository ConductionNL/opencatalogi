<?php

/**
 * OpenCatalogi organisation activation controller
 *
 * The Woo settings search the TOOI organisation registers and activate the
 * organisations this installation publishes for; officers read the activated
 * set for their publisher and responsible-organisation choices.
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
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use InvalidArgumentException;
use OCA\OpenCatalogi\Service\ValueList\OrganisationActivation;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Throwable;

/**
 * Searches the TOOI organisation registers, activates or deactivates one, and answers the activated set.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
 */
class OrganisationActivationController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                 $appName    The app name.
	 * @param IRequest               $request    The request.
	 * @param OrganisationActivation $activation The activation.
	 * @param IL10N                  $l10n       Localisation.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OrganisationActivation $activation,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The organisations of the TOOI registers matching `search`, activated ones first, for an administrator.
	 *
	 * @return JSONResponse 200 with `{results, total}`; 503 when the registers cannot be read.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function index(): JSONResponse {
		$search = (string)$this->request->getParam('search', '');

		return $this->answer(read: fn (): array => $this->activation->search(query: $search));
	}//end index()

	/**
	 * Activate or deactivate one organisation, for an administrator.
	 *
	 * Takes `entry` (the TOOI identifier) and `active`. Activating links or
	 * creates the OpenRegister organisation and answers which one it is.
	 *
	 * @return JSONResponse 200 with `{entry, active, organisation, created}`; 400 for a bad request or an unknown or ended organisation; 503 when OpenRegister fails.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function update(): JSONResponse {
		$entry = trim((string)$this->request->getParam('entry', ''));
		$active = $this->request->getParam('active');
		if ($entry === '' || is_bool($active) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Name the organisation and whether it is active.')], Http::STATUS_BAD_REQUEST);
		}

		try {
			if ($active === false) {
				$this->activation->deactivate(uri: $entry);
				return new JSONResponse(['entry' => $entry, 'active' => false, 'organisation' => null, 'created' => false]);
			}

			$activated = $this->activation->activate(uri: $entry);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $this->l10n->t('This organisation cannot be activated: %s', [$e->getMessage()])], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $this->l10n->t('The organisation could not be saved: %s', [$e->getMessage()])], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse(['entry' => $entry, 'active' => true, 'organisation' => $activated['organisation'], 'created' => $activated['created']]);
	}//end update()

	/**
	 * The activated organisations, for any signed-in user choosing a publisher or responsible organisation.
	 *
	 * @return JSONResponse 200 with `{results, total}`; 503 when the registers cannot be read.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
	 */
	#[NoAdminRequired]
	public function active(): JSONResponse {
		return $this->answer(read: fn (): array => $this->activation->search(query: ''));
	}//end active()

	/**
	 * Run a read and answer it, or a 503 naming why it failed.
	 *
	 * @param callable(): array<string, mixed> $read The read.
	 *
	 * @return JSONResponse The answer.
	 */
	private function answer(callable $read): JSONResponse {
		try {
			return new JSONResponse($read());
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $this->l10n->t('The organisation registers could not be read: %s', [$e->getMessage()])], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}//end answer()
}//end class
