<?php

/**
 * The harvest feeds of the settings page.
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
 * @spec openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#requirement-a-harvest-feed-is-an-openregister-source-owned-by-opencatalogi-req-hfi-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use JsonSerializable;
use OCA\OpenCatalogi\Contract\HarvestFeedServiceInterface;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use RuntimeException;
use Throwable;

/**
 * Admin only: list, create, update and delete feeds through HarvestFeedServiceInterface.
 * Run now is OpenRegister's `POST /api/sources/{id}/sync`; there is no run path here.
 *
 * @spec openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#requirement-a-harvest-feed-is-an-openregister-source-owned-by-opencatalogi-req-hfi-002
 */
class HarvestFeedController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string             $appName The app name.
	 * @param IRequest           $request The request.
	 * @param HarvestFeedServiceInterface $feeds The feeds.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly HarvestFeedServiceInterface $feeds,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * This app's feeds.
	 *
	 * @return JSONResponse `{results}`.
	 *
	 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-2.2
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function index(): JSONResponse {
		return $this->answer(call: fn (): array => ['results' => $this->feeds->list()]);
	}//end index()

	/**
	 * Save a new feed.
	 *
	 * @return JSONResponse The feed (201), or `{error, field}` on a refusal.
	 *
	 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-2.2
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function create(): JSONResponse {
		return $this->answer(call: fn (): array => $this->feeds->create(data: $this->request->getParams()), status: Http::STATUS_CREATED);
	}//end create()

	/**
	 * Change a feed.
	 *
	 * @param int $id The feed's source id.
	 *
	 * @return JSONResponse The feed, or `{error, field}` on a refusal.
	 *
	 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-2.2
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function update(int $id): JSONResponse {
		$data = $this->request->getParams();
		unset($data['id'], $data['_route']);

		return $this->answer(call: fn (): array => $this->feeds->update(id: $id, data: $data));
	}//end update()

	/**
	 * Delete a feed and its flow.
	 *
	 * @param int $id The feed's source id.
	 *
	 * @return JSONResponse 204, or `{error}`.
	 *
	 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-2.2
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function destroy(int $id): JSONResponse {
		return $this->answer(
			call: function () use ($id): array {
				$this->feeds->delete(id: $id);
				return [];
			},
			status: Http::STATUS_NO_CONTENT
		);
	}//end destroy()

	/**
	 * Answer a call, mapping a refusal onto its status and field.
	 *
	 * A refusal carries its HTTP status as its code and serialises to
	 * `{error, field}`; anything else (OpenRegister absent) is a 503.
	 *
	 * @param callable(): array<string, mixed> $call   The call.
	 * @param int                              $status The status on success.
	 *
	 * @return JSONResponse The answer.
	 */
	private function answer(callable $call, int $status=Http::STATUS_OK): JSONResponse {
		try {
			return new JSONResponse($call(), $status);
		} catch (RuntimeException $e) {
			$code = $e->getCode();
			if ($code < 400 || $code > 499) {
				$code = Http::STATUS_SERVICE_UNAVAILABLE;
			}

			$body = ['error' => $e->getMessage()];
			if ($e instanceof JsonSerializable) {
				$body = $e->jsonSerialize();
			}

			return new JSONResponse($body, $code);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}//end answer()
}//end class
