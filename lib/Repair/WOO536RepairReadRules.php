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
 * Repair step that backfills the two-rule `depublicatiedatum` read-shape
 * on the `publication` and `document` schemas for existing installations.
 *
 * WOO-536 (Robert Zondervan, 2026-08-12) requires the public search endpoint
 * to filter depublished objects. The seed JSON in
 * `lib/Settings/publication_register.json` was updated to the two-rule shape:
 *
 *   read: [
 *     { group: public, match: { publicatiedatum $lte $now, depublicatiedatum $gte $now } },
 *     { group: public, match: { publicatiedatum $lte $now, depublicatiedatum $exists false } },
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
 *      depublicatiedatum). Admin-customised shapes are left alone.
 *   4. Update is idempotent — re-running has no effect once schema is on
 *      the two-rule shape.
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Guard branches are the point of the class.
 */
class WOO536RepairReadRules implements IRepairStep
{
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
     * @param IOutput $output The output interface.
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        // Guard 1: OR must be installed (schema authorization lives in OR).
        if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
            $output->warning('OpenRegister app is not installed - skipping WOO-536 read-rule backfill');
            return;
        }

        try {
            $schemaMapper = $this->container->get('OCA\\OpenRegister\\Db\\SchemaMapper');
        } catch (\Throwable $e) {
            $output->warning('OpenRegister SchemaMapper unavailable - skipping WOO-536 read-rule backfill');
            return;
        }

        $updated = 0;
        $skipped = 0;
        foreach (['publication', 'document'] as $slug) {
            $result = $this->maybeUpgradeSchema(schemaMapper: $schemaMapper, slug: $slug, output: $output);
            if ($result === 'updated') {
                $updated++;
            } else {
                $skipped++;
            }
        }

        $output->info(
            sprintf('WOO-536 read-rule backfill: %d schema(s) upgraded, %d skipped (already correct or customised)', $updated, $skipped)
        );

    }//end run()

    /**
     * Locate a schema by slug and upgrade its read-block if it carries the
     * old single-rule shape.
     *
     * @param object  $schemaMapper The OpenRegister SchemaMapper (typed as object
     *                              so this repair step can compile without an OR
     *                              dependency at analysis-time).
     * @param string  $slug         Schema slug ('publication' or 'document').
     * @param IOutput $output       The output interface for progress reporting.
     *
     * @return string 'updated' | 'skipped'
     */
    private function maybeUpgradeSchema(object $schemaMapper, string $slug, IOutput $output): string
    {
        try {
            $schemas = $schemaMapper->findAll(filters: ['slug' => $slug]);
        } catch (\Throwable $e) {
            $output->warning("WOO-536: cannot list schemas by slug '{$slug}' — {$e->getMessage()}");
            return 'skipped';
        }

        if (empty($schemas) === true) {
            $output->info("WOO-536: schema '{$slug}' not present in this installation — nothing to upgrade");
            return 'skipped';
        }

        $updated = false;
        foreach ($schemas as $schema) {
            $authorization = $schema->getAuthorization();
            if (is_array($authorization) === false || isset($authorization['read']) === false) {
                $output->info("WOO-536: schema '{$slug}' (id ".$schema->getId().") has no authorization.read block — skipping");
                continue;
            }

            $read = $authorization['read'];
            if ($this->isSingleRuleShape(read: $read) === false) {
                $output->info(
                    "WOO-536: schema '{$slug}' (id ".$schema->getId().") already on two-rule shape or admin-customised — skipping"
                );
                continue;
            }

            // Upgrade: replace the single conditional public rule with the two-rule
            // depublicatiedatum-aware shape. Preserve any non-public elements
            // (like 'authenticated') by keeping them after the two new rules.
            $authorization['read'] = $this->buildTwoRuleRead(existing: $read);
            $schema->setAuthorization($authorization);

            try {
                $schemaMapper->update($schema);
                $output->info(
                    "WOO-536: schema '{$slug}' (id ".$schema->getId().") upgraded to two-rule shape"
                );
                $updated = true;
                $this->logger->info(
                    'WOO-536 repair: schema authorization.read upgraded to two-rule shape',
                    ['schema' => $slug, 'schemaId' => $schema->getId()]
                );
            } catch (\Throwable $e) {
                $output->warning(
                    "WOO-536: failed to update schema '{$slug}' (id ".$schema->getId()."): {$e->getMessage()}"
                );
            }
        }//end foreach

        return $updated === true ? 'updated' : 'skipped';

    }//end maybeUpgradeSchema()

    /**
     * Detect the old single-rule shape:
     *
     *   read: [
     *     { group: public, match: { publicatiedatum: { $lte: $now } } },
     *     "authenticated"     (optional third element)
     *   ]
     *
     * Any deviation from this shape (extra rules, different match keys,
     * additional operators on publicatiedatum) is considered admin-customised
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

            // Old shape: exactly one match key, `publicatiedatum: { $lte: $now }`.
            if (count($match) !== 1 || isset($match['publicatiedatum']) === false) {
                return false;
            }

            $publicatiedatum = $match['publicatiedatum'];
            if (is_array($publicatiedatum) === false
                || count($publicatiedatum) !== 1
                || ($publicatiedatum['$lte'] ?? null) !== '$now'
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
                    'publicatiedatum'   => ['$lte' => '$now'],
                    'depublicatiedatum' => ['$gte' => '$now'],
                ],
            ],
            [
                'group' => 'public',
                'match' => [
                    'publicatiedatum'   => ['$lte' => '$now'],
                    'depublicatiedatum' => ['$exists' => false],
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
