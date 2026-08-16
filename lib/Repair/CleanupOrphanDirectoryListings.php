<?php
/**
 * Repair step for removing directory listings whose `directory` field does not
 * point at a valid `/api/directory` endpoint.
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Repair;

use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;

/**
 * One-shot cleanup for the getUniqueDirectories() publications-URL bug.
 *
 * Older builds of DirectoryService::getUniqueDirectories() gathered `publications`
 * URLs and then fed them into syncDirectory() as if they were directory URLs. That
 * caused syncListing() to save every publication returned by the peer's
 * /api/federation/publications endpoint as a new "listing" row, with the
 * `directory` field carrying the (wrong) publications URL. Those rows never sync
 * again once the bug is fixed and just sit as stale garbage.
 *
 * This step deletes any listing whose `directory` field is empty OR does not
 * contain `/api/directory`. Safe to run repeatedly — it's a no-op once the store
 * is clean.
 *
 * Register/schema discovery goes through SchemaMapper / RegisterMapper rather
 * than the app-config `listing_register` key: some environments have a corrupt
 * value stored there (literal string "Array" — see #TODO fix upstream in
 * updateObjectTypeConfiguration()) and IAppConfig-driven lookup would then
 * silently skip the cleanup.
 */
class CleanupOrphanDirectoryListings implements IRepairStep
{
    private const LISTING_SCHEMA_SLUG = 'listing';

    private const APP_ID = 'opencatalogi';

    /**
     * Constructor.
     *
     * @param IAppManager        $appManager The app manager.
     * @param IDBConnection      $db         Database connection (direct query on per-register tables).
     * @param ContainerInterface $container  DI container for lazy OpenRegister lookups.
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly IDBConnection $db,
        private readonly ContainerInterface $container
    ) {

    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Clean up OpenCatalogi directory listings with a non-directory URL';

    }//end getName()

    /**
     * Run the repair step.
     *
     * Serves DIR-004 ("synchronize all directories via cron") from the storage
     * side. That sync walks the `directory` field of every stored listing, and
     * a row holding a PUBLICATIONS url instead of a directory url makes the
     * hourly job sync from an endpoint that is not a directory. DirectoryService
     * ::getKnownDirectoryUrls() filters such rows out at read time; this step
     * removes the ones already persisted, so the two together are what keep the
     * cron's URL set valid.
     *
     * @param IOutput $output The output interface.
     *
     * @return void
     *
     * @spec openspec/specs/dashboard/spec.md#requirement-synchronize-all-directories-via-cron-every-hour-dir-004
     */
    public function run(IOutput $output): void
    {
        if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
            $output->warning('OpenRegister is not installed — skipping orphan-directory-listings cleanup');
            return;
        }

        try {
            $schemaMapper   = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
            $registerMapper = $this->container->get('OCA\OpenRegister\Db\RegisterMapper');
            $objectService  = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $output->warning('OpenRegister services unavailable — skipping cleanup: '.$e->getMessage());
            return;
        }

        // Resolve the listing schema by slug scoped to this app so a foreign
        // register that happens to also ship a `listing` slug never leaks in.
        try {
            $schema = $schemaMapper->findByApplicationAndSlug(self::LISTING_SCHEMA_SLUG, self::APP_ID);
        } catch (\Throwable $e) {
            $output->warning('Listing schema lookup failed — skipping cleanup: '.$e->getMessage());
            return;
        }

        if ($schema === null) {
            $output->info('No listing schema found — nothing to clean up');
            return;
        }

        $schemaId = (int) $schema->getId();

        // Find every register that carries this schema; typically 1, but a fleet may
        // have multiple registers (e.g. per-tenant) so we sweep across all of them.
        try {
            $registers = $registerMapper->findAll();
        } catch (\Throwable $e) {
            $output->warning('Register enumeration failed — skipping cleanup: '.$e->getMessage());
            return;
        }

        $totalDeleted = 0;
        $totalKept    = 0;
        $totalFailed  = 0;

        foreach ($registers as $register) {
            $registerSchemas = array_map('intval', ($register->getSchemas() ?? []));
            if (in_array($schemaId, $registerSchemas, true) === false) {
                continue;
            }

            $tally = $this->cleanRegister(
                registerId: (int) $register->getId(),
                schemaId: $schemaId,
                objectService: $objectService,
                output: $output
            );

            $totalDeleted += $tally['deleted'];
            $totalKept    += $tally['kept'];
            $totalFailed  += $tally['failed'];
        }//end foreach

        $output->info(
                sprintf(
            'Directory-listings cleanup: deleted %d orphan row(s), kept %d valid row(s), %d failure(s)',
            $totalDeleted,
            $totalKept,
            $totalFailed
        )
                );

    }//end run()

    /**
     * Delete the orphan listing rows in one register's listing table.
     *
     * Extracted from run() so that method stays under PHPMD's cyclomatic (10),
     * NPath (200) and length (100) thresholds — it was 12 / 672 / 102. The
     * alternative was three baseline entries, each scoped to (rule, file) and
     * therefore licensing every future violation of that rule in this class.
     *
     * A scan failure on one register is logged and skipped rather than aborting
     * the sweep: a repair step that stops at the first unreadable table would
     * leave every later register uncleaned, which is the failure mode this step
     * exists to prevent.
     *
     * @param int     $registerId    The register whose listing table to clean.
     * @param int     $schemaId      The resolved listing schema id.
     * @param object  $objectService OpenRegister's ObjectService.
     * @param IOutput $output        Repair output channel.
     *
     * @return array{deleted: int, kept: int, failed: int} Per-register tally.
     */
    private function cleanRegister(int $registerId, int $schemaId, object $objectService, IOutput $output): array
    {
        $tableName = sprintf('openregister_table_%d_%d', $registerId, $schemaId);
        $tally     = [
            'deleted' => 0,
            'kept'    => 0,
            'failed'  => 0,
        ];

        try {
            $rows = $this->fetchOrphanRows($tableName);
        } catch (\Throwable $e) {
            $output->warning(sprintf('Failed to scan %s: %s', $tableName, $e->getMessage()));
            return $tally;
        }

        foreach ($rows as $row) {
            // No `?? null`: fetchOrphanRows() selects `_uuid` explicitly and
            // declares `array{_uuid: string, ...}`, so phpstan rightly calls the
            // coalesce dead code. The empty() guard stays — the column is
            // selected, but a blank value would still make deleteObject()
            // address nothing, and that row is counted as a failure rather than
            // silently skipped.
            $uuid = $row['_uuid'];
            if (empty($uuid) === true) {
                $tally['failed']++;
                continue;
            }

            try {
                $objectService->deleteObject(
                    uuid: (string) $uuid,
                    register: (string) $registerId,
                    schema: (string) $schemaId,
                    _rbac: false,
                    _multitenancy: false
                );
                $tally['deleted']++;
            } catch (\Throwable $e) {
                $tally['failed']++;
                $output->warning(sprintf('Failed to delete listing %s: %s', (string) $uuid, $e->getMessage()));
            }
        }//end foreach

        $tally['kept'] = $this->countValidRows($tableName);

        return $tally;

    }//end cleanRegister()

    /**
     * Find rows in a per-register/schema listing table whose `directory` field
     * is empty OR does not contain `/api/directory` AND that have not yet been
     * soft-deleted (`_deleted IS NULL`).
     *
     * @param string $tableName The per-register/schema table name (unprefixed).
     *
     * @return array<int, array{_uuid: string, title: ?string, directory: ?string}>
     *
     * @throws \Throwable On any DB error (caller filters).
     */
    private function fetchOrphanRows(string $tableName): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('_uuid', 'title', 'directory')
            ->from($tableName)
            ->where($qb->expr()->isNull('_deleted'))
            ->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->isNull('directory'),
                    $qb->expr()->notLike('directory', $qb->createNamedParameter('%/api/directory%'))
                )
            );

        return $qb->executeQuery()->fetchAll();

    }//end fetchOrphanRows()

    /**
     * Count non-deleted rows in a listing table that DO carry a valid
     * `/api/directory` URL. Used for the summary line only.
     *
     * @param string $tableName The per-register/schema table name (unprefixed).
     *
     * @return int
     */
    private function countValidRows(string $tableName): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'c'))
                ->from($tableName)
                ->where($qb->expr()->isNull('_deleted'))
                ->andWhere(
                    $qb->expr()->like('directory', $qb->createNamedParameter('%/api/directory%'))
                );

            $row = $qb->executeQuery()->fetch();
            return (int) ($row['c'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }

    }//end countValidRows()
}//end class
