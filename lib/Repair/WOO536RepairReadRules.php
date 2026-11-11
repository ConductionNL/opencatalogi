<?php

/**
 * WOO-536 repair step for publication + document read-rule shape.
 *
 * @category Repair
 * @package  OCA\OpenCatalogi\Repair
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
 * @spec openspec/changes/fix-fts-catalog-model-alignment/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Repair;

use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step that backfills the two-rule `depublicationDate` read-shape
 * on the `publication` and `document` schemas for existing installations, and
 * (WOO-573) translates the Dutch 1.x match-keys `publicatiedatum` /
 * `depublicatiedatum` those legacy schemas still carry after
 * RenameDutchPublicationColumns renamed the object columns to English.
 *
 * WOO-536 (Robert Zondervan, 2026-08-12) requires the public search endpoint
 * to filter depublished objects. The seed JSON in
 * `lib/Settings/publication_register.json` was updated to the two-rule shape:
 *
 *   read: [
 *     { group: public, match: { publicationDate $lte $now, depublicationDate $gte $now } },
 *     { group: public, match: { publicationDate $lte $now, depublicationDate $exists false } },
 *     "authenticated"
 *   ]
 *
 * Fresh installs pick this up via the standard seed-import path
 * (InitializeSettings::run → SettingsService::loadSettings). Existing
 * installations may still carry the older single-rule shape from before
 * this change — this repair step upgrades them.
 *
 * Guards (in order of check):
 *   1. OR must be installed (skip otherwise).
 *   2. The schema's `authorization.read` must exist on disk.
 *   3. The read block must be on the single-rule shape (missing
 *      depublicationDate). Admin-customised shapes are left alone.
 *   4. Update is idempotent — re-running has no effect once schema is on
 *      the two-rule shape.
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Guard branches are the point of the class.
 *
 * @spec openspec/specs/search/spec.md
 */
class WOO536RepairReadRules implements IRepairStep {

	/**
	 * Bundled schemas whose read rules encode the publication lifecycle and
	 * therefore get the WOO-536 two-rule upgrade. Translation of Dutch keys
	 * (WOO-573) is NOT limited to this list.
	 *
	 * @var string[]
	 */
	private const TWO_RULE_SLUGS = ['publication', 'document'];

	/**
	 * The depublicationDate-aware two-rule read shape: published content that
	 * is not yet depublished, or has no depublication date, is readable by the
	 * public group in every organisation.
	 */
	private const TWO_RULE_READ = [
		[
			'group' => 'public',
			'match' => [
				'publicationDate' => ['$lte' => '$now'],
				'depublicationDate' => ['$gte' => '$now'],
			],
			'crossOrganisation' => true,
		],
		[
			'group' => 'public',
			'match' => [
				'publicationDate' => ['$lte' => '$now'],
				'depublicationDate' => ['$exists' => false],
			],
			'crossOrganisation' => true,
		],
	];

	/**
	 * The Dutch 1.x match keys, each with the English name used since #850.
	 */
	private const DUTCH_MATCH_KEYS = [
		'publicatiedatum' => 'publicationDate',
		'depublicatiedatum' => 'depublicationDate',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager The app manager.
	 * @param ContainerInterface $container The container.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Backfill publication + document read-rule two-rule shape (WOO-536)';
	}//end getName()

	/**
	 * Run the repair step.
	 *
	 * Walks EVERY OpenRegister schema (WOO-573): the Dutch 1.x match-keys can
	 * live on any schema an operator copied its rules from, not only on the two
	 * bundled ones, and after RenameDutchPublicationColumns they all become SQL
	 * against a column that no longer exists. Translation therefore runs
	 * everywhere; the WOO-536 two-rule upgrade stays limited to the bundled
	 * `publication` / `document` slugs whose lifecycle fields it encodes.
	 *
	 * @param IOutput $output The output interface.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/search/spec.md
	 */
	public function run(IOutput $output): void {
		// Guard 1: OR must be enabled (schema authorization lives in OR).
		// `isEnabledForAnyone()` replaces the deprecated `getInstalledApps()`
		// membership check in NC 30+.
		if ($this->appManager->isEnabledForAnyone('openregister') === false) {
			$output->warning('OpenRegister app is not enabled - skipping WOO-536 read-rule backfill');
			return;
		}

		try {
			$schemaMapper = $this->container->get('OCA\\OpenRegister\\Db\\SchemaMapper');
			$schemas = $schemaMapper->findAll();
		} catch (\Throwable $e) {
			$output->warning('OpenRegister SchemaMapper unavailable - skipping WOO-536 read-rule backfill: ' . $e->getMessage());
			return;
		}

		$updated = 0;
		$skipped = 0;
		foreach ($schemas as $schema) {
			$repair = new SchemaRepair(schemaMapper: $schemaMapper, output: $output, schema: $schema);
			if ($this->repairSchema(repair: $repair) === true) {
				$updated++;
				continue;
			}

			$skipped++;
		}

		$output->info(
			sprintf(
				'WOO-536 read-rule backfill: %d schema(s) updated, %d skipped (no Dutch keys, already correct or customised)',
				$updated,
				$skipped
			)
		);

	}//end run()

	/**
	 * Repair one schema: translate Dutch match-keys, then (bundled slugs only)
	 * upgrade the single-rule read shape, and persist when anything changed.
	 *
	 * @param SchemaRepair $repair The schema, its mapper and the output.
	 *
	 * @return bool True when the schema was updated.
	 *
	 * @spec openspec/specs/search/spec.md
	 */
	private function repairSchema(SchemaRepair $repair): bool {
		$slug = $repair->slug();
		$authorization = $repair->schema->getAuthorization();
		if (is_array($authorization) === false) {
			$this->reportMissingAuthorization(repair: $repair);
			return false;
		}

		$result = $this->computeAuthorizationChanges(authorization: $authorization, slug: $slug);
		$this->reportDroppedKeys(repair: $repair, dropped: $result['dropped']);

		if (empty($result['changes']) === true) {
			$this->reportUnchanged(repair: $repair);
			return false;
		}

		$repair->schema->setAuthorization($result['authorization']);

		return $this->persist(repair: $repair, changes: $result['changes']);
	}//end repairSchema()

	/**
	 * Warn that a bundled schema has no authorization block to repair.
	 *
	 * @param SchemaRepair $repair The schema, its mapper and the output.
	 *
	 * @return void
	 */
	private function reportMissingAuthorization(SchemaRepair $repair): void {
		if (in_array($repair->slug(), self::TWO_RULE_SLUGS, true) === true) {
			$repair->output->warning("WOO-536: {$repair->label()} has no authorization block — skipping (anonymous read rules missing)");
		}
	}//end reportMissingAuthorization()

	/**
	 * Warn about every Dutch duplicate the translation dropped.
	 *
	 * @param SchemaRepair $repair The schema, its mapper and the output.
	 * @param string[] $dropped The "action[index].key" of each dropped key.
	 *
	 * @return void
	 */
	private function reportDroppedKeys(SchemaRepair $repair, array $dropped): void {
		$label = $repair->label();
		foreach ($dropped as $where) {
			$repair->output->warning(
				"WOO-573: {$label} — Dutch key dropped at {$where} because the English key already exists; verify the two conditions agreed"
			);
		}
	}//end reportDroppedKeys()

	/**
	 * Tell the operator a bundled schema needed no repair.
	 *
	 * @param SchemaRepair $repair The schema, its mapper and the output.
	 *
	 * @return void
	 */
	private function reportUnchanged(SchemaRepair $repair): void {
		if (in_array($repair->slug(), self::TWO_RULE_SLUGS, true) === true) {
			$repair->output->info("WOO-536: {$repair->label()} already on two-rule shape or admin-customised — skipping");
		}
	}//end reportUnchanged()

	/**
	 * Save a repaired schema and report the change.
	 *
	 * @param SchemaRepair $repair The schema, its mapper and the output.
	 * @param string[] $changes The human-readable change list.
	 *
	 * @return bool True when the schema was saved.
	 *
	 * @spec openspec/specs/search/spec.md
	 */
	private function persist(SchemaRepair $repair, array $changes): bool {
		$label = $repair->label();
		try {
			$repair->schemaMapper->update($repair->schema);
		} catch (\Throwable $e) {
			$repair->output->warning("WOO-536: failed to update {$label}: {$e->getMessage()}");
			return false;
		}

		$repair->output->info("WOO-536: {$label} " . implode(' + ', $changes));
		$this->logger->info(
			'WOO-536 repair: schema authorization updated',
			['schema' => $repair->slug(), 'schemaId' => $repair->schema->getId(), 'changes' => $changes]
		);

		return true;
	}//end persist()

	/**
	 * Pure computation of the repaired authorization block.
	 *
	 * Step 1 (WOO-573, every schema): translate the Dutch 1.x property names in
	 * every action's match-clauses. Step 2 (WOO-536, bundled slugs only): upgrade
	 * the single-rule read shape to the depublicationDate-aware two-rule shape,
	 * preserving non-conditional elements such as "authenticated".
	 *
	 * @param array $authorization The current authorization block.
	 * @param string $slug The schema slug (decides whether step 2 applies).
	 *
	 * @return array{authorization: array, changes: string[], dropped: string[]} The new block, a human-readable change list
	 *                                                                           (empty = nothing to write) and the
	 *                                                                           "action[index].key" of Dutch keys dropped in
	 *                                                                           favour of an existing English key.
	 *
	 * @spec openspec/specs/search/spec.md
	 */
	private function computeAuthorizationChanges(array $authorization, string $slug): array {
		$dropped = [];
		$changes = [];
		$translated = $this->translateDutchKeys(authorization: $authorization, dropped: $dropped);
		if ($translated !== $authorization) {
			$changes[] = 'Dutch keys translated (WOO-573)';
		}

		$read = ($translated['read'] ?? null);
		if (in_array($slug, self::TWO_RULE_SLUGS, true) === true
			&& is_array($read) === true
			&& $this->isSingleRuleShape(read: $read) === true
		) {
			$translated['read'] = $this->buildTwoRuleRead(existing: $read);
			$changes[] = 'upgraded to two-rule shape';
		}

		return [
			'authorization' => $translated,
			'changes' => $changes,
			'dropped' => $dropped,
		];

	}//end computeAuthorizationChanges()

	/**
	 * Rename the Dutch 1.x property names inside every action's conditional
	 * rules to the English names used since #850 / RenameDutchPublicationColumns.
	 *
	 * Applies to `read`, `create`, `update` and `delete` alike (any action whose
	 * value is a rule list; non-list siblings such as `inheritFromPublic` are
	 * left untouched). Only `publicatiedatum` and `depublicatiedatum` are
	 * renamed; all other keys, operators and rules are returned untouched, so
	 * the caller can compare the result with the input to learn whether anything
	 * changed. When the English key already exists in the same match, the Dutch
	 * duplicate is dropped and reported through `$dropped` so the operator can
	 * verify the two conditions agreed. Pure function apart from that by-ref
	 * report.
	 *
	 * @param array $authorization The schema's authorization block.
	 * @param string[] $dropped By-ref: "action[index].key" of every dropped Dutch duplicate.
	 *
	 * @return array The authorization block with English match-keys.
	 *
	 * @spec openspec/specs/search/spec.md
	 */
	private function translateDutchKeys(array $authorization, array &$dropped): array {
		foreach ($authorization as $action => $rules) {
			if (is_array($rules) === false) {
				continue;
			}

			foreach ($rules as $index => $rule) {
				if ($this->hasMatchList(rule: $rule) === false) {
					continue;
				}

				$authorization[$action][$index]['match'] = $this->englishMatch(conditions: $rule['match']);
				array_push($dropped, ...$this->droppedDutchKeys(conditions: $rule['match'], location: "{$action}[{$index}]"));
			}
		}

		return $authorization;
	}//end translateDutchKeys()

	/**
	 * Whether an action's element is a conditional rule with a match list.
	 *
	 * @param mixed $rule One element of an action's rule list.
	 *
	 * @return bool True when the rule has a `match` array.
	 */
	private function hasMatchList(mixed $rule): bool {
		return is_array($rule) === true && isset($rule['match']) === true && is_array($rule['match']) === true;
	}//end hasMatchList()

	/**
	 * The match list with its Dutch keys renamed to English, in place; a Dutch
	 * key whose English name is already there is left out.
	 *
	 * @param array $conditions The rule's match list.
	 *
	 * @return array The match list with English keys.
	 */
	private function englishMatch(array $conditions): array {
		$english = [];
		foreach ($conditions as $key => $condition) {
			$newKey = $this->englishKeyFor(conditions: $conditions, key: $key);
			if ($newKey === null) {
				continue;
			}

			$english[$newKey] = $condition;
		}

		return $english;
	}//end englishMatch()

	/**
	 * Where each Dutch duplicate left out of a match list sat, as
	 * "action[index].key".
	 *
	 * @param array $conditions The rule's match list.
	 * @param string $location The rule's "action[index]".
	 *
	 * @return string[] One entry per dropped Dutch key, in match order.
	 */
	private function droppedDutchKeys(array $conditions, string $location): array {
		$dropped = [];
		foreach (array_keys($conditions) as $key) {
			if ($this->englishKeyFor(conditions: $conditions, key: $key) === null) {
				$dropped[] = "{$location}.{$key}";
			}
		}

		return $dropped;
	}//end droppedDutchKeys()

	/**
	 * The key a match condition goes under: the English name of a Dutch key,
	 * any other key as it is. An English key that already exists wins over the
	 * Dutch duplicate — never clobber a rule the admin already fixed.
	 *
	 * @param array $conditions The rule's match list.
	 * @param int|string $key One key of that list.
	 *
	 * @return int|string|null The key to use, or null when the condition is dropped.
	 */
	private function englishKeyFor(array $conditions, int|string $key): int|string|null {
		$newKey = (self::DUTCH_MATCH_KEYS[$key] ?? $key);
		if ($newKey !== $key && array_key_exists($newKey, $conditions) === true) {
			return null;
		}

		return $newKey;
	}//end englishKeyFor()

	/**
	 * Detect the old single-rule shape.
	 *
	 * Old shape (before this repair):
	 *
	 *   read: [
	 *     { group: public, match: { publicationDate: { $lte: $now } } },
	 *     "authenticated"     (optional third element)
	 *   ]
	 *
	 * Any deviation from this shape (extra rules, different match keys,
	 * additional operators on publicationDate) is considered admin-customised
	 * and left alone.
	 *
	 * @param array $read The current read-block array.
	 *
	 * @return bool True when the block matches the pre-fix single-rule shape.
	 */
	private function isSingleRuleShape(array $read): bool {
		// Expected shape: exactly one conditional public rule + optional simple
		// string elements (e.g., "authenticated"). More than one conditional
		// rule means admin has customised, and any two-rule shape (post-fix)
		// will have exactly two conditional public rules.
		$conditionalCount = 0;
		foreach ($read as $element) {
			if (is_string($element) === true) {
				// Simple element like "authenticated" or "public" — allowed.
				continue;
			}

			if (is_array($element) === false || $this->isOldPublicRule(rule: $element) === false) {
				return false;
			}

			$conditionalCount++;
		}

		// Exactly one conditional public rule with the old-shape match => needs upgrade.
		return $conditionalCount === 1;
	}//end isSingleRuleShape()

	/**
	 * Whether a conditional rule is the old public rule: group `public` and
	 * exactly one match key, `publicationDate: { $lte: $now }`.
	 *
	 * @param array $rule The conditional rule.
	 *
	 * @return bool
	 */
	private function isOldPublicRule(array $rule): bool {
		if (($rule['group'] ?? null) !== 'public') {
			return false;
		}

		$match = ($rule['match'] ?? []);
		if (is_array($match) === false || count($match) !== 1 || isset($match['publicationDate']) === false) {
			return false;
		}

		return $this->isLteNow(condition: $match['publicationDate']);
	}//end isOldPublicRule()

	/**
	 * Whether a condition is exactly `{ $lte: $now }`.
	 *
	 * @param mixed $condition The condition.
	 *
	 * @return bool
	 */
	private function isLteNow(mixed $condition): bool {
		return is_array($condition) === true
			&& count($condition) === 1
			&& ($condition['$lte'] ?? null) === '$now';

	}//end isLteNow()

	/**
	 * Build the two-rule shape while preserving any non-conditional
	 * elements (e.g., "authenticated") from the existing read block. Published
	 * content is read in every organisation, so both rules cross organisations.
	 *
	 * @param array $existing The existing read-block array (must match single-rule shape).
	 *
	 * @return array The two-rule read block.
	 */
	private function buildTwoRuleRead(array $existing): array {
		$twoRule = self::TWO_RULE_READ;

		// Preserve simple-string elements (like "authenticated") from the
		// existing read block, in their original order after the two new rules.
		foreach ($existing as $element) {
			if (is_string($element) === true) {
				$twoRule[] = $element;
			}
		}

		return $twoRule;
	}//end buildTwoRuleRead()
}//end class
