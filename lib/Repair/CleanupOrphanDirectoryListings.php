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
        $services = $this->openRegisterServices(output: $output);
        if ($services === null) {
            return;
        }

        $tables = $this->listingTables(services: $services, output: $output);
        if ($tables === null) {
            return;
        }

        $sweep = new OrphanListingSweep(objectService: $services['objectService'], output: $output);
        foreach ($tables as $table) {
            $this->cleanRegister(table: $table, sweep: $sweep);
        }

        $output->info($sweep->summary());

    }//end run()

    /**
     * OpenRegister's schema mapper, register mapper and object service.
     *
     * @param IOutput $output The output interface.
     *
     * @return array<string, object>|null The services, or null when OpenRegister is absent.
     */
    private function openRegisterServices(IOutput $output): ?array
    {
        if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
            $output->warning('OpenRegister is not installed — skipping orphan-directory-listings cleanup');
            return null;
        }

        try {
            return [
                'schemaMapper'   => $this->container->get('OCA\OpenRegister\Db\SchemaMapper'),
                'registerMapper' => $this->container->get('OCA\OpenRegister\Db\RegisterMapper'),
                'objectService'  => $this->container->get('OCA\OpenRegister\Service\ObjectService'),
            ];
        } catch (\Throwable $e) {
            $output->warning('OpenRegister services unavailable — skipping cleanup: '.$e->getMessage());
            return null;
        }

    }//end openRegisterServices()

    /**
     * The listing table of every register that carries this app's listing schema.
     *
     * A fleet may have several registers (e.g. per tenant), so every register is checked.
     *
     * @param array<string, object> $services The OpenRegister services.
     * @param IOutput               $output   The output interface.
     *
     * @return ListingTable[]|null The tables, or null when the lookup failed.
     */
    private function listingTables(array $services, IOutput $output): ?array
    {
        $schemaId = $this->listingSchemaId(schemaMapper: $services['schemaMapper'], output: $output);
        if ($schemaId === null) {
            return null;
        }

        try {
            $registers = $services['registerMapper']->findAll();
        } catch (\Throwable $e) {
            $output->warning('Register enumeration failed — skipping cleanup: '.$e->getMessage());
            return null;
        }

        return $this->tablesCarrying(registers: $registers, schemaId: $schemaId);

    }//end listingTables()

    /**
     * The listing table of each register that carries the listing schema.
     *
     * @param iterable<object> $registers OpenRegister registers.
     * @param integer          $schemaId  The listing schema id.
     *
     * @return ListingTable[]
     */
    private function tablesCarrying(iterable $registers, int $schemaId): array
    {
        $tables = [];
        foreach ($registers as $register) {
            if (in_array($schemaId, array_map('intval', ($register->getSchemas() ?? [])), true) === true) {
                $tables[] = new ListingTable(registerId: (int) $register->getId(), schemaId: $schemaId);
            }
        }

        return $tables;

    }//end tablesCarrying()

    /**
     * The id of this app's listing schema.
     *
     * The slug is scoped to this app, so a foreign register that also ships a
     * `listing` slug never leaks in.
     *
     * @param object  $schemaMapper OpenRegister's schema mapper.
     * @param IOutput $output       The output interface.
     *
     * @return integer|null The schema id, or null when there is none or the lookup failed.
     */
    private function listingSchemaId(object $schemaMapper, IOutput $output): ?int
    {
        try {
            $schema = $schemaMapper->findByApplicationAndSlug(self::LISTING_SCHEMA_SLUG, self::APP_ID);
        } catch (\Throwable $e) {
            $output->warning('Listing schema lookup failed — skipping cleanup: '.$e->getMessage());
            return null;
        }

        if ($schema === null) {
            $output->info('No listing schema found — nothing to clean up');
            return null;
        }

        return (int) $schema->getId();

    }//end listingSchemaId()

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
     * @param ListingTable       $table The listing table to clean.
     * @param OrphanListingSweep $sweep The sweep that deletes and tallies.
     *
     * @return void
     */
    private function cleanRegister(ListingTable $table, OrphanListingSweep $sweep): void
    {
        try {
            $rows = $this->fetchOrphanRows(tableName: $table->name());
        } catch (\Throwable $e) {
            $sweep->output->warning(sprintf('Failed to scan %s: %s', $table->name(), $e->getMessage()));
            return;
        }

        foreach ($rows as $row) {
            $sweep->delete(table: $table, row: $row);
        }

        $sweep->kept += $this->countValidRows(tableName: $table->name());

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
