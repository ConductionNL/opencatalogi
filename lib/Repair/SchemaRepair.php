<?php

/**
 * One schema being repaired by a repair step.
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
 * @spec openspec/specs/search/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Repair;

use OCP\Migration\IOutput;

/**
 * A schema, the mapper that saves it and the output progress is reported on.
 *
 * The schema and mapper are typed as object so a repair step compiles without
 * an OpenRegister dependency at analysis time.
 *
 * @spec openspec/specs/search/spec.md
 */
final class SchemaRepair {

	/**
	 * Constructor.
	 *
	 * @param object $schemaMapper The OpenRegister SchemaMapper.
	 * @param IOutput $output The output interface for progress reporting.
	 * @param object $schema The OpenRegister Schema entity.
	 */
	public function __construct(
		public readonly object $schemaMapper,
		public readonly IOutput $output,
		public readonly object $schema,
	) {

	}//end __construct()

	/**
	 * The schema's slug.
	 *
	 * @return string The slug.
	 *
	 * @spec openspec/specs/search/spec.md
	 */
	public function slug(): string {
		return (string)$this->schema->getSlug();
	}//end slug()

	/**
	 * How progress lines name the schema: its slug and id.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/specs/search/spec.md
	 */
	public function label(): string {
		return "schema '{$this->slug()}' (id " . $this->schema->getId() . ')';
	}//end label()
}//end class
