<?php

/**
 * OpenCatalogi Feed Consumer Controller.
 *
 * The administrator's endpoints for a catalogue's feed consumers
 * (integration-feed-consumer-credentials): list them, add one, generate a key
 * (shown once), revoke a key and disable a consumer. Every action is
 * admin-only. The listing also carries the instance-wide account list the
 * OOAPI feed accepts, read-only (REQ-FCC-004).
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
 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-an-administrator-issues-a-key-to-a-consumer-for-one-catalogue-req-fcc-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use InvalidArgumentException;
use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\FeedConsumerServiceInterface;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Admin endpoints for a catalogue's feed consumers and their keys.
 *
 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-an-administrator-issues-a-key-to-a-consumer-for-one-catalogue-req-fcc-001
 */
class FeedConsumerController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                       $appName   The app name.
	 * @param IRequest                     $request   The request.
	 * @param FeedConsumerServiceInterface $consumers The feed consumers.
	 * @param CatalogiServiceInterface     $catalogs  Resolves a catalogue by slug.
	 * @param IAppConfig                   $appConfig Reads the instance-wide consumer list.
	 * @param IL10N                        $l10n      Translations.
	 * @param LoggerInterface              $logger    The logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly FeedConsumerServiceInterface $consumers,
		private readonly CatalogiServiceInterface $catalogs,
		private readonly IAppConfig $appConfig,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The catalogue's consumers, and the instance-wide account list the feed also accepts.
	 *
	 * @param string $catalogSlug The catalogue slug.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-account-based-access-keeps-working-and-is-visible-req-fcc-004
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function index(string $catalogSlug): JSONResponse {
		return $this->inCatalog(
			catalogSlug: $catalogSlug,
			action: function (array $catalog, string $catalogId): JSONResponse {
				$configured = trim($this->appConfig->getValueString('opencatalogi', 'ooapi_consumers', ''));
				$accounts = [];
				if ($configured !== '') {
					$accounts = array_values(array_filter(array_map('trim', explode(',', $configured)), static fn (string $uid): bool => $uid !== ''));
				}

				return new JSONResponse(
					[
						'results' => $this->consumers->consumersOf($catalogId),
						'instanceAccounts' => [
							'accounts' => $accounts,
							'everyAccount' => $configured === '',
							'setting' => 'ooapi_consumers',
						],
					]
				);
			}
		);

	}//end index()

	/**
	 * Add a consumer to the catalogue.
	 *
	 * @param string $catalogSlug The catalogue slug.
	 *
	 * @return JSONResponse The consumer, 201.
	 *
	 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-an-administrator-issues-a-key-to-a-consumer-for-one-catalogue-req-fcc-001
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function create(string $catalogSlug): JSONResponse {
		return $this->inCatalog(
			catalogSlug: $catalogSlug,
			action: fn (array $catalog): JSONResponse => new JSONResponse(
				$this->consumers->addConsumer(
					$catalog,
					[
						'name' => $this->request->getParam('name', ''),
						'contact' => $this->request->getParam('contact', ''),
					]
				),
				Http::STATUS_CREATED
			)
		);

	}//end create()

	/**
	 * Generate a key for one of the catalogue's consumers. The key is in this answer only.
	 *
	 * @param string $catalogSlug The catalogue slug.
	 * @param string $id          The consumer id.
	 *
	 * @return JSONResponse The key and the consumer, 201.
	 *
	 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-keys-rotate-without-an-outage-req-fcc-003
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function generateKey(string $catalogSlug, string $id): JSONResponse {
		return $this->inCatalog(
			catalogSlug: $catalogSlug,
			action: function (array $catalog, string $catalogId) use ($id): JSONResponse {
				$this->consumers->consumerOf($catalogId, $id);
				$issued = $this->consumers->generateKey(
					$id,
					[
						'label' => $this->request->getParam('label', ''),
						'expiresAt' => $this->request->getParam('expiresAt'),
					]
				);

				$response = new JSONResponse($issued, Http::STATUS_CREATED);
				$response->addHeader('Cache-Control', 'no-store');
				return $response;
			}
		);

	}//end generateKey()

	/**
	 * Revoke a key of one of the catalogue's consumers.
	 *
	 * @param string $catalogSlug The catalogue slug.
	 * @param string $id          The consumer id.
	 * @param string $keyId       The key id.
	 *
	 * @return JSONResponse The consumer.
	 *
	 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-keys-rotate-without-an-outage-req-fcc-003
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function revokeKey(string $catalogSlug, string $id, string $keyId): JSONResponse {
		return $this->inCatalog(
			catalogSlug: $catalogSlug,
			action: function (array $catalog, string $catalogId) use ($id, $keyId): JSONResponse {
				$this->consumers->consumerOf($catalogId, $id);
				return new JSONResponse($this->consumers->revokeKey($id, $keyId));
			}
		);

	}//end revokeKey()

	/**
	 * Disable one of the catalogue's consumers and its account.
	 *
	 * @param string $catalogSlug The catalogue slug.
	 * @param string $id          The consumer id.
	 *
	 * @return JSONResponse The consumer.
	 *
	 * @spec openspec/changes/integration-feed-consumer-credentials/specs/ooapi-catalog-publication/spec.md#requirement-an-administrator-issues-a-key-to-a-consumer-for-one-catalogue-req-fcc-001
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function disable(string $catalogSlug, string $id): JSONResponse {
		return $this->inCatalog(
			catalogSlug: $catalogSlug,
			action: function (array $catalog, string $catalogId) use ($id): JSONResponse {
				$this->consumers->consumerOf($catalogId, $id);
				return new JSONResponse($this->consumers->disableConsumer($id));
			}
		);

	}//end disable()

	/**
	 * Run an action on a catalogue: 404 for an unknown catalogue or consumer,
	 * 400 for refused input, 500 for anything else.
	 *
	 * @param string   $catalogSlug The catalogue slug.
	 * @param callable $action      Gets the catalogue and its id.
	 *
	 * @return JSONResponse
	 */
	private function inCatalog(string $catalogSlug, callable $action): JSONResponse {
		try {
			$catalog = $this->catalogs->getCatalogBySlug($catalogSlug);
			$catalogId = (string)($catalog['id'] ?? ($catalog['@self']['id'] ?? ''));
			if ($catalog === null || $catalogId === '') {
				return new JSONResponse(['error' => $this->l10n->t('Catalog not found')], Http::STATUS_NOT_FOUND);
			}

			return $action($catalog, $catalogId);
		} catch (OutOfBoundsException $e) {
			return new JSONResponse(['error' => $this->l10n->t('Feed consumer or key not found')], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error('OpenCatalogi: feed consumer action failed', ['reason' => $e->getMessage()]);
			return new JSONResponse(['error' => $this->l10n->t('Internal server error')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end inCatalog()
}//end class
