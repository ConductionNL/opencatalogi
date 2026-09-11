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

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\App\IAppManager;
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
class WOO536RepairReadRules implements IRepairStep
{

    /**
     * Bundled schemas whose read rules encode the publication lifecycle and
     * therefore get the WOO-536 two-rule upgrade. Translation of Dutch keys
     * (WOO-573) is NOT limited to this list.
     *
     * @var string[]
     */
    private const TWO_RULE_SLUGS = ['publication', 'document'];

    /**
     * Constructor.
     *
     * @param IAppManager        $appManager The app manager.
     * @param ContainerInterface $container  The container.
     * @param LoggerInterface    $logger     The logger.
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     */
    public function getName(): string
    {
        return "Backfill publication + document read-rule two-rule shape (WOO-536)";

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
    public function run(IOutput $output): void
    {
        // Guard 1: OR must be enabled (schema authorization lives in OR).
        // `isEnabledForAnyone()` replaces the deprecated `getInstalledApps()`
        // membership check in NC 30+.
        if ($this->appManager->isEnabledForAnyone('openregister') === false) {
            $output->warning('OpenRegister app is not enabled - skipping WOO-536 read-rule backfill');
            return;
        }

        try {
            $schemaMapper = $this->container->get('OCA\\OpenRegister\\Db\\SchemaMapper');
            $schemas      = $schemaMapper->findAll();
        } catch (\Throwable $e) {
            $output->warning('OpenRegister SchemaMapper unavailable - skipping WOO-536 read-rule backfill: '.$e->getMessage());
            return;
        }

        $updated = 0;
        $skipped = 0;
        foreach ($schemas as $schema) {
            if ($this->repairSchema(schemaMapper: $schemaMapper, schema: $schema, output: $output) === true) {
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
     * @param object  $schemaMapper The OpenRegister SchemaMapper (typed as object
     *                              so this repair step compiles without an OR
     *                              dependency at analysis-time).
     * @param object  $schema       The OpenRegister Schema entity.
     * @param IOutput $output       The output interface for progress reporting.
     *
     * @return bool True when the schema was updated.
     *
     * @spec openspec/specs/search/spec.md
     */
    private function repairSchema(object $schemaMapper, object $schema, IOutput $output): bool
    {
        $slug          = (string) $schema->getSlug();
        $label         = "schema '{$slug}' (id ".$schema->getId().")";
        $authorization = $schema->getAuthorization();
        if (is_array($authorization) === false) {
            return false;
        }

        $dropped = [];
        $result  = $this->computeAuthorizationChanges(
            authorization: $authorization,
            slug: $slug,
            dropped: $dropped
        );
        foreach ($dropped as $where) {
            $output->warning(
                "WOO-573: {$label} — Dutch key dropped at {$where} because the English key already exists; verify the two conditions agreed"
            );
        }

        if (empty($result['changes']) === true) {
            if (in_array($slug, self::TWO_RULE_SLUGS, true) === true) {
                $output->info("WOO-536: {$label} already on two-rule shape or admin-customised — skipping");
            }

            return false;
        }

        $schema->setAuthorization($result['authorization']);
        try {
            $schemaMapper->update($schema);
        } catch (\Throwable $e) {
            $output->warning("WOO-536: failed to update {$label}: {$e->getMessage()}");
            return false;
        }

        $output->info("WOO-536: {$label} ".implode(' + ', $result['changes']));
        $this->logger->info(
            'WOO-536 repair: schema authorization updated',
            ['schema' => $slug, 'schemaId' => $schema->getId(), 'changes' => $result['changes']]
        );

        return true;

    }//end repairSchema()


    /**
     * Pure computation of the repaired authorization block.
     *
     * Step 1 (WOO-573, every schema): translate the Dutch 1.x property names in
     * every action's match-clauses. Step 2 (WOO-536, bundled slugs only): upgrade
     * the single-rule read shape to the depublicationDate-aware two-rule shape,
     * preserving non-conditional elements such as "authenticated".
     *
     * @param array    $authorization The current authorization block.
     * @param string   $slug          The schema slug (decides whether step 2 applies).
     * @param string[] $dropped       By-ref: "action[index].key" of Dutch keys dropped in favour of an existing English key.
     *
     * @return array{authorization: array, changes: string[]} The new block and a human-readable change list (empty = nothing to write).
     *
     * @spec openspec/specs/search/spec.md
     */
    private function computeAuthorizationChanges(array $authorization, string $slug, array &$dropped): array
    {
        $changes    = [];
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
            $changes[]          = 'upgraded to two-rule shape';
        }

        return [
            'authorization' => $translated,
            'changes'       => $changes,
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
     * @param array    $authorization The schema's authorization block.
     * @param string[] $dropped       By-ref: "action[index].key" of every dropped Dutch duplicate.
     *
     * @return array The authorization block with English match-keys.
     *
     * @spec openspec/specs/search/spec.md
     */
    private function translateDutchKeys(array $authorization, array &$dropped): array
    {
        $map = [
            'publicatiedatum'   => 'publicationDate',
            'depublicatiedatum' => 'depublicationDate',
        ];

        foreach ($authorization as $action => $rules) {
            if (is_array($rules) === false) {
                continue;
            }

            foreach ($rules as $index => $rule) {
                if (is_array($rule) === false || isset($rule['match']) === false || is_array($rule['match']) === false) {
                    continue;
                }

                $match = [];
                foreach ($rule['match'] as $key => $condition) {
                    $newKey = ($map[$key] ?? $key);
                    // An English key that already exists wins over the Dutch
                    // duplicate — never clobber a rule the admin already fixed.
                    if ($newKey !== $key && array_key_exists($newKey, $rule['match']) === true) {
                        $dropped[] = "{$action}[{$index}].{$key}";
                        continue;
                    }

                    $match[$newKey] = $condition;
                }

                $authorization[$action][$index]['match'] = $match;
            }
        }

        return $authorization;

    }//end translateDutchKeys()

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
    private function isSingleRuleShape(array $read): bool
    {
        // Expected shape: exactly one conditional public rule + optional simple
        // string elements (e.g., "authenticated"). More than one conditional
        // rule means admin has customised, and any two-rule shape (post-fix)
        // will have exactly two conditional public rules.
        $conditionalCount = 0;
        $matchedShape    = false;
        foreach ($read as $element) {
            if (is_string($element) === true) {
                // Simple element like "authenticated" or "public" — allowed.
                continue;
            }

            if (is_array($element) === false) {
                return false;
            }

            $conditionalCount++;
            if (($element['group'] ?? null) !== 'public') {
                return false;
            }

            $match = ($element['match'] ?? []);
            if (is_array($match) === false) {
                return false;
            }

            // Old shape: exactly one match key, `publicationDate: { $lte: $now }`.
            if (count($match) !== 1 || isset($match['publicationDate']) === false) {
                return false;
            }

            $publicationDate = $match['publicationDate'];
            if (is_array($publicationDate) === false
                || count($publicationDate) !== 1
                || ($publicationDate['$lte'] ?? null) !== '$now'
            ) {
                return false;
            }

            $matchedShape = true;
        }//end foreach

        // Exactly one conditional public rule with the old-shape match => needs upgrade.
        return $matchedShape === true && $conditionalCount === 1;

    }//end isSingleRuleShape()

    /**
     * Build the two-rule shape while preserving any non-conditional
     * elements (e.g., "authenticated") from the existing read block.
     *
     * @param array $existing The existing read-block array (must match single-rule shape).
     *
     * @return array The two-rule read block.
     */
    private function buildTwoRuleRead(array $existing): array
    {
        $twoRule = [
            [
                'group' => 'public',
                'match' => [
                    'publicationDate'   => ['$lte' => '$now'],
                    'depublicationDate' => ['$gte' => '$now'],
                ],
            ],
            [
                'group' => 'public',
                'match' => [
                    'publicationDate'   => ['$lte' => '$now'],
                    'depublicationDate' => ['$exists' => false],
                ],
            ],
        ];

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
