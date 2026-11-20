<?php

/**
 * OpenCatalogi Capability Rights Controller.
 *
 * The rights table in the admin settings: the eight Woo rights and the groups
 * granted each, read and changed by an administrator.
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
 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-capabilities-are-rights-granted-per-group-req-wab-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use InvalidArgumentException;
use OCA\OpenCatalogi\Service\CapabilityRightsTable;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Reads and changes the grants of the eight rights.
 *
 * Neither method carries NoAdminRequired or AuthorizedAdminSetting: who may
 * hand out a right is an administrator only, never a delegated group, since a
 * delegated group could otherwise grant itself every right.
 *
 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-capabilities-are-rights-granted-per-group-req-wab-004
 */
class CapabilityRightsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                $appName The app name.
	 * @param IRequest              $request The request.
	 * @param CapabilityRightsTable $table   The rights and their grants.
	 * @param LoggerInterface       $logger  Logs a grant that could not be read or saved.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly CapabilityRightsTable $table,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The eight rights with the groups granted each.
	 *
	 * @return JSONResponse `{rights: [{verb, description, groups}]}`, or 500 when the grants cannot be read.
	 *
	 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-capabilities-are-rights-granted-per-group-req-wab-004
	 */
	public function index(): JSONResponse {
		try {
			return new JSONResponse(['rights' => $this->table->rows()]);
		} catch (\Throwable $e) {
			$this->logger->error('[CapabilityRightsController] The rights could not be read: '.$e->getMessage());
			return new JSONResponse(['error' => 'The rights could not be read.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end index()

	/**
	 * Grant one right to exactly the posted groups.
	 *
	 * @param string $verb The right.
	 *
	 * @return JSONResponse `{rights: [...]}` after the change; 400 for an unknown right or group; 500 when it could not be saved.
	 *
	 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-capabilities-are-rights-granted-per-group-req-wab-004
	 */
	public function update(string $verb): JSONResponse {
		$groups = $this->request->getParam('groups', []);
		if (is_array($groups) === false) {
			return new JSONResponse(['error' => 'groups must be a list of group ids.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			return new JSONResponse(['rights' => $this->table->grant(verb: $verb, groups: array_values($groups))]);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('[CapabilityRightsController] The right '.$verb.' could not be saved: '.$e->getMessage());
			return new JSONResponse(['error' => 'The right could not be saved.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end update()
}//end class
