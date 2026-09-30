<?php

/**
 * OpenCatalogi Portal Account Removed Listener.
 *
 * When a resident removes their portal account, their dossiers and saved
 * searches go with it (hydra `woo-citizen-journey`, C7). portaliq has no
 * removal event: `PortalSelfServiceService::removeAccount()` updates the
 * resident's `portalAccount` object (register `portaliq`) to
 * `status: removed`. This listener hears that OpenRegister update and deletes
 * every `collection` and `savedSearch` whose `owner` is the account's
 * subject reference.
 *
 * It runs inside OpenRegister's save of another app's object, so it never
 * throws: a failure is logged and the account's save stands.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-removing-a-portal-account-deletes-that-residents-dossiers-req-ccol-007
 * @template-implements IEventListener<Event>
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Service\Portal\CitizenCollectionService;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deletes a removed resident's dossiers and saved searches.
 *
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-removing-a-portal-account-deletes-that-residents-dossiers-req-ccol-007
 * @template-implements IEventListener<Event>
 */
class PortalAccountRemovedListener implements IEventListener {

	/**
	 * The register portaliq keeps its accounts in.
	 */
	public const ACCOUNT_REGISTER = 'portaliq';

	/**
	 * The account schema.
	 */
	public const ACCOUNT_SCHEMA = 'portalAccount';

	/**
	 * The status portaliq sets on removal.
	 */
	public const REMOVED = 'removed';

	/**
	 * OpenRegister's id-to-slug maps, read once per request.
	 *
	 * @var array{register: array<mixed>, schema: array<mixed>}|null
	 */
	private ?array $slugs = null;

	/**
	 * Constructor.
	 *
	 * @param CitizenCollectionService $collections Deletes the resident's objects.
	 * @param ContainerInterface       $container   Resolves OpenRegister's mappers.
	 * @param LoggerInterface          $logger      The logger.
	 */
	public function __construct(
		private readonly CitizenCollectionService $collections,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle an OpenRegister object update.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-removing-a-portal-account-deletes-that-residents-dossiers-req-ccol-007
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		try {
			$old = $event->getOldObject();
			$new = $event->getNewObject();
			if ($old === null || $this->isPortalAccount(register: (string)$new->getRegister(), schema: (string)$new->getSchema()) === false) {
				return;
			}

			$newData = $new->getObject();
			$oldData = $old->getObject();
			if (($newData['status'] ?? null) !== self::REMOVED || ($oldData['status'] ?? null) === self::REMOVED) {
				return;
			}

			$subjectRef = (string)($newData['subjectRef'] ?? '');
			if ($subjectRef === '') {
				$subjectRef = (string)($oldData['subjectRef'] ?? '');
			}

			$deleted = $this->collections->removeEverythingOf(owner: $subjectRef);
			$this->logger->info('OpenCatalogi: removed a closed portal account\'s dossiers and saved searches', ['deleted' => $deleted]);
		} catch (Throwable $e) {
			$this->logger->warning('OpenCatalogi: could not clean up after a removed portal account; the removal itself stands', ['reason' => $e->getMessage()]);
		}//end try

	}//end handle()

	/**
	 * Whether a register and schema, as ids or slugs, are portaliq's accounts.
	 *
	 * @param string $register The object's register.
	 * @param string $schema   The object's schema.
	 *
	 * @return bool
	 */
	private function isPortalAccount(string $register, string $schema): bool {
		return $this->slugOf(map: 'register', value: $register) === self::ACCOUNT_REGISTER
			&& $this->slugOf(map: 'schema', value: $schema) === self::ACCOUNT_SCHEMA;

	}//end isPortalAccount()

	/**
	 * The slug for an id, or the value itself when it already is one.
	 *
	 * @param string $map   `register` or `schema`.
	 * @param string $value The id or slug.
	 *
	 * @return string
	 */
	private function slugOf(string $map, string $value): string {
		if ($this->slugs === null) {
			$this->slugs = ['register' => [], 'schema' => []];
			foreach (['register' => 'OCA\OpenRegister\Db\RegisterMapper', 'schema' => 'OCA\OpenRegister\Db\SchemaMapper'] as $key => $class) {
				try {
					$this->slugs[$key] = (array)$this->container->get($class)->getIdToSlugMap();
				} catch (Throwable $e) {
					$this->logger->debug('OpenCatalogi: no '.$key.' slugs', ['reason' => $e->getMessage()]);
				}
			}
		}

		$slug = ($this->slugs[$map][$value] ?? null);
		if (is_string($slug) === true && $slug !== '') {
			return $slug;
		}

		return $value;

	}//end slugOf()
}//end class
