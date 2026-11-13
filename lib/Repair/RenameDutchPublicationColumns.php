<?php

/**
 * OpenCatalogi RenameDutchPublicationColumns Repair Step
 *
 * Moves the stored data of the publication schemas from their Dutch column
 * names to the English ones the register now declares.
 *
 * WHY THIS IS NEEDED AT ALL. OpenRegister does not store an object as a JSON
 * blob keyed by property name — each schema property is a real, snake_cased
 * COLUMN in the per-schema shard table `oc_openregister_table_{register}_{schema}`.
 * On schema sync, MagicMapper ADDS a column when the snake_cased property name
 * is absent, and it NEVER renames: there is not a single `RENAME COLUMN` in
 * openregister. Its only DROP path removes a camelCase duplicate whose
 * snake_case twin already exists.
 *
 * So renaming `publicatiedatum` to `publicationDate` in the register, on its
 * own, produces:
 *   1. the schema declares `publicationDate`;
 *   2. MagicMapper adds an empty `publication_date` column;
 *   3. the data stays in `publicatiedatum`, never dropped and never read;
 *   4. every read of the publication date returns null.
 *
 * For this app that is not cosmetic: anonymous visibility on the ORI harvest
 * feed is governed by `publicatiedatum <= now`, so a silently-null publication
 * date changes what the public can see. It is also invisible to every gate and
 * test, because the suites assert against fixtures rather than migrated rows.
 *
 * SAFETY. Non-destructive and idempotent:
 *   - a column is renamed only when the OLD one exists and the NEW one does not;
 *   - where MagicMapper has already added an empty NEW column, the data is
 *     copied across and the old column is LEFT IN PLACE rather than dropped,
 *     so the step is reversible and a re-run is a no-op;
 *   - nothing is deleted.
 *
 * SCOPE. Every `openregister_table_*` shard on the install is considered, not
 * only OpenCatalogi's own `Publication`/`Document`/`WooBatch` schemas: a
 * tenant's domain schema (a Woo category, say) carries the exact same Dutch
 * columns under its own title, and the visibility predicate this fixes
 * (`publicatiedatum <= now`) runs against it too once it is in a catalog's
 * scope. `run()`'s own per-table column check is what actually decides
 * whether a table needs touching, so nothing here pre-filters by title or
 * schema id — a shard table is migrated when it HAS one of the old columns,
 * full stop.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenCatalogi\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Rename the Dutch publication columns to their English equivalents.
 *
 * @spec openspec/specs/publication-retention-lifecycle/spec.md
 */
class RenameDutchPublicationColumns implements IRepairStep {
	/**
	 * Old snake_case column name => new snake_case column name.
	 *
	 * Snake_case, not camelCase: MagicMapper stores `publicationDate` as
	 * `publication_date`, and a camelCase column is exactly what its
	 * de-duplication path then drops.
	 *
	 * @var array<string, string>
	 */
	private const COLUMN_MAP = [
		'publicatiedatum' => 'publication_date',
		'depublicatiedatum' => 'depublication_date',
		'besluit' => 'decision_letter',
		// Fleet vocabulary batch.
		'inventarislijst' => 'inventory_list',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md
	 */
	public function getName(): string {
		return 'Move publication data from the Dutch columns to the English ones';
	}//end getName()

	/**
	 * Run the column migration across every shard table of every affected schema.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md
	 */
	public function run(IOutput $output): void {
		$tables = $this->shardTables();
		if ($tables === []) {
			$output->info('RenameDutchPublicationColumns: no publication shard tables on this install; nothing to do.');
			return;
		}

		$renamed = 0;
		$copied = 0;

		foreach ($tables as $table) {
			$columns = $this->columnsOf(table: $table);

			foreach (self::COLUMN_MAP as $old => $new) {
				if (in_array($old, $columns, true) === false) {
					// Already migrated, or this schema never had the property.
					continue;
				}

				$qTable = $this->quote(identifier: $table);
				$qOld = $this->quote(identifier: $old);
				$qNew = $this->quote(identifier: $new);

				if (in_array($new, $columns, true) === false) {
					$sql = 'ALTER TABLE ' . $qTable . ' RENAME COLUMN ' . $qOld . ' TO ' . $qNew;
					if ($this->exec(sql: $sql) === true) {
						$renamed++;
					}

					continue;
				}

				// The mapper already added an empty English column: back-fill and
				// leave the Dutch one, so this stays reversible.
				$sql = 'UPDATE ' . $qTable . ' SET ' . $qNew . ' = ' . $qOld
					. ' WHERE ' . $qNew . ' IS NULL AND ' . $qOld . ' IS NOT NULL';
				if ($this->exec(sql: $sql) === true) {
					$copied++;
				}
			}//end foreach
		}//end foreach

		$output->info(
			'RenameDutchPublicationColumns: ' . $renamed . ' column(s) renamed, '
			. $copied . ' column(s) back-filled, across ' . count($tables) . ' shard table(s).'
		);

	}//end run()

	/**
	 * Every openregister shard table on the install.
	 *
	 * NOT filtered by schema title or id: this used to resolve `Publication`/
	 * `Document`/`WooBatch` schema ids first and only consider their shards,
	 * but a tenant's own domain schemas (a Woo category, say) carry the exact
	 * same Dutch columns under a different title and were silently left out —
	 * `run()`'s own per-table, per-column `columnsOf()` check already decides
	 * correctly which tables actually need a column moved, so pre-filtering by
	 * title here only ever narrowed that set wrongly, never usefully.
	 *
	 * @return array<int, string>
	 */
	private function shardTables(): array {
		// Table discovery goes through information_schema, NOT IDBConnection.
		// OCP\IDBConnection exposes neither getSchema() nor getPrefix(); both
		// exist only on the concrete OC\DB\Connection. Calling them is a runtime
		// fatal that `php -l` and phpcs both report as clean — only phpstan
		// catches it. Pattern follows openregister's own RegisterService: anchor
		// on the `openregister_table_` MARKER, never on a computed prefix.
		try {
			$stmt = $this->db->prepare(
				'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern'
			);
			$stmt->bindValue('pattern', '%openregister\_table\_%');
			$stmt->execute();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchPublicationColumns: could not list tables; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$tables = [];
		while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
			$name = (string)($row['table_name'] ?? '');
			if ($name !== '' && strpos($name, 'openregister_table_') !== false) {
				$tables[] = $name;
			}
		}

		return array_values(array_unique($tables));
	}//end shardTables()

	/**
	 * List the column names of a table.
	 *
	 * @param string $table Table name.
	 *
	 * @return array<int, string>
	 */
	private function columnsOf(string $table): array {
		// Queried from information_schema — IDBConnection has no getSchema().
		try {
			$stmt = $this->db->prepare(
				'SELECT column_name FROM information_schema.columns WHERE table_name = :table'
			);
			$stmt->bindValue('table', $table);
			$stmt->execute();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchPublicationColumns: could not read columns; skipping table.',
				['table' => $table, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$columns = [];
		while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
			$name = (string)($row['column_name'] ?? '');
			if ($name !== '') {
				$columns[] = $name;
			}
		}

		return $columns;
	}//end columnsOf()

	/**
	 * Execute one DDL/DML statement, logging and swallowing failure.
	 *
	 * A failure must not abort the repair run: the remaining tables are
	 * independent, and an un-migrated column is still readable.
	 *
	 * @param string $sql The statement.
	 *
	 * @return bool Whether it succeeded.
	 */
	private function exec(string $sql): bool {
		try {
			$this->db->executeStatement($sql);
			return true;
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchPublicationColumns: statement failed; leaving the column as it was.',
				['sql' => $sql, 'exception' => $e->getMessage()]
			);
			return false;
		}

	}//end exec()

	/**
	 * Quote an identifier for the active platform.
	 *
	 * @param string $identifier Table or column name.
	 *
	 * @return string
	 */
	private function quote(string $identifier): string {
		return $this->db->getDatabasePlatform()->quoteSingleIdentifier($identifier);
	}//end quote()
}//end class
