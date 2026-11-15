<?php

/**
 * OpenCatalogi SuggestPublicationMetadata job
 *
 * Runs the rules floor for one newly created publication, after its save.
 *
 * @category BackgroundJob
 * @package  OCA\OpenCatalogi\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\BackgroundJob;

use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\ReadsObjectProperties;
use OCA\OpenCatalogi\Service\Woo\MetadataSuggestionStore;
use OCA\OpenCatalogi\Service\WooMetadataSuggestionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The rules floor for one publication (design D2), queued so the save does not wait.
 *
 * The argument names the publication: `register`, `schema` and `id`. The job
 * reads what the instance knows around it (its schema's slug, the
 * organisation of the catalogue it sits in, the suggestions it already has),
 * asks WooMetadataSuggestionService for proposals and stores each as a
 * pending suggestion. Nothing is written to the publication itself.
 */
class SuggestPublicationMetadata extends QueuedJob {
	use ReadsObjectProperties;

	/**
	 * The most catalogues read to find the one a publication sits in.
	 *
	 * @var int
	 */
	private const CATALOG_PAGE = 200;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The clock.
	 * @param WooMetadataSuggestionService $suggestions The rules floor.
	 * @param MetadataSuggestionStore $store Where suggestions are stored.
	 * @param OpenRegisterLookup $openRegister OpenRegister's services.
	 * @param IAppConfig $config Holds the catalogue register and schema.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly WooMetadataSuggestionService $suggestions,
		private readonly MetadataSuggestionStore $store,
		private readonly OpenRegisterLookup $openRegister,
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

	}//end __construct()

	/**
	 * Propose and store the missing Woo metadata of one publication.
	 *
	 * @param mixed $argument `register`, `schema` and `id` of the publication.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
	 */
	protected function run($argument): void {
		$register = (string)($argument['register'] ?? '');
		$schema = (string)($argument['schema'] ?? '');
		$id = (string)($argument['id'] ?? '');
		if ($register === '' || $schema === '' || $id === '' || $this->store->isConfigured() === false) {
			return;
		}

		try {
			$this->suggestFor(register: $register, schema: $schema, id: $id);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[SuggestPublicationMetadata] no suggestions for ' . $id . ': ' . $e->getMessage(),
				context: ['exception' => $e]
			);
		}

	}//end run()

	/**
	 * Read the publication and its surroundings, then store each proposal.
	 *
	 * @param string $register The publication's register id.
	 * @param string $schema The publication's schema id.
	 * @param string $id The publication's uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
	 */
	private function suggestFor(string $register, string $schema, string $id): void {
		$publication = $this->asArray(
			object: $this->openRegister->objectService()->find(id: $id, register: $register, schema: $schema)
		);
		if ($publication === []) {
			return;
		}

		$context = [
			'schemaSlug' => $this->schemaSlug(schema: $schema),
			'catalogOrganization' => $this->catalogOrganisation(schema: $schema),
			'existing' => $this->store->forPublication(publicationId: $id),
		];

		foreach ($this->suggestions->suggestFromRules(publication: $publication, context: $context) as $proposal) {
			$this->store->create(proposal: $proposal, publicationId: $id);
		}
	}//end suggestFor()

	/**
	 * The slug of the publication's schema, null when it cannot be read.
	 *
	 * @param string $schema The schema id.
	 *
	 * @return string|null The slug.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
	 */
	private function schemaSlug(string $schema): ?string {
		try {
			$slug = $this->openRegister
				->installedService('OCA\OpenRegister\Db\SchemaMapper', 'OpenRegister schemas are not available.')
				->find((int)$schema)
				->getSlug();
		} catch (\Throwable $e) {
			return null;
		}

		return is_string($slug) === true && $slug !== '' ? $slug : null;
	}//end schemaSlug()

	/**
	 * The organisation of the first catalogue that lists the schema, null when none does or it cannot be read.
	 *
	 * @param string $schema The schema id.
	 *
	 * @return array{id: string, name: mixed, tooiIdentifier: mixed}|null The organisation.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
	 */
	private function catalogOrganisation(string $schema): ?array {
		$catalogRegister = trim($this->config->getValueString('opencatalogi', 'catalog_register', ''));
		$catalogSchema = trim($this->config->getValueString('opencatalogi', 'catalog_schema', ''));
		if ($catalogRegister === '' || $catalogSchema === '') {
			return null;
		}

		try {
			$result = $this->openRegister->objectService()->searchObjectsPaginated(
				query: ['@self' => ['register' => $catalogRegister, 'schema' => $catalogSchema], '_limit' => self::CATALOG_PAGE],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			return null;
		}

		foreach ((array)($result['results'] ?? []) as $row) {
			$catalog = $this->asArray(object: $row);
			$schemas = array_map('strval', (array)($catalog['schemas'] ?? []));
			$organisation = (string)($catalog['organization'] ?? '');
			if ($organisation !== '' && in_array($schema, $schemas, true) === true) {
				return $this->organisation(uuid: $organisation);
			}
		}

		return null;
	}//end catalogOrganisation()

	/**
	 * One OpenRegister organisation, null when it cannot be read.
	 *
	 * @param string $uuid The organisation uuid.
	 *
	 * @return array{id: string, name: mixed, tooiIdentifier: mixed}|null The organisation.
	 */
	private function organisation(string $uuid): ?array {
		try {
			$found = $this->openRegister
				->installedService('OCA\OpenRegister\Db\OrganisationMapper', 'OpenRegister organisations are not available.')
				->findByUuidFollowingMerge($uuid);
		} catch (\Throwable $e) {
			return null;
		}

		return ['id' => $uuid, 'name' => $found->getName(), 'tooiIdentifier' => $found->getTooi()];
	}//end organisation()
}//end class
