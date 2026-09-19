<?php

/**
 * OpenCatalogi Service Catalogue Service.
 *
 * The public request catalogue: every service a resident or a company can ask
 * for, with what it costs, how long it takes, and the form binding that starts
 * it. The binding is resolved against the published case type definitions, and
 * an entry whose binding cannot be resolved is listed as unavailable rather
 * than dropped, because a hidden obligation is worse than a broken one.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Service\Catalogue\CatalogueUnreadableException;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads the public request catalogue and decides what each entry may claim.
 *
 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md
 */
class ServiceCatalogueService {

	/**
	 * The reason an entry carries no binding at all.
	 *
	 * @var string
	 */
	public const REASON_NO_BINDING = 'no-form-binding';

	/**
	 * The reason an entry's binding misses one of its three parts.
	 *
	 * @var string
	 */
	public const REASON_INCOMPLETE_BINDING = 'incomplete-form-binding';

	/**
	 * The reason an entry names a case type nothing answers to.
	 *
	 * @var string
	 */
	public const REASON_UNKNOWN_CASE_TYPE = 'unknown-case-type';

	/**
	 * The reason an entry names a case type that exists but is not published.
	 *
	 * @var string
	 */
	public const REASON_UNPUBLISHED_CASE_TYPE = 'unpublished-case-type';

	/**
	 * The group an entry is listed under when it names none.
	 *
	 * @var string
	 */
	public const UNGROUPED = 'ungrouped';

	/**
	 * Cached OpenRegister ObjectService instance.
	 *
	 * @var object|null
	 */
	private ?object $objectService = null;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Server container for resolving OpenRegister.
	 * @param LoggerInterface $logger Logger.
	 * @param IAppManager $appManager The app manager, to establish that OpenRegister is there before asking for it.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly IAppManager $appManager,
	) {

	}//end __construct()

	/**
	 * Resolve the OpenRegister ObjectService.
	 *
	 * There is no null return and no empty-list fallback. A catalogue we cannot
	 * read is unreadable, and saying "no services" to a resident who is owed a
	 * published catalogue is the failure this method refuses to commit.
	 *
	 * @return object The ObjectService.
	 *
	 * @throws CatalogueUnreadableException When OpenRegister cannot be reached.
	 *
	 * @spec exclude pure framework plumbing: resolves the consumed OR ObjectService.
	 */
	public function getObjectService(): object {
		if ($this->objectService !== null) {
			return $this->objectService;
		}

		// ADR-083 rule 1: the dependency is optional at runtime, so its presence
		// is established before it is asked for. Without this the container
		// lookup is the only place the dependency is declared, which is nowhere
		// a reader or a gate can see it.
		// The app id is written out rather than held in a constant: ADR-083's
		// checker reads the literal, and an indirection here is invisible to it.
		if ($this->appManager->isInstalled('openregister') === false) {
			throw new CatalogueUnreadableException(
				message: 'The service catalogue cannot be read because OpenRegister is not installed.'
			);
		}

		try {
			$this->objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->error('[ServiceCatalogueService] OpenRegister is unavailable: ' . $e->getMessage());
			throw new CatalogueUnreadableException(
				message: 'The service catalogue cannot be read because OpenRegister is unavailable.',
				code: 0,
				previous: $e
			);
		}

		return $this->objectService;

	}//end getObjectService()

	/**
	 * Inject an ObjectService, for the caller that already holds one.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 *
	 * @return void
	 *
	 * @spec exclude test and caller seam for the consumed OR ObjectService.
	 */
	public function setObjectService(object $objectService): void {
		$this->objectService = $objectService;

	}//end setObjectService()

	/**
	 * Index the published case type definitions by their identifier.
	 *
	 * @param array<int, array<string, mixed>> $caseTypes The case type definitions as arrays.
	 *
	 * @return array<string, array<string, mixed>> The definitions keyed by identifier.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-a-public-catalogue-lists-everything-that-can-be-requested-req-psc-101
	 */
	public function indexCaseTypes(array $caseTypes): array {
		$indexed = [];
		foreach ($caseTypes as $caseType) {
			$identifier = (string)($caseType['identifier'] ?? '');
			if ($identifier === '') {
				continue;
			}

			$indexed[$identifier] = $caseType;
		}

		return $indexed;

	}//end indexCaseTypes()

	/**
	 * Decide whether one entry's form binding resolves, and say why when it does not.
	 *
	 * The entry is returned either way, with `available` and, when it is false,
	 * `unavailableReason`. Nothing is removed from the list here: REQ-PSC-101
	 * says an entry with no resolvable form is listed as unavailable.
	 *
	 * @param array<string, mixed> $entry One catalogue entry.
	 * @param array<string, array<string, mixed>> $caseTypesById Published case types keyed by identifier.
	 *
	 * @return array<string, mixed> The entry with its availability decided.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-a-public-catalogue-lists-everything-that-can-be-requested-req-psc-101
	 */
	public function decideAvailability(array $entry, array $caseTypesById): array {
		$binding = ($entry['formBinding'] ?? null);

		if (is_array($binding) === false || $binding === []) {
			return $this->markUnavailable(entry: $entry, reason: self::REASON_NO_BINDING);
		}

		$caseType = trim((string)($binding['caseType'] ?? ''));
		$audience = trim((string)($binding['audience'] ?? ''));
		$formName = trim((string)($binding['formName'] ?? ''));

		if ($caseType === '' || $audience === '' || $formName === '') {
			return $this->markUnavailable(entry: $entry, reason: self::REASON_INCOMPLETE_BINDING);
		}

		if (array_key_exists($caseType, $caseTypesById) === false) {
			return $this->markUnavailable(entry: $entry, reason: self::REASON_UNKNOWN_CASE_TYPE);
		}

		$definition = $caseTypesById[$caseType];
		if (($definition['published'] ?? null) === false) {
			return $this->markUnavailable(entry: $entry, reason: self::REASON_UNPUBLISHED_CASE_TYPE);
		}

		$entry['available'] = true;
		unset($entry['unavailableReason']);

		return $entry;

	}//end decideAvailability()

	/**
	 * Mark an entry unavailable with its reason.
	 *
	 * @param array<string, mixed> $entry The entry.
	 * @param string $reason The reason constant.
	 *
	 * @return array<string, mixed> The entry, listed and unavailable.
	 */
	private function markUnavailable(array $entry, string $reason): array {
		$entry['available'] = false;
		$entry['unavailableReason'] = $reason;

		return $entry;

	}//end markUnavailable()

	/**
	 * Decide availability for every entry and group them.
	 *
	 * @param array<int, array<string, mixed>> $entries The catalogue entries.
	 * @param array<int, array<string, mixed>> $caseTypes The published case type definitions.
	 *
	 * @return array{entries: array<int, array<string, mixed>>, groups: array<string, array<int, array<string, mixed>>>, unavailable: integer}
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-a-public-catalogue-lists-everything-that-can-be-requested-req-psc-101
	 */
	public function assemble(array $entries, array $caseTypes): array {
		$indexed = $this->indexCaseTypes(caseTypes: $caseTypes);

		$decided = [];
		$groups = [];
		$unavailable = 0;

		foreach ($entries as $entry) {
			$decidedEntry = $this->decideAvailability(entry: $entry, caseTypesById: $indexed);
			if ($decidedEntry['available'] === false) {
				$unavailable++;
			}

			$group = trim((string)($decidedEntry['group'] ?? ''));
			if ($group === '') {
				$group = self::UNGROUPED;
			}

			$groups[$group][] = $decidedEntry;
			$decided[] = $decidedEntry;
		}

		ksort($groups);

		return [
			'entries' => $decided,
			'groups' => $groups,
			'unavailable' => $unavailable,
		];

	}//end assemble()

	/**
	 * The entries an administrator has to repair, with the reason each one carries.
	 *
	 * @param array<int, array<string, mixed>> $entries The catalogue entries.
	 * @param array<int, array<string, mixed>> $caseTypes The published case type definitions.
	 *
	 * @return array<int, array<string, mixed>> The unavailable entries.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-a-public-catalogue-lists-everything-that-can-be-requested-req-psc-101
	 */
	public function unavailableEntries(array $entries, array $caseTypes): array {
		$assembled = $this->assemble(entries: $entries, caseTypes: $caseTypes);

		return array_values(
			array_filter(
				$assembled['entries'],
				static fn (array $entry): bool => $entry['available'] === false
			)
		);

	}//end unavailableEntries()

	/**
	 * Read the catalogue entries and the published case types from OpenRegister.
	 *
	 * @param array<string, string> $entryConfig The register and schema of the entries.
	 * @param array<string, string> $caseTypeConfig The register and schema of the case type definitions.
	 * @param array<string, mixed> $filters Extra search filters from the caller.
	 *
	 * @return array{
	 *     entries: array<int, array<string, mixed>>,
	 *     groups: array<string, array<int, array<string, mixed>>>,
	 *     unavailable: integer,
	 *     total: integer
	 * }
	 *
	 * @throws CatalogueUnreadableException When OpenRegister cannot be reached.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-a-public-catalogue-lists-everything-that-can-be-requested-req-psc-101
	 */
	public function readCatalogue(array $entryConfig, array $caseTypeConfig, array $filters = []): array {
		$objectService = $this->getObjectService();

		$entryQuery = $filters;
		$entryQuery['@self'] = [
			'register' => $entryConfig['register'],
			'schema' => $entryConfig['schema'],
		];

		$entryResult = $objectService->searchObjectsPaginated($entryQuery, _rbac: true, _multitenancy: false);
		$entries = $this->toArrays(objects: ($entryResult['results'] ?? []));

		$caseTypeResult = $objectService->searchObjectsPaginated(
			[
				'@self' => [
					'register' => $caseTypeConfig['register'],
					'schema' => $caseTypeConfig['schema'],
				],
				'_limit' => 1000,
			],
			_rbac: true,
			_multitenancy: false
		);
		$caseTypes = $this->toArrays(objects: ($caseTypeResult['results'] ?? []));

		$assembled = $this->assemble(entries: $entries, caseTypes: $caseTypes);
		$assembled['total'] = (int)($entryResult['total'] ?? count($entries));

		return $assembled;

	}//end readCatalogue()

	/**
	 * Normalise OpenRegister results to plain arrays.
	 *
	 * An ObjectEntity serialises to `{id, ..., object: {...}}`; the properties
	 * this service reasons about live in `object`.
	 *
	 * @param array<int, mixed> $objects The raw results.
	 *
	 * @return array<int, array<string, mixed>> The property arrays, each keeping its id.
	 */
	private function toArrays(array $objects): array {
		$arrays = [];
		foreach ($objects as $object) {
			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$object = $object->jsonSerialize();
			}

			if (is_array($object) === false) {
				continue;
			}

			if (isset($object['object']) === true && is_array($object['object']) === true) {
				$properties = $object['object'];
				$properties['id'] = ($object['id'] ?? ($properties['id'] ?? null));
				$arrays[] = $properties;
				continue;
			}

			$arrays[] = $object;
		}

		return $arrays;

	}//end toArrays()
}//end class
