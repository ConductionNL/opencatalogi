<?php

/**
 * The slug-to-id map of the schemas a catalogue's registers list.
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
 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-002-a-schema-slug-resolves-only-inside-the-catalogues-own-registers
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use Closure;
use Throwable;

/**
 * Collects the lowercased slug of every schema the added registers list. The
 * first register that lists a slug wins, and a register or schema that cannot
 * be read is skipped. Built and read by CatalogScopeSlugResolver only.
 *
 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-002-a-schema-slug-resolves-only-inside-the-catalogues-own-registers
 */
final class SchemaSlugIndex {

	/**
	 * Lowercased slug to schema id.
	 *
	 * @var array<string, int>
	 */
	private array $map = [];

	/**
	 * Constructor.
	 *
	 * @param Closure(int): array<int, mixed> $schemaIdsOfRegister The schema ids a register lists.
	 * @param Closure(int): (string|null) $schemaSlugOf The slug of a schema id.
	 */
	public function __construct(
		private readonly Closure $schemaIdsOfRegister,
		private readonly Closure $schemaSlugOf,
	) {
	}//end __construct()

	/**
	 * Add the schemas one register lists; a register that cannot be read adds nothing.
	 *
	 * @param int $registerId The register id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-002-a-schema-slug-resolves-only-inside-the-catalogues-own-registers
	 */
	public function addRegister(int $registerId): void {
		try {
			$schemaIds = ($this->schemaIdsOfRegister)($registerId);
		} catch (Throwable) {
			return;
		}

		foreach ($schemaIds as $schemaId) {
			$this->addSchema(schemaId: $schemaId);
		}
	}//end addRegister()

	/**
	 * The lowercased slug to schema id map.
	 *
	 * @return array<string, int>
	 *
	 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-002-a-schema-slug-resolves-only-inside-the-catalogues-own-registers
	 */
	public function map(): array {
		return $this->map;
	}//end map()

	/**
	 * Add one listed schema under its slug, unless an earlier one has that slug.
	 *
	 * @param mixed $schemaId The listed schema id.
	 *
	 * @return void
	 */
	private function addSchema(mixed $schemaId): void {
		if (is_numeric($schemaId) === false) {
			return;
		}

		$slug = $this->slugOf(schemaId: (int)$schemaId);
		if (is_string($slug) === true && $slug !== '' && isset($this->map[strtolower($slug)]) === false) {
			$this->map[strtolower($slug)] = (int)$schemaId;
		}
	}//end addSchema()

	/**
	 * A schema's slug, or null when it cannot be read.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return mixed
	 */
	private function slugOf(int $schemaId): mixed {
		try {
			return ($this->schemaSlugOf)($schemaId);
		} catch (Throwable) {
			return null;
		}
	}//end slugOf()
}//end class
