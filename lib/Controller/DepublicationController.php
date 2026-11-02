<?php

/**
 * OpenCatalogi Depublication Controller.
 *
 * Taking a publication back, and recording that each channel it reached has
 * acknowledged the withdrawal. The two write paths REQ-PIN-106 asks for.
 * Without the acknowledgement half, a withdrawal can be sent and can never be
 * acknowledged, so every depublication stays outstanding for ever and the
 * organisation cannot leave that state.
 *
 * Both paths STORE. A depublication that only ever existed inside one response
 * cannot carry an acknowledgement, so the failure to store is answered as a
 * failure (503, "the withdrawals were sent but could not be recorded") and
 * never swallowed: the letter goes out either way, and it is the evidence that
 * would otherwise be lost.
 *
 * Split out of PublicationRulesController on 2026-09-19. Both routes keep
 * their URL and their verb; only the route NAME changed, from
 * `publicationRules#<action>` to `depublication#<action>`, and no code
 * resolves these by name.
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
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\DepublicationServiceInterface;
use OCA\OpenCatalogi\Contract\ServiceCatalogueServiceInterface;
use OCA\OpenCatalogi\Service\Catalogue\CatalogueUnreadableException;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;

/**
 * Depublication and its acknowledgements. Every write here is admin-gated.
 *
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
 */
class DepublicationController extends Controller {
	use ReadsOpenRegisterResults;
	use ResolvesRegisterConfiguration;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param IL10N $l10n Localisation.
	 * @param IUserSession $userSession The current session.
	 * @param DepublicationServiceInterface $depublicationService Taking a publication back.
	 * @param ContainerInterface $container Server container, for the register resolver.
	 * @param ServiceCatalogueServiceInterface $objects The OpenRegister reader that refuses rather than defaulting.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IL10N $l10n,
		private readonly IUserSession $userSession,
		private readonly DepublicationServiceInterface $depublicationService,
		private readonly ContainerInterface $container,
		private readonly ServiceCatalogueServiceInterface $objects,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Who is acting, for the record.
	 *
	 * @return string The user id, or an empty string.
	 */
	private function actor(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();

	}//end actor()

	/**
	 * The register and schema the depublications live in.
	 *
	 * @return array<string, string> The register and schema identifiers.
	 */
	private function depublicationConfiguration(): array {
		return $this->resolveRegisterConfiguration(
			registerKey: 'publication_register',
			schemaKey: 'depublication_schema'
		);

	}//end depublicationConfiguration()

	/**
	 * Depublish in one action and withdraw from every channel it reached.
	 *
	 * @return JSONResponse The depublication, with any outstanding channel named.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function depublish(): JSONResponse {
		$publication = $this->request->getParam('publication', []);
		if (is_array($publication) === false || $publication === []) {
			return new JSONResponse(data: ['error' => 'missing-publication'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		try {
			$depublication = $this->depublicationService->depublish(
				publication: $publication,
				reason: trim((string)$this->request->getParam('reason', '')),
				depublishedBy: $this->actor(),
				channels: $this->requestedChannels()
			);
		} catch (\DomainException $e) {
			return new JSONResponse(
				data: ['error' => 'depublication-refused', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return $this->storeDepublication(depublication: $depublication);

	}//end depublish()

	/**
	 * The channels the request asks to withdraw from, as strings; none when it names no list.
	 *
	 * @return array<int, string> The channels.
	 */
	private function requestedChannels(): array {
		$channels = $this->request->getParam('channels', []);
		if (is_array($channels) === false) {
			return [];
		}

		return array_map('strval', $channels);

	}//end requestedChannels()

	/**
	 * Store a new depublication and answer it with what is still outstanding.
	 *
	 * The depublication is STORED, not just returned. REQ-PIN-106 says each
	 * channel's acknowledgement is recorded and an unacknowledged withdrawal is
	 * shown as outstanding; neither is possible against a value that only ever
	 * existed inside one response.
	 *
	 * @param array<string, mixed> $depublication The depublication.
	 *
	 * @return JSONResponse The stored depublication, or why it could not be stored.
	 */
	private function storeDepublication(array $depublication): JSONResponse {
		try {
			$saved = $this->objects->getObjectService()->saveObject(
				object: $depublication,
				extend: [],
				register: $this->depublicationConfiguration()['register'],
				schema: $this->depublicationConfiguration()['schema']
			);
		} catch (CatalogueUnreadableException $e) {
			return new JSONResponse(
				data: [
					'error' => 'depublication-unstored',
					'message' => $this->l10n->t('The withdrawals were sent but could not be recorded.'),
				],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		return $this->withOutstanding(saved: $saved);

	}//end storeDepublication()

	/**
	 * A stored depublication with its outstanding channels and whether it is complete.
	 *
	 * @param mixed $saved What the object store answered.
	 *
	 * @return JSONResponse The depublication.
	 */
	private function withOutstanding(mixed $saved): JSONResponse {
		$stored = $this->asArray(object: $saved);
		$outstanding = $this->depublicationService->outstandingChannels(depublication: $stored);

		return new JSONResponse(
			array_merge(
				$stored,
				[
					'outstandingChannels' => $outstanding,
					'complete' => ($outstanding === []),
				]
			)
		);

	}//end withOutstanding()

	/**
	 * Record that a channel acknowledged its withdrawal.
	 *
	 * The write path REQ-PIN-106 asks for. Without it a withdrawal can be sent
	 * and can never be acknowledged, so every depublication stays outstanding
	 * for ever and the organisation cannot leave that state.
	 *
	 * An acknowledgement is only ever recorded against a channel the
	 * depublication actually sent a withdrawal to. A channel nobody wrote to
	 * cannot acknowledge on its behalf, which is the failure that would let a
	 * document be reported as gone from a harvester that still holds it.
	 *
	 * @return JSONResponse The depublication with what is still outstanding.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function acknowledgeWithdrawal(): JSONResponse {
		$id = trim((string)$this->request->getParam('depublication', ''));
		$channel = trim((string)$this->request->getParam('channel', ''));
		if ($id === '' || $channel === '') {
			return $this->badRequest(
				error: 'missing-parameters',
				message: $this->l10n->t('Name the depublication and the channel that acknowledged.')
			);
		}

		$found = $this->findDepublication(id: $id);
		if ($found instanceof JSONResponse) {
			return $found;
		}

		if ($this->withdrawalWasSent(depublication: $found['depublication'], channel: $channel) === false) {
			return $this->badRequest(
				error: 'unknown-channel',
				message: $this->l10n->t('No withdrawal was sent to that channel, so there is nothing for it to acknowledge.')
			);
		}

		$found['depublication'] = $this->depublicationService->recordAcknowledgement(
			depublication: $found['depublication'],
			channel: $channel,
			answer: trim((string)$this->request->getParam('answer', ''))
		);

		return $this->storeAcknowledgement(found: $found);

	}//end acknowledgeWithdrawal()

	/**
	 * A 400 answer naming the error and explaining it.
	 *
	 * @param string $error The error code.
	 * @param string $message The translated explanation.
	 *
	 * @return JSONResponse The 400 answer.
	 */
	private function badRequest(string $error, string $message): JSONResponse {
		return new JSONResponse(
			data: ['error' => $error, 'message' => $message],
			statusCode: Http::STATUS_BAD_REQUEST
		);

	}//end badRequest()

	/**
	 * Read a stored depublication with the register and schema it lives in.
	 *
	 * @param string $id The depublication's id.
	 *
	 * @return array<string, mixed>|JSONResponse The `id`, `config` and `depublication`, or the 503 or 404 answer.
	 */
	private function findDepublication(string $id): array|JSONResponse {
		try {
			$config = $this->depublicationConfiguration();
			$objectService = $this->objects->getObjectService();
			$depublication = $this->asArray(
				object: $objectService->find(id: $id, register: $config['register'], schema: $config['schema'])
			);
		} catch (CatalogueUnreadableException $e) {
			return new JSONResponse(
				data: ['error' => 'depublication-unreadable'],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		} catch (\Throwable $e) {
			return new JSONResponse(data: ['error' => 'not-found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return ['id' => $id, 'config' => $config, 'depublication' => $depublication];

	}//end findDepublication()

	/**
	 * Whether a depublication sent a withdrawal to a channel.
	 *
	 * A channel nobody wrote to cannot acknowledge on its behalf, which is the
	 * failure that would let a document be reported as gone from a harvester
	 * that still holds it.
	 *
	 * @param array<string, mixed> $depublication The stored depublication.
	 * @param string $channel The channel that acknowledged.
	 *
	 * @return bool True when a withdrawal was sent to the channel.
	 */
	private function withdrawalWasSent(array $depublication, string $channel): bool {
		$channels = array_map(
			static fn (array $withdrawal): string => (string)($withdrawal['channel'] ?? ''),
			array_filter((array)($depublication['withdrawals'] ?? []), 'is_array')
		);

		return in_array($channel, $channels, true);

	}//end withdrawalWasSent()

	/**
	 * Store an acknowledged depublication under its own id and answer it with what is still outstanding.
	 *
	 * @param array<string, mixed> $found The `id`, `config` and `depublication` from findDepublication().
	 *
	 * @return JSONResponse The depublication, or the 503 answer.
	 */
	private function storeAcknowledgement(array $found): JSONResponse {
		try {
			$saved = $this->objects->getObjectService()->saveObject(
				object: $found['depublication'],
				extend: [],
				register: $found['config']['register'],
				schema: $found['config']['schema'],
				uuid: $found['id']
			);
		} catch (CatalogueUnreadableException $e) {
			return new JSONResponse(
				data: ['error' => 'depublication-unstored'],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		return $this->withOutstanding(saved: $saved);

	}//end storeAcknowledgement()
}//end class
