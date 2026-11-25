<?php

/**
 * OpenCatalogi Value List Choices Controller.
 *
 * How this organisation uses the national value lists, over HTTP:
 *
 * - An administrator reads a list with the organisation's choices and saves
 *   the choice about one entry (its explanation, order, own label, hidden).
 *   Those two routes carry no NoAdminRequired, so Nextcloud refuses everyone
 *   else.
 * - Anyone, cross-origin, reads the visible information categories with
 *   their explanation: the contract a portal's category filter reads.
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
use OCA\OpenCatalogi\Service\ValueListsService;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IL10N;
use Throwable;

/**
 * Reads a value list with the organisation's choices, saves one choice, and serves the public categories.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-a-category-explanation-is-served-publicly-req-wvl-003
 */
class ValueListChoicesController extends Controller {

	/**
	 * The fields an administrator may set on a choice here.
	 *
	 * @var list<string>
	 */
	public const EDITABLE = ['order', 'localLabel', 'explanation', 'hidden'];

	/**
	 * The longest explanation a choice holds.
	 *
	 * @var int
	 */
	public const EXPLANATION_MAX = 1000;

	/**
	 * The value list the information categories are.
	 *
	 * @var string
	 */
	public const CATEGORIES = 'informatiecategorieen';

	/**
	 * Constructor.
	 *
	 * @param string        $appName The app name.
	 * @param IRequest      $request The request.
	 * @param ValueListView $view    The lists with the organisation's choices.
	 * @param IL10N         $l10n    Localisation.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ValueListView $view,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * One list as the organisation uses it, for an administrator.
	 *
	 * @return JSONResponse 200 with `{entries, choicesUnavailable}`; 400 for an unknown list.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-a-category-explanation-is-served-publicly-req-wvl-003
	 */
	public function index(): JSONResponse {
		$scheme = (string)$this->request->getParam('scheme', self::CATEGORIES);
		if (in_array($scheme, ValueListsService::SCHEMES, true) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Unknown value list.')], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($this->view->entries(scheme: $scheme));

	}//end index()

	/**
	 * Save the organisation's choice about one entry, for an administrator.
	 *
	 * @return JSONResponse 200 with the list after the save; 400 for a bad request; 422 when the save is refused.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-a-category-explanation-is-served-publicly-req-wvl-003
	 */
	public function update(): JSONResponse {
		$scheme = (string)$this->request->getParam('scheme', '');
		$fields = (array)$this->request->getParam('choice', []);
		$choice = array_merge($fields, ['scheme' => $scheme, 'entry' => trim((string)$this->request->getParam('entry', ''))]);

		$problem = $this->problem(choice: $choice, fields: $fields);
		if ($problem !== null) {
			return new JSONResponse(['error' => $problem], Http::STATUS_BAD_REQUEST);
		}

		try {
			$this->view->save(choice: $choice);
		} catch (Throwable $e) {
			return new JSONResponse(
				['error' => $this->l10n->t('The choice could not be saved: %s', [$e->getMessage()])],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return new JSONResponse($this->view->entries(scheme: $scheme));

	}//end update()

	/**
	 * Every visible information category as `{code, uri, label, explanation, order}`, in the organisation's order.
	 *
	 * @return JSONResponse 200 with `{results, total, choicesUnavailable}`.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-a-category-explanation-is-served-publicly-req-wvl-003
	 */
	#[CORS]
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function publicCategories(): JSONResponse {
		$view = $this->view->entries(scheme: self::CATEGORIES);
		$results = [];
		foreach ($view['entries'] as $entry) {
			if ($entry['hidden'] !== true) {
				$results[] = array_intersect_key($entry, array_flip(['code', 'uri', 'label', 'explanation', 'order']));
			}
		}

		return new JSONResponse(['results' => $results, 'total' => count($results), 'choicesUnavailable' => $view['choicesUnavailable']]);

	}//end publicCategories()

	/**
	 * What is wrong with a save request, or null when nothing is.
	 *
	 * @param array<string, mixed> $choice The choice with its `scheme` and `entry`.
	 * @param array<string, mixed> $fields The fields the request sets.
	 *
	 * @return string|null The problem, translated.
	 */
	private function problem(array $choice, array $fields): ?string {
		if (in_array($choice['scheme'], ValueListsService::SCHEMES, true) === false) {
			return $this->l10n->t('Unknown value list.');
		}

		if ($choice['entry'] === '') {
			return $this->l10n->t('Name the entry the choice is about.');
		}

		$unknown = array_diff(array_map('strval', array_keys($fields)), self::EDITABLE);
		if ($unknown !== []) {
			return $this->l10n->t('A value list choice has no field %s.', [reset($unknown)]);
		}

		if (mb_strlen((string)($fields['explanation'] ?? '')) > self::EXPLANATION_MAX) {
			return $this->l10n->t('An explanation is at most %s characters.', [(string)self::EXPLANATION_MAX]);
		}

		return null;

	}//end problem()
}//end class
