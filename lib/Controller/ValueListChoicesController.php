<?php

/**
 * OpenCatalogi Value List Choices Controller.
 *
 * The Woo settings' way to curate a national value list: read the list as this
 * organisation uses it, and save the organisation's choice about one entry
 * (its explanation, order, own label, hidden). Administrators only: the
 * routes carry no NoAdminRequired, so Nextcloud refuses everyone else.
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

use OCA\OpenCatalogi\Service\ValueListsService;
use OCA\OpenCatalogi\Service\Woo\ValueListChoiceStore;
use OCA\OpenCatalogi\Service\Woo\ValueListView;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads a value list with the organisation's choices, and saves one choice.
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
	 * Constructor.
	 *
	 * @param string               $appName The app name.
	 * @param IRequest             $request The request.
	 * @param ValueListView        $view    The lists with the organisation's choices.
	 * @param ValueListChoiceStore $store   Where a choice is saved.
	 * @param IL10N                $l10n    Localisation.
	 * @param LoggerInterface      $logger  The logger.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ValueListView $view,
		private readonly ValueListChoiceStore $store,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * One list as the organisation uses it.
	 *
	 * @return JSONResponse 200 with `{entries, choicesUnavailable}`; 400 for an unknown list.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-a-category-explanation-is-served-publicly-req-wvl-003
	 */
	public function index(): JSONResponse {
		$scheme = (string)$this->request->getParam('scheme', 'informatiecategorieen');
		if (in_array($scheme, ValueListsService::SCHEMES, true) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Unknown value list.')], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($this->view->entries(scheme: $scheme));

	}//end index()

	/**
	 * Save the organisation's choice about one entry.
	 *
	 * @return JSONResponse 200 with the list after the save; 400 for a bad request; 422 when the save is refused.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-a-category-explanation-is-served-publicly-req-wvl-003
	 */
	public function update(): JSONResponse {
		$scheme = (string)$this->request->getParam('scheme', '');
		$entry = trim((string)$this->request->getParam('entry', ''));
		$fields = (array)$this->request->getParam('choice', []);

		$problem = $this->problem(scheme: $scheme, entry: $entry, fields: $fields);
		if ($problem !== null) {
			return new JSONResponse(['error' => $problem], Http::STATUS_BAD_REQUEST);
		}

		try {
			$this->store->save(scheme: $scheme, entry: $entry, fields: $fields);
		} catch (Throwable $e) {
			$this->logger->warning('OpenCatalogi: a value list choice was not saved: ' . $e->getMessage(), ['app' => 'opencatalogi']);
			return new JSONResponse(
				['error' => $this->l10n->t('The choice could not be saved: %s', [$e->getMessage()])],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return new JSONResponse($this->view->entries(scheme: $scheme));

	}//end update()

	/**
	 * What is wrong with the request, or null when nothing is.
	 *
	 * @param string               $scheme The list.
	 * @param string               $entry  The entry's URI.
	 * @param array<string, mixed> $fields The fields to set.
	 *
	 * @return string|null The problem, translated.
	 */
	private function problem(string $scheme, string $entry, array $fields): ?string {
		if (in_array($scheme, ValueListsService::SCHEMES, true) === false) {
			return $this->l10n->t('Unknown value list.');
		}

		if ($entry === '') {
			return $this->l10n->t('Name the entry the choice is about.');
		}

		foreach (array_keys($fields) as $field) {
			if (in_array((string)$field, self::EDITABLE, true) === false) {
				return $this->l10n->t('A value list choice has no field %s.', [(string)$field]);
			}
		}

		if (mb_strlen((string)($fields['explanation'] ?? '')) > self::EXPLANATION_MAX) {
			return $this->l10n->t('An explanation is at most %s characters.', [(string)self::EXPLANATION_MAX]);
		}

		return null;

	}//end problem()
}//end class
