<?php

/**
 * OpenCatalogi Woo Organisation Type Controller.
 *
 * The organisation type an installation publishes as, for an administrator
 * (or a delegated OpenCatalogi administrator), with the information categories
 * the Woo does not apply to for that type. Both routes carry no
 * NoAdminRequired, so Nextcloud refuses everyone else. Choosing a type hides
 * nothing: the page shows the proposal, and the administrator confirms or
 * changes it per category through the value-list choices.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-value-list-curation/tasks.md#task-4.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use InvalidArgumentException;
use OCA\OpenCatalogi\Service\ValueList\CategoryApplicability;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Reads and sets the organisation type, answering the categories proposed for hiding.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-categories-that-do-not-apply-are-hidden-and-their-sitemap-stays-valid-req-wvl-004
 */
class WooOrganisationTypeController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                $appName       The app name.
	 * @param IRequest              $request       The request.
	 * @param CategoryApplicability $applicability The type and the applicability table.
	 * @param IL10N                 $l10n          Localisation.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CategoryApplicability $applicability,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The organisation type, the types to choose from and the proposal for the type set.
	 *
	 * @return JSONResponse 200 with `{type, types, proposal}`.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-categories-that-do-not-apply-are-hidden-and-their-sitemap-stays-valid-req-wvl-004
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function index(): JSONResponse {
		return new JSONResponse($this->answer());

	}//end index()

	/**
	 * Set the organisation type and answer its proposal; nothing is hidden here.
	 *
	 * @return JSONResponse 200 with `{type, types, proposal}`; 400 for an unknown type.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-categories-that-do-not-apply-are-hidden-and-their-sitemap-stays-valid-req-wvl-004
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function update(): JSONResponse {
		try {
			$this->applicability->setOrganisationType(type: trim((string)$this->request->getParam('type', '')));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $this->l10n->t('Choose one of the listed organisation types.')], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($this->answer());

	}//end update()

	/**
	 * The type, the types and the proposal for the type (none when no type is set).
	 *
	 * @return array{type: string|null, types: list<string>, proposal: list<array<string, string>>}
	 */
	private function answer(): array {
		$type = $this->applicability->organisationType();
		$proposal = [];
		if ($type !== null) {
			$proposal = $this->applicability->proposalFor(type: $type);
		}

		return ['type' => $type, 'types' => $this->applicability->types(), 'proposal' => $proposal];

	}//end answer()
}//end class
