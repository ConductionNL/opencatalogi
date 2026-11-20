<?php

/**
 * OpenCatalogi single lawful value
 *
 * Fills a publication field the officer has exactly one lawful choice for.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
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

namespace OCA\OpenCatalogi\Service\MetadataSuggestion;

use DateTimeZone;
use OCA\OpenCatalogi\Contract\SingleLawfulValueInterface;
use OCA\OpenCatalogi\Contract\InformationCategoryRegistryInterface;
use OCA\OpenCatalogi\Contract\OpenRegisterServicesInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A field with one lawful value for this officer fills itself (REQ-WMS-004, decision D5).
 *
 * For a new publication it computes, per empty field, the values the officer
 * may lawfully choose: the organisations the officer is a member of for
 * `organization`, and the categories that declare the publication's schema
 * for `wooCategory`. A set of exactly one fills the field and is recorded in
 * `filledByRule`. An empty set, a set of two or more, or a set that cannot be
 * computed fills nothing. Suggestions are never read here, so a Hermiq
 * suggestion is only ever written when a person accepts it (REQ-WMS-003).
 *
 * The publication carries no catalogue property (a catalogue lists schemas),
 * so there is no catalogue field to fill.
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-a-field-with-one-lawful-value-for-this-officer-fills-itself-req-wms-004
 */
class SingleLawfulValue implements SingleLawfulValueInterface {

	/**
	 * The rule that filled an organisation: the officer is a member of one organisation only.
	 *
	 * @var string
	 */
	public const RULE_ONE_ORGANISATION = 'officer-member-of-one-organisation';

	/**
	 * The rule that filled a category: one category declares the publication's schema.
	 *
	 * @var string
	 */
	public const RULE_ONE_CATEGORY = 'schema-declared-by-one-category';

	/**
	 * OpenRegister's organisation store, read by user id without side effects.
	 *
	 * @var string
	 */
	public const ORGANISATIONS = 'OCA\\OpenRegister\\Db\\OrganisationMapperInterface';

	/**
	 * OpenRegister's schema store.
	 *
	 * @var string
	 */
	public const SCHEMAS = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterServicesInterface $openRegister OpenRegister's organisations and schemas.
	 * @param InformationCategoryRegistryInterface $categories The categories and the schemas each one declares.
	 * @param IAppConfig $config Names the publication register and schema.
	 * @param ITimeFactory $time The clock a fill is dated by.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly OpenRegisterServicesInterface $openRegister,
		private readonly InformationCategoryRegistryInterface $categories,
		private readonly IAppConfig $config,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The publication with every empty field that has one lawful value for this officer filled.
	 *
	 * @param array<string, mixed> $publication The publication as it would be written, with
	 *                                          `@self.register` and `@self.schema`.
	 * @param IUser $officer The officer creating it.
	 *
	 * @return array<string, mixed> The publication, with the fills and their `filledByRule` records.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-a-field-with-one-lawful-value-for-this-officer-fills-itself-req-wms-004
	 */
	public function fill(array $publication, IUser $officer): array {
		$slug = $this->publicationSchemaSlug(publication: $publication);
		if ($slug === null) {
			return $publication;
		}

		$sets = [];
		if ($this->isEmpty(value: ($publication['organization'] ?? null)) === true) {
			$sets['organization'] = [$this->organisationsOf(userId: $officer->getUID()), self::RULE_ONE_ORGANISATION];
		}

		if ($this->isEmpty(value: ($publication['wooCategory'] ?? null)) === true) {
			$sets['wooCategory'] = [$this->categoriesDeclaring(slug: $slug), self::RULE_ONE_CATEGORY];
		}

		return $this->withFills(publication: $publication, sets: $sets);
	}//end fill()

	/**
	 * The publication with every field that has exactly one lawful value filled and recorded.
	 *
	 * @param array<string, mixed> $publication The publication.
	 * @param array<string, array{0: array<int, string>|null, 1: string}> $sets The lawful values and rule by field.
	 *
	 * @return array<string, mixed> The publication, with the fills and their `filledByRule` records.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-a-field-with-one-lawful-value-for-this-officer-fills-itself-req-wms-004
	 */
	private function withFills(array $publication, array $sets): array {
		$filledAt = $this->time->now()->setTimezone(new DateTimeZone('UTC'))->format('c');
		foreach ($sets as $field => [$values, $rule]) {
			if ($values === null || count($values) !== 1) {
				continue;
			}

			$publication[$field] = $values[0];
			$publication['filledByRule'] = array_merge(
				(array)($publication['filledByRule'] ?? []),
				[['field' => $field, 'value' => $values[0], 'rule' => $rule, 'at' => $filledAt]]
			);
		}

		return $publication;
	}//end withFills()

	/**
	 * The slug of the publication's schema, or null when this is not a publication.
	 *
	 * A publication is an object in the publication register whose schema is the
	 * configured publication schema or one a Woo category declares. Anything else
	 * in that register (suggestions, depublications, catalogues) is left alone.
	 *
	 * @param array<string, mixed> $publication The object as it would be written.
	 *
	 * @return string|null The slug, or null.
	 */
	private function publicationSchemaSlug(array $publication): ?string {
		$schema = $this->publicationRegisterSchema(publication: $publication);
		$slug   = null;
		if ($schema !== null) {
			$slug = $this->schemaSlug(schema: $schema);
		}

		if ($slug === null) {
			return null;
		}

		if ($schema === $this->setting(key: 'publication_schema')) {
			return $slug;
		}

		// Categories that cannot be read declare nothing: not a publication.
		$declaring = $this->categoriesDeclaring(slug: $slug);
		if ($declaring !== null && $declaring !== []) {
			return $slug;
		}

		return null;
	}//end publicationSchemaSlug()

	/**
	 * The object's schema id when it lives in the publication register, else null.
	 *
	 * @param array<string, mixed> $publication The object as it would be written.
	 *
	 * @return string|null The schema id, or null.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-a-field-with-one-lawful-value-for-this-officer-fills-itself-req-wms-004
	 */
	private function publicationRegisterSchema(array $publication): ?string {
		$register = (string)($publication['@self']['register'] ?? '');
		$schema = (string)($publication['@self']['schema'] ?? '');
		if ($register === '' || $schema === '' || $register !== $this->setting(key: 'publication_register')) {
			return null;
		}

		return $schema;
	}//end publicationRegisterSchema()

	/**
	 * The uuids of the organisations the user is a member of, or null when they cannot be read.
	 *
	 * @param string $userId The user id.
	 *
	 * @return array<int, string>|null The uuids, or null.
	 */
	private function organisationsOf(string $userId): ?array {
		try {
			$organisations = $this->openRegister
				->installedService(self::ORGANISATIONS, 'OpenRegister organisations are not available.')
				->findByUserId($userId);
		} catch (Throwable $e) {
			$this->logger->warning('[SingleLawfulValue] The officer\'s organisations could not be read: ' . $e->getMessage());
			return null;
		}

		$uuids = [];
		foreach ((array)$organisations as $organisation) {
			$uuid = (string)$organisation->getUuid();
			if ($uuid !== '') {
				$uuids[$uuid] = $uuid;
			}
		}

		return array_values($uuids);
	}//end organisationsOf()

	/**
	 * The codes of the categories that declare the schema.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return array<int, string>|null The codes, or null when the categories cannot be read.
	 */
	private function categoriesDeclaring(string $slug): ?array {
		try {
			$codes = [];
			foreach (array_keys($this->categories->all()) as $code) {
				if (in_array($slug, $this->categories->schemasFor(code: (string)$code), true) === true) {
					$codes[] = (string)$code;
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning('[SingleLawfulValue] The categories could not be read: ' . $e->getMessage());
			return null;
		}

		return $codes;
	}//end categoriesDeclaring()

	/**
	 * The slug of a schema, or null when it cannot be read.
	 *
	 * @param string $schema The schema id.
	 *
	 * @return string|null The slug.
	 */
	private function schemaSlug(string $schema): ?string {
		try {
			$slug = $this->openRegister
				->installedService(self::SCHEMAS, 'OpenRegister schemas are not available.')
				->find((int)$schema)
				->getSlug();
		} catch (Throwable $e) {
			return null;
		}

		if (is_string($slug) === false || $slug === '') {
			return null;
		}

		return $slug;
	}//end schemaSlug()

	/**
	 * An app setting, trimmed.
	 *
	 * @param string $key The setting.
	 *
	 * @return string The value, empty when unset.
	 */
	private function setting(string $key): string {
		return trim($this->config->getValueString('opencatalogi', $key, ''));
	}//end setting()

	/**
	 * Whether a field holds no value.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for null, an empty string or an empty list.
	 */
	private function isEmpty(mixed $value): bool {
		if (is_string($value) === true) {
			return trim($value) === '';
		}

		return $value === null || $value === [];
	}//end isEmpty()
}//end class
