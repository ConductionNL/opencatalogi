<?php

/**
 * OpenCatalogi repair step: every existing publication is for everyone.
 *
 * The `group: public` read rule of the publication schema admits only
 * `audience: public` (publications-for-signed-in-readers, REQ-PSR-002). A
 * publication stored before `audience` existed carries none, so this step sets
 * `audience: public` on every publication without one, and no publication drops
 * off the public side (REQ-PSR-001). A publication that already says who can
 * read it is left as it is, so the step is idempotent.
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
 * @spec openspec/changes/publications-for-signed-in-readers/specs/publications/spec.md#requirement-a-publication-says-who-can-read-it-req-psr-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Repair;

use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Sets `audience: public` on every publication that has none.
 *
 * @spec openspec/changes/publications-for-signed-in-readers/tasks.md#1-the-schema-and-the-rule
 */
class SetPublicationAudiencePublic implements IRepairStep {

	/**
	 * The slug of the publication schema.
	 */
	private const PUBLICATION_SLUG = 'publication';

	/**
	 * The audience an existing publication gets.
	 */
	public const AUDIENCE = 'public';

	/**
	 * How many publications one search reads.
	 */
	private const PAGE_SIZE = 200;

	/**
	 * OpenRegister's ObjectService for the run.
	 *
	 * @var object|null
	 */
	private ?object $objects = null;

	/**
	 * What the run did.
	 *
	 * @var array{set: int, kept: int, failed: int}
	 */
	private array $counts = ['set' => 0, 'kept' => 0, 'failed' => 0];

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settings The registers and OpenRegister's ObjectService.
	 * @param LoggerInterface          $logger   Records a publication that could not be saved.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The name of the step.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Make every existing publication readable by everyone (audience public)';
	}//end getName()

	/**
	 * Set `audience: public` on every publication without an audience.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publications-for-signed-in-readers/specs/publications/spec.md#requirement-a-publication-says-who-can-read-it-req-psr-001
	 */
	public function run(IOutput $output): void {
		$objects = $this->settings->getObjectService();
		if ($objects === null) {
			$output->info('SetPublicationAudiencePublic: OpenRegister is not available; nothing to do.');
			return;
		}

		$this->objects = $objects;
		$this->counts = ['set' => 0, 'kept' => 0, 'failed' => 0];
		foreach ($this->publicationScopes() as [$register, $schema]) {
			$this->setInScope(scope: ['register' => $register, 'schema' => $schema]);
		}

		$counts = $this->counts;
		$output->info(
			'SetPublicationAudiencePublic: ' . $counts['set'] . ' publication(s) set to audience public, '
			. $counts['kept'] . ' already had an audience, ' . $counts['failed'] . ' could not be saved.'
		);
	}//end run()

	/**
	 * Every register and schema id pair of a publication schema.
	 *
	 * @return array<int, array{0: string, 1: string}>
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
	 * Walk one register and schema page by page.
	 *
	 * @param array{register: string, schema: string} $scope The pair.
	 *
	 * @return void
	 */
	private function setInScope(array $scope): void {
		$page = 1;
		do {
			$rows = (array)($this->objects->searchObjectsPaginated(
				query: ['@self' => $scope, '_limit' => self::PAGE_SIZE, '_page' => $page],
				_rbac: false,
				_multitenancy: false
			)['results'] ?? []);
			foreach ($rows as $row) {
				$this->setOne(publication: self::asArray(row: $row), scope: $scope);
			}

			$page++;
		} while (count($rows) === self::PAGE_SIZE);
	}//end setInScope()

	/**
	 * Give one publication the audience public when it has none.
	 *
	 * @param array<string, mixed>                    $publication The publication.
	 * @param array{register: string, schema: string} $scope       Where it is stored.
	 *
	 * @return void
	 */
	private function setOne(array $publication, array $scope): void {
		$audience = $publication['audience'] ?? null;
		if (is_string($audience) === true && $audience !== '') {
			$this->counts['kept']++;
			return;
		}

		$uuid = (string)($publication['id'] ?? ($publication['@self']['id'] ?? ''));
		unset($publication['@self']);
		$publication['audience'] = self::AUDIENCE;
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
			$this->counts['set']++;
		} catch (\Throwable $e) {
			$this->counts['failed']++;
			$this->logger->warning('SetPublicationAudiencePublic: publication ' . $uuid . ' could not be saved: ' . $e->getMessage());
		}
	}//end setOne()

	/**
	 * A search row as an array.
	 *
	 * @param mixed $row An ObjectEntity or an array.
	 *
	 * @return array<string, mixed>
	 */
	private static function asArray(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return (array)$row;
	}//end asArray()
}//end class
