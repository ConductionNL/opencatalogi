<?php

/**
 * Gives every existing publication a stored lifecycle state on upgrade (REQ-PLC-007).
 *
 * @category Repair
 * @package  OCA\OpenCatalogi\Repair
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
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Repair;

use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sets the stored state of every publication whose `status` is empty, then makes the public read rule require it.
 *
 * A publication with a publication date becomes `published`, with `firstReleasedAt` set to that date, so a
 * publication that was public stays public; one without a date becomes `draft`. A stored state is never changed.
 * On an install where the schema import was skipped because the version did not move, the public read rule still
 * matches the dates only; it gets `status: published` added, after the states are written.
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
 */
class BackfillPublicationLifecycleState implements IRepairStep {

	/**
	 * The slug of the publication schema in every register.
	 */
	private const PUBLICATION_SLUG = 'publication';

	/**
	 * Publications read per page.
	 */
	private const PAGE_SIZE = 200;

	/**
	 * OpenRegister's object service, for the run in progress.
	 *
	 * @var object|null
	 */
	private ?object $objects = null;

	/**
	 * The running counts of the run in progress.
	 *
	 * @var array{published: int, draft: int, kept: int, failed: int}
	 */
	private array $counts = ['published' => 0, 'draft' => 0, 'kept' => 0, 'failed' => 0];

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settings  Reaches OpenRegister's registers and object service.
	 * @param ContainerInterface       $container Reaches OpenRegister's schema mapper for the read rule.
	 * @param LoggerInterface          $logger    PSR-3 logger.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settings,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
	 */
	public function getName(): string {
		return 'Give every existing publication a stored lifecycle state';
	}//end getName()

	/**
	 * Write the missing states, report the counts, then bring the public read rule in line.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
	 */
	public function run(IOutput $output): void {
		$objects = $this->settings->getObjectService();
		if ($objects === null) {
			$output->info('BackfillPublicationLifecycleState: OpenRegister is not available; nothing to do.');
			return;
		}

		$this->objects = $objects;
		$this->counts  = ['published' => 0, 'draft' => 0, 'kept' => 0, 'failed' => 0];
		foreach ($this->publicationScopes() as [$register, $schema]) {
			$this->backfillScope(scope: ['register' => $register, 'schema' => $schema]);
		}

		$counts = $this->counts;
		$output->info(
			'BackfillPublicationLifecycleState: ' . $counts['published'] . ' publication(s) set to published, '
			. $counts['draft'] . ' set to draft, ' . $counts['kept'] . ' kept their state, '
			. $counts['failed'] . ' could not be saved.'
		);

		// After the states: a rule that requires `published` before they are written hides every legacy publication.
		$this->requirePublishedInReadRules(output: $output);

	}//end run()

	/**
	 * Every register and schema pair whose schema is the publication schema.
	 *
	 * @return array<int, array{0: string, 1: string}> The pairs.
	 */
	private function publicationScopes(): array {
		$scopes = [];
		foreach (($this->settings->getSettings()['availableRegisters'] ?? []) as $register) {
			foreach ((array)($register['schemas'] ?? []) as $schema) {
				if (is_array($schema) === true && strtolower((string)($schema['slug'] ?? '')) === self::PUBLICATION_SLUG) {
					$scopes[] = [(string)($register['id'] ?? ''), (string)($schema['id'] ?? '')];
				}
			}
		}

		return array_values(array_filter($scopes, static fn (array $scope): bool => $scope[0] !== '' && $scope[1] !== ''));
	}//end publicationScopes()

	/**
	 * Backfill every publication of one register and schema, page by page.
	 *
	 * @param array{register: string, schema: string} $scope The register and schema.
	 *
	 * @return void
	 */
	private function backfillScope(array $scope): void {
		$page = 1;
		do {
			$rows = (array)($this->objects->searchObjectsPaginated(
				query: ['@self' => $scope, '_limit' => self::PAGE_SIZE, '_page' => $page],
				_rbac: false,
				_multitenancy: false
			)['results'] ?? []);
			foreach ($rows as $row) {
				$this->backfillOne(publication: self::asArray(row: $row), scope: $scope);
			}

			$pageCount = count($rows);
			$page++;
		} while ($pageCount === self::PAGE_SIZE);

	}//end backfillScope()

	/**
	 * Give one publication a state when it has none.
	 *
	 * @param array<string, mixed>                    $publication The publication.
	 * @param array{register: string, schema: string} $scope       The register and schema.
	 *
	 * @return void
	 */
	private function backfillOne(array $publication, array $scope): void {
		if (trim((string)($publication['status'] ?? '')) !== '') {
			$this->counts['kept']++;
			return;
		}

		$uuid = (string)($publication['id'] ?? ($publication['@self']['id'] ?? ''));
		unset($publication['@self']);
		$date  = trim((string)($publication['publicationDate'] ?? ''));
		$state = 'draft';
		if ($date !== '') {
			$state = 'published';
			$publication['firstReleasedAt'] = $date;
		}

		$publication['status'] = $state;
		try {
			$this->objects->saveObject(
				object: $publication,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $scope['register'], schema: $scope['schema']),
					uuid: $uuid
				),
				_rbac: false,
				_multitenancy: false
			);
			$this->counts[$state]++;
		} catch (\Throwable $e) {
			$this->counts['failed']++;
			$this->logger->warning('BackfillPublicationLifecycleState: publication ' . $uuid . ' could not be saved: ' . $e->getMessage());
		}

	}//end backfillOne()

	/**
	 * Add `status: published` to every public date rule of a publication schema that lacks a status condition.
	 *
	 * Idempotent: a rule that already names a status, or names no publication date, is left as it is.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 */
	private function requirePublishedInReadRules(IOutput $output): void {
		try {
			$mapper  = $this->container->get('OCA\\OpenRegister\\Db\\SchemaMapper');
			$schemas = $mapper->findAll();
		} catch (\Throwable $e) {
			$output->warning('BackfillPublicationLifecycleState: schema mapper unavailable, read rule not checked: ' . $e->getMessage());
			return;
		}

		foreach ($schemas as $schema) {
			if (strtolower((string)$schema->getSlug()) !== self::PUBLICATION_SLUG) {
				continue;
			}

			$authorization = $schema->getAuthorization();
			if (is_array($authorization) === false || is_array($authorization['read'] ?? null) === false) {
				continue;
			}

			$read = self::withPublishedRequired(read: $authorization['read']);
			if ($read === $authorization['read']) {
				continue;
			}

			$authorization['read'] = $read;
			$schema->setAuthorization($authorization);
			try {
				$mapper->update($schema);
				$output->info('BackfillPublicationLifecycleState: the public read rule of schema ' . $schema->getId() . ' now requires published.');
			} catch (\Throwable $e) {
				$output->warning('BackfillPublicationLifecycleState: read rule of schema ' . $schema->getId() . ' not saved: ' . $e->getMessage());
			}
		}

	}//end requirePublishedInReadRules()

	/**
	 * The read rules with `status: published` added to each public rule that matches on the publication date.
	 *
	 * @param array<int|string, mixed> $read The read rules.
	 *
	 * @return array<int|string, mixed> The rules.
	 */
	private static function withPublishedRequired(array $read): array {
		foreach ($read as $index => $rule) {
			if (is_array($rule) === false || ($rule['group'] ?? null) !== 'public' || is_array($rule['match'] ?? null) === false) {
				continue;
			}

			if (array_key_exists('publicationDate', $rule['match']) === false || array_key_exists('status', $rule['match']) === true) {
				continue;
			}

			$read[$index]['match'] = ['status' => 'published'] + $rule['match'];
		}

		return $read;
	}//end withPublishedRequired()

	/**
	 * A search row as an array.
	 *
	 * @param mixed $row An array or a serialisable entity.
	 *
	 * @return array<string, mixed> The row.
	 */
	private static function asArray(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return (array)$row;
	}//end asArray()
}//end class
