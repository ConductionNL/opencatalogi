<?php

/**
 * Harvest feeds: OpenRegister sources owned by OpenCatalogi.
 *
 * A feed is an OpenRegister `Source` of type `opencatalogi.dcat-jsonld`,
 * written through OpenRegister's `SourceService` (never the mapper). This
 * service is not a pass-through: it is admin only, it lists only this app's
 * sources, it works out the register and schema from the catalogue (refusing a
 * schema outside it), and it writes the fields that keep a harvested dataset a
 * draft that names its source on every save.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Harvest
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

namespace OCA\OpenCatalogi\Service\Harvest;

use InvalidArgumentException;
use OCA\OpenCatalogi\Contract\HarvestFeedServiceInterface;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCP\IGroupManager;
use OCP\IUserSession;
use Throwable;

/**
 * Lists, saves and deletes this app's harvest feeds.
 *
 * @spec openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#requirement-a-harvest-feed-is-an-openregister-source-owned-by-opencatalogi-req-hfi-002
 */
class HarvestFeedService implements HarvestFeedServiceInterface {

	/**
	 * The application every feed is owned by.
	 */
	public const APPLICATION = 'opencatalogi';

	/**
	 * The fetcher every feed runs.
	 */
	public const TYPE = 'opencatalogi.dcat-jsonld';

	/**
	 * The publication fields a harvest never writes, so a harvested dataset
	 * stays a draft until an editor publishes it (REQ-HFI-004).
	 *
	 * @var array<int, string>
	 */
	public const PROTECTED_FIELDS = ['publicationDate', 'depublicationDate', 'status'];

	/**
	 * Where OpenRegister writes the dataset's `@id` and the source's uuid (REQ-HFI-005).
	 *
	 * @var array<string, string>
	 */
	public const PROVENANCE = ['externalIdProperty' => 'source', 'sourceProperty' => 'derivedFrom'];

	/**
	 * The fields an administrator sets on a feed.
	 *
	 * @var array<int, string>
	 */
	private const EDITABLE = ['title', 'description', 'config', 'mappingId', 'schedule', 'runAs', 'syncEnabled', 'maxItemsPerRun'];

	/**
	 * OpenRegister's source writer.
	 */
	private const SOURCE_SERVICE = 'OCA\OpenRegister\Service\Sync\SourceService';

	/**
	 * OpenRegister's source table, read for the list.
	 */
	private const SOURCE_MAPPER = 'OCA\OpenRegister\Db\SourceMapper';

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterLookup    $openRegister OpenRegister's services.
	 * @param HarvestFeedCatalogues $catalogues   The register and schema of a feed's catalogue.
	 * @param IUserSession          $session      The caller.
	 * @param IGroupManager         $groups       Whether the caller is an administrator.
	 */
	public function __construct(
		private readonly OpenRegisterLookup $openRegister,
		private readonly HarvestFeedCatalogues $catalogues,
		private readonly IUserSession $session,
		private readonly IGroupManager $groups,
	) {
	}//end __construct()

	/**
	 * This app's feeds.
	 *
	 * @return array<int, array<string, mixed>> The feeds, as OpenRegister serialises its sources.
	 *
	 * @throws HarvestFeedRefused When the caller is not an administrator.
	 *
	 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-2.1
	 */
	public function list(): array {
		$this->assertAdmin();

		$sources = $this->sourceMapper()->findAll(null, null, ['application' => self::APPLICATION]);

		$feeds = [];
		foreach ($sources as $source) {
			if ($source->getApplication() === self::APPLICATION) {
				$feeds[] = $source->jsonSerialize();
			}
		}

		return $feeds;
	}//end list()

	/**
	 * Save a new feed.
	 *
	 * @param array<string, mixed> $data The feed's fields; `targetSchema` is the schema picked in the catalogue.
	 *
	 * @return array<string, mixed> The stored feed.
	 *
	 * @throws HarvestFeedRefused When the caller is not an administrator, or a field is refused.
	 *
	 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-2.1
	 */
	public function create(array $data): array {
		$this->assertAdmin();

		$fields = array_merge(
			$this->editableFields(data: $data),
			$this->target(config: ($data['config'] ?? null), schema: ($data['targetSchema'] ?? null)),
			['conflictStrategy' => 'manual', 'syncEnabled' => (bool) ($data['syncEnabled'] ?? true)],
			$this->fixedFields()
		);

		return $this->write(write: static fn (object $sources): object => $sources->create(data: $fields));
	}//end create()

	/**
	 * Change a feed. A save that touches neither the source nor the schema
	 * (Switch off, a new schedule) keeps the stored target.
	 *
	 * @param int                  $id   The feed's source id.
	 * @param array<string, mixed> $data The changed fields.
	 *
	 * @return array<string, mixed> The stored feed.
	 *
	 * @throws HarvestFeedRefused When the caller is not an administrator, the feed is not this app's, or a field is refused.
	 *
	 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-2.1
	 */
	public function update(int $id, array $data): array {
		$this->assertAdmin();
		$current = $this->ownSource(id: $id);

		$fields = $this->editableFields(data: $data);
		if (array_key_exists('config', $data) === true || array_key_exists('targetSchema', $data) === true) {
			$fields = array_merge(
				$fields,
				$this->target(
					config: ($data['config'] ?? $current->getConfig()),
					schema: ($data['targetSchema'] ?? $current->getTargetSchema())
				)
			);
		}

		$fields = array_merge($fields, $this->fixedFields());

		return $this->write(write: static fn (object $sources): object => $sources->update(id: $id, data: $fields));
	}//end update()

	/**
	 * Delete a feed and its flow; OpenRegister keeps its sync records as history.
	 *
	 * @param int $id The feed's source id.
	 *
	 * @return void
	 *
	 * @throws HarvestFeedRefused When the caller is not an administrator, or the feed is not this app's.
	 *
	 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-2.1
	 */
	public function delete(int $id): void {
		$this->assertAdmin();
		$this->ownSource(id: $id);

		$this->sourceService()->delete(id: $id);
	}//end delete()

	/**
	 * The fields every feed carries on every save, whatever the request says.
	 *
	 * @return array<string, mixed> The fields.
	 *
	 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-2.1
	 */
	public function fixedFields(): array {
		return [
			'application' => self::APPLICATION,
			'type' => self::TYPE,
			'protectedFields' => self::PROTECTED_FIELDS,
			'provenance' => self::PROVENANCE,
			'identityProperty' => 'source',
			'deleteStrategy' => 'flag',
		];
	}//end fixedFields()

	/**
	 * The fields of the request an administrator may set.
	 *
	 * @param array<string, mixed> $data The request.
	 *
	 * @return array<string, mixed> The fields.
	 */
	private function editableFields(array $data): array {
		return array_intersect_key($data, array_flip(self::EDITABLE));
	}//end editableFields()

	/**
	 * The register and schema of the feed's catalogue.
	 *
	 * @param mixed $config The feed config, with `targetCatalog`.
	 * @param mixed $schema The schema picked.
	 *
	 * @return array{targetRegister: string, targetSchema: string} The target.
	 *
	 * @throws HarvestFeedRefused When the catalogue or schema is missing or refused.
	 */
	private function target(mixed $config, mixed $schema): array {
		$catalogId = null;
		if (is_array($config) === true) {
			$catalogId = ($config['targetCatalog'] ?? null);
		}

		if (is_string($catalogId) === false || $catalogId === '') {
			throw new HarvestFeedRefused(status: 422, field: 'targetCatalog', message: 'A harvest feed needs a catalogue.');
		}

		if ((is_string($schema) === false && is_int($schema) === false) || $schema === '') {
			throw new HarvestFeedRefused(status: 422, field: 'targetSchema', message: 'A harvest feed needs a schema of its catalogue.');
		}

		return $this->catalogues->target(catalogId: $catalogId, schemaId: $schema);
	}//end target()

	/**
	 * Run a write on OpenRegister's source service, showing its refusal on the field it names.
	 *
	 * @param callable(object): object $write The write.
	 *
	 * @return array<string, mixed> The stored feed.
	 *
	 * @throws HarvestFeedRefused When OpenRegister refuses a field.
	 */
	private function write(callable $write): array {
		try {
			return $write($this->sourceService())->jsonSerialize();
		} catch (InvalidArgumentException $e) {
			$field = 'config';
			if (property_exists($e, 'field') === true) {
				$field = (string) $e->field;
			}

			throw new HarvestFeedRefused(status: 422, field: $field, message: $e->getMessage());
		}
	}//end write()

	/**
	 * The source, when it is one of this app's feeds.
	 *
	 * @param int $id The source id.
	 *
	 * @return object The source.
	 *
	 * @throws HarvestFeedRefused When it does not exist or is not this app's.
	 */
	private function ownSource(int $id): object {
		try {
			$source = $this->sourceMapper()->find($id);
		} catch (Throwable) {
			throw new HarvestFeedRefused(status: 404, message: 'Harvest feed ' . $id . ' does not exist.');
		}

		if ($source->getApplication() !== self::APPLICATION) {
			throw new HarvestFeedRefused(status: 404, message: 'Harvest feed ' . $id . ' does not exist.');
		}

		return $source;
	}//end ownSource()

	/**
	 * Refuse anyone but an administrator.
	 *
	 * @return void
	 *
	 * @throws HarvestFeedRefused When the caller is not an administrator.
	 */
	private function assertAdmin(): void {
		$user = $this->session->getUser();
		if ($user === null || $this->groups->isAdmin($user->getUID()) === false) {
			throw new HarvestFeedRefused(status: 403, message: 'Only an administrator can manage harvest feeds.');
		}
	}//end assertAdmin()

	/**
	 * OpenRegister's source writer.
	 *
	 * @return object The service.
	 */
	private function sourceService(): object {
		return $this->openRegister->installedService(serviceId: self::SOURCE_SERVICE, unavailable: 'OpenRegister is not installed.');
	}//end sourceService()

	/**
	 * OpenRegister's source table.
	 *
	 * @return object The mapper.
	 */
	private function sourceMapper(): object {
		return $this->openRegister->installedService(serviceId: self::SOURCE_MAPPER, unavailable: 'OpenRegister is not installed.');
	}//end sourceMapper()
}//end class
