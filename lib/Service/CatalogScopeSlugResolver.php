<?php

/**
 * Turns the register and schema slugs of a seeded catalogue into ids.
 *
 * A catalogue scope holds numeric ids: `PublicationService::isObjectInCatalogScope()`
 * runs every entry through `intval`, so a slug there reads as 0 and matches
 * nothing. A catalogue seeded from a register fragment cannot know those ids,
 * because they only exist after the import. So a seed names its register and
 * schema by slug, and this resolves them once the import has run.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-007-harvested-components-are-found-in-search-and-in-the-public-api
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use Closure;
use Throwable;

/**
 * Resolves slug entries in a catalogue scope to ids.
 */
class CatalogScopeSlugResolver {

	/**
	 * Replace every slug in a scope list with the id it names.
	 *
	 * Numeric entries are kept as they are. A slug that resolves to nothing is
	 * kept too, so the scope keeps saying what the seed meant and the next import
	 * can try again (for example once the schema it names has been imported).
	 *
	 * @param array<int, mixed> $entries The scope list as stored.
	 * @param callable(string): (int|string|null) $resolve Looks one slug up; null when unknown.
	 *
	 * @return array{0: array<int, mixed>, 1: bool} The resolved list, and whether anything changed.
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-007-harvested-components-are-found-in-search-and-in-the-public-api
	 */
	public function resolve(array $entries, callable $resolve): array {
		$changed = false;
		$resolved = [];
		foreach ($entries as $entry) {
			$id = null;
			if ($this->isSlug(entry: $entry) === true) {
				$id = $this->resolvedId(resolve: $resolve, slug: $entry);
			}

			if ($id === null) {
				$resolved[] = $entry;
				continue;
			}

			$resolved[] = $id;
			$changed = true;
		}

		return [$resolved, $changed];
	}//end resolve()

	/**
	 * Whether a scope entry is a slug: a non-empty, non-numeric string.
	 *
	 * @param mixed $entry The scope entry.
	 *
	 * @return bool
	 */
	private function isSlug(mixed $entry): bool {
		return is_string($entry) === true && $entry !== '' && is_numeric($entry) === false;
	}//end isSlug()

	/**
	 * The id a slug names as text, or null when the lookup throws or names nothing.
	 *
	 * @param callable(string): (int|string|null) $resolve Looks one slug up.
	 * @param string $slug The slug.
	 *
	 * @return string|null
	 */
	private function resolvedId(callable $resolve, string $slug): ?string {
		try {
			$id = $resolve($slug);
		} catch (Throwable) {
			return null;
		}

		if ($id === null || (string)$id === '') {
			return null;
		}

		return (string)$id;
	}//end resolvedId()

	/**
	 * Resolve a whole catalogue scope: registers by slug, then schemas by slug INSIDE those registers.
	 *
	 * A bare schema slug is ambiguous. OpenRegister lets two apps own a schema
	 * with the same slug (`module`, `usage`, `organization`), and a global lookup
	 * picks one of them by id order. A catalogue unions its registers with its
	 * schemas, so a schema resolved from another app's register would put that
	 * app's objects in the catalogue. So a schema slug only resolves to a schema
	 * that one of the catalogue's own registers lists, and stays a slug otherwise.
	 *
	 * `pending` names what a later register event can complete: every register
	 * slug still unresolved, and (when a schema slug is still unresolved) the id
	 * of every register already resolved, whose schema list may still grow.
	 *
	 * @param array<int, mixed> $registers The scope's registers as stored.
	 * @param array<int, mixed> $schemas The scope's schemas as stored.
	 * @param CatalogScopeLookups $lookups The register and schema lookups.
	 *
	 * @return array{registers: array<int, mixed>, schemas: array<int, mixed>, changed: bool, pending: list<string>}
	 *
	 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-002-a-schema-slug-resolves-only-inside-the-catalogues-own-registers
	 */
	public function resolveScope(array $registers, array $schemas, CatalogScopeLookups $lookups): array {
		[$registers, $registersChanged] = $this->resolve(entries: $registers, resolve: $lookups->findRegisterId);

		$registerIds = array_values(array_map('intval', array_filter($registers, 'is_numeric')));

		$slugToId = null;
		$lookup = function (string $slug) use (&$slugToId, $registerIds, $lookups): ?int {
			if ($slugToId === null) {
				$slugToId = $this->schemaSlugMap(
					registerIds: $registerIds,
					schemaIdsOfRegister: $lookups->schemaIdsOfRegister,
					schemaSlugOf: $lookups->schemaSlugOf
				);
			}

			return ($slugToId[strtolower($slug)] ?? null);
		};

		[$schemas, $schemasChanged] = $this->resolve(entries: $schemas, resolve: $lookup);

		return [
			'registers' => $registers,
			'schemas' => $schemas,
			'changed' => ($registersChanged || $schemasChanged),
			'pending' => $this->pending(registers: $registers, schemas: $schemas, registerIds: $registerIds),
		];
	}//end resolveScope()

	/**
	 * What a later register event can still complete in a resolved scope.
	 *
	 * @param array<int, mixed> $registers The scope's registers after resolution.
	 * @param array<int, mixed> $schemas The scope's schemas after resolution.
	 * @param array<int, int> $registerIds The registers that did resolve.
	 *
	 * @return list<string> Lowercased register slugs, plus register ids while a schema slug remains.
	 *
	 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-004-installing-stackiq-after-opencatalogi-completes-the-scope
	 */
	private function pending(array $registers, array $schemas, array $registerIds): array {
		$pending = $this->lowercasedSlugs(entries: $registers);
		if ($this->hasSlug(entries: $schemas) === true) {
			foreach ($registerIds as $registerId) {
				$pending[] = (string)$registerId;
			}
		}

		return array_values(array_unique($pending));
	}//end pending()

	/**
	 * The slugs of a scope list, lowercased, in order.
	 *
	 * @param array<int, mixed> $entries The scope list.
	 *
	 * @return list<string>
	 */
	private function lowercasedSlugs(array $entries): array {
		$slugs = [];
		foreach ($entries as $entry) {
			if ($this->isSlug(entry: $entry) === true) {
				$slugs[] = strtolower($entry);
			}
		}

		return $slugs;
	}//end lowercasedSlugs()

	/**
	 * Map the slug of every schema the given registers list to its id.
	 *
	 * The first register that lists a slug wins, and a register or schema that
	 * cannot be read is skipped rather than failing the whole scope.
	 *
	 * @param array<int, int> $registerIds The resolved register ids.
	 * @param callable(int): array<int, mixed> $schemaIdsOfRegister The schema ids a register lists.
	 * @param callable(int): (string|null) $schemaSlugOf The slug of a schema id.
	 *
	 * @return array<string, int> Lowercased slug to schema id.
	 *
	 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-002-a-schema-slug-resolves-only-inside-the-catalogues-own-registers
	 */
	public function schemaSlugMap(array $registerIds, callable $schemaIdsOfRegister, callable $schemaSlugOf): array {
		$index = new SchemaSlugIndex(
			schemaIdsOfRegister: Closure::fromCallable($schemaIdsOfRegister),
			schemaSlugOf: Closure::fromCallable($schemaSlugOf)
		);
		foreach ($registerIds as $registerId) {
			$index->addRegister(registerId: $registerId);
		}

		return $index->map();
	}//end schemaSlugMap()

	/**
	 * Whether a scope list still holds a slug.
	 *
	 * @param array<int, mixed> $entries The scope list.
	 *
	 * @return bool True when at least one entry is a non-numeric string.
	 *
	 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-004-installing-stackiq-after-opencatalogi-completes-the-scope
	 */
	public function hasSlug(array $entries): bool {
		foreach ($entries as $entry) {
			if ($this->isSlug(entry: $entry) === true) {
				return true;
			}
		}

		return false;
	}//end hasSlug()
}//end class
