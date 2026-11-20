<?php

/**
 * Publication lifecycle controller
 *
 * `GET /api/publications/lifecycle`: the publication's transition table, for a
 * caller that wants the allowed moves without an object
 * (publication-lifecycle-on-or REQ-PLC-001).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-the-publication-declares-one-lifecycle-with-named-transitions-req-plc-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\Publication\PublicationLifecycleTable;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Publishes the publication lifecycle.
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-the-publication-declares-one-lifecycle-with-named-transitions-req-plc-001
 */
class PublicationLifecycleController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                    $appName The app name.
	 * @param IRequest                  $request The request.
	 * @param PublicationLifecycleTable $table   The shipped lifecycle.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PublicationLifecycleTable $table,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The transition table: states, initial and final state, and every named move with its from and to states.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-the-publication-declares-one-lifecycle-with-named-transitions-req-plc-001
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		return new JSONResponse(data: $this->table->table());
	}//end index()
}//end class
