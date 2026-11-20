<?php

/**
 * The admin routes of the council documents harvest.
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
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\CouncilHarvestServiceInterface;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Status, set-up, the switches and Run now of the Council documents section.
 * Every route is for administrators of the OpenCatalogi settings only.
 *
 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
 */
class CouncilHarvestController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                         $appName The app name.
	 * @param IRequest                       $request The request.
	 * @param CouncilHarvestServiceInterface $harvest The harvest's status and actions.
	 * @param LoggerInterface                $logger  Records an action that failed unexpectedly.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly CouncilHarvestServiceInterface $harvest,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * What the section shows.
	 *
	 * @return JSONResponse The status.
	 *
	 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function status(): JSONResponse {
		return $this->answer(action: fn (): array => $this->harvest->status());
	}//end status()

	/**
	 * Set the harvest up for the source in the body (`source`).
	 *
	 * @return JSONResponse What was written, or 409 naming the missing piece.
	 *
	 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function setup(): JSONResponse {
		$source = (string)$this->request->getParam('source', '');
		return $this->answer(action: fn (): array => $this->harvest->setUp(sourceSlug: $source));
	}//end setup()

	/**
	 * Switch the harvest on.
	 *
	 * @return JSONResponse The status, or 409.
	 *
	 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function enable(): JSONResponse {
		return $this->answer(action: fn (): array => $this->harvest->setEnabled(enabled: true));
	}//end enable()

	/**
	 * Switch the harvest off.
	 *
	 * @return JSONResponse The status, or 409.
	 *
	 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function disable(): JSONResponse {
		return $this->answer(action: fn (): array => $this->harvest->setEnabled(enabled: false));
	}//end disable()

	/**
	 * Turn Publish on arrival on or off (`publish` in the body).
	 *
	 * @return JSONResponse The status, or 409.
	 *
	 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-each-public-council-document-becomes-a-publication-with-its-file-req-cdh-002
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function publishOnArrival(): JSONResponse {
		$publish = filter_var($this->request->getParam('publish'), FILTER_VALIDATE_BOOLEAN);
		return $this->answer(action: fn (): array => $this->harvest->setPublishOnArrival(publish: $publish));
	}//end publishOnArrival()

	/**
	 * Start a run now.
	 *
	 * @return JSONResponse The queued run, or 409.
	 *
	 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function run(): JSONResponse {
		return $this->answer(action: fn (): array => $this->harvest->runNow());
	}//end run()

	/**
	 * The answer of one action: 200 with its result, 409 with the refusal the
	 * section shows, or 500 without details for anything unexpected.
	 *
	 * @param callable(): array<string, mixed> $action The action.
	 *
	 * @return JSONResponse The answer.
	 */
	private function answer(callable $action): JSONResponse {
		try {
			return new JSONResponse($action());
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (Throwable $e) {
			$this->logger->error('[CouncilHarvestController] The council documents harvest action failed', ['error' => $e->getMessage()]);
			return new JSONResponse(['error' => 'The council documents harvest could not do this. See the server log.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end answer()
}//end class
