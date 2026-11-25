<?php

/**
 * OpenCatalogi Public Categories Controller.
 *
 * Serves the Woo information categories as this organisation uses them, to
 * anyone and CORS-enabled, for a portal's category filter: each visible
 * category with its label, the organisation's explanation and its order.
 * Hidden categories are left out. This is the contract the portal reads.
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
 * @spec openspec/changes/woo-value-list-curation/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\Woo\ValueListView;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The public list of information categories, with the organisation's explanations.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-a-category-explanation-is-served-publicly-req-wvl-003
 */
class PublicCategoriesController extends Controller {

	/**
	 * The value list the information categories are.
	 *
	 * @var string
	 */
	public const SCHEME = 'informatiecategorieen';

	/**
	 * Constructor.
	 *
	 * @param string          $appName The app name.
	 * @param IRequest        $request The request.
	 * @param ValueListView   $view    The categories with the organisation's choices.
	 * @param LoggerInterface $logger  The logger.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ValueListView $view,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Every visible information category as `{code, uri, label, explanation, order}`, in the organisation's order.
	 *
	 * @return JSONResponse 200 with `{results, total, choicesUnavailable}`; 500 when the list cannot be read.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-a-category-explanation-is-served-publicly-req-wvl-003
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[CORS]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function index(): JSONResponse {
		try {
			$view = $this->view->entries(scheme: self::SCHEME);
		} catch (Throwable $e) {
			$this->logger->error('OpenCatalogi: public categories failed', ['reason' => $e->getMessage()]);
			return new JSONResponse(['error' => 'failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$results = [];
		foreach ($view['entries'] as $entry) {
			if ($entry['hidden'] === true) {
				continue;
			}

			$results[] = [
				'code' => $entry['code'],
				'uri' => $entry['uri'],
				'label' => $entry['label'],
				'explanation' => $entry['explanation'],
				'order' => $entry['order'],
			];
		}

		return new JSONResponse(
			['results' => $results, 'total' => count($results), 'choicesUnavailable' => $view['choicesUnavailable']]
		);

	}//end index()
}//end class
