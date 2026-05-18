<?php

/**
 * OpenCatalogi View Service.
 *
 * Provides two-phase retrieval of gebruiksobjecten: regularly-owned gebruik (RBAC-filtered)
 * and deelnames gebruik (RBAC-bypassed, filtered by deelnemers array). The two result sets
 * are merged and deduplicated so that owned objects always take precedence over deelnames
 * objects for the same module/referentiecomponent combination.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/deelnames-gebruik/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCP\IAppConfig;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for retrieving gebruiksobjecten including deelnames (participations).
 *
 * @spec openspec/changes/deelnames-gebruik/tasks.md#task-2
 */
class ViewService
{
    /**
     * ViewService constructor.
     *
     * @param IAppConfig         $config     App configuration interface.
     * @param ContainerInterface $container  Container for dependency injection.
     * @param IAppManager        $appManager App manager for checking installed apps.
     * @param LoggerInterface    $logger     PSR-3 logger.
     *
     * @spec openspec/changes/deelnames-gebruik/tasks.md#task-2
     */
    public function __construct(
        private readonly IAppConfig $config,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Retrieve the OpenRegister ObjectService from the container.
     *
     * @return \OCA\OpenRegister\Service\ObjectService The ObjectService.
     *
     * @throws RuntimeException When OpenRegister is not installed.
     */
    private function getObjectService(): \OCA\OpenRegister\Service\ObjectService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        }

        throw new RuntimeException('OpenRegister service is not available.');

    }//end getObjectService()

    /**
     * Get the configured gebruik register slug.
     *
     * @return string The register slug or empty string when not configured.
     */
    public function getGebruikRegister(): string
    {
        return $this->config->getValueString('opencatalogi', 'gebruik_register', '');

    }//end getGebruikRegister()

    /**
     * Get the configured gebruik schema slug.
     *
     * @return string The schema slug or empty string when not configured.
     */
    public function getGebruikSchema(): string
    {
        return $this->config->getValueString('opencatalogi', 'gebruik_schema', '');

    }//end getGebruikSchema()

    /**
     * Retrieve gebruiksobjecten for an organization using two-phase retrieval.
     *
     * Phase 1: Regular RBAC-filtered query for owned gebruiksobjecten.
     * Phase 2: RBAC-disabled query for deelnames gebruiksobjecten where the organization
     *          appears in the `deelnemers` array.
     *
     * The two result sets are merged with deduplication: if the same object appears in both
     * sets (e.g. an organization appears in its own gebruiksobject's deelnemers), the owned
     * version is kept and it is NOT marked as deelnames.
     *
     * @param string $organizationId   UUID of the organization to retrieve gebruik for.
     * @param bool   $includeGebruik   Whether to include regularly-owned gebruiksobjecten.
     * @param bool   $includeDeelnames Whether to include deelnames gebruiksobjecten.
     *
     * @return array{
     *     owned: array<int, array<string, mixed>>,
     *     deelnames: array<int, array<string, mixed>>,
     *     warnings: array<int, string>
     * } Separated result sets plus any non-fatal warnings.
     *
     * @spec openspec/changes/deelnames-gebruik/tasks.md#task-2
     */
    public function getGebruikForOrganization(
        string $organizationId,
        bool $includeGebruik,
        bool $includeDeelnames
    ): array {
        $owned     = [];
        $deelnames = [];
        $warnings  = [];

        if ($includeGebruik === false && $includeDeelnames === false) {
            return [
                'owned'     => [],
                'deelnames' => [],
                'warnings'  => [],
            ];
        }

        $register = $this->getGebruikRegister();
        $schema   = $this->getGebruikSchema();

        // Phase 1 — regular owned gebruik (standard RBAC).
        if ($includeGebruik === true) {
            try {
                $owned = $this->fetchOwnedGebruik(
                    register: $register,
                    schema: $schema
                );
            } catch (\Throwable $e) {
                $warning = 'Owned gebruik query failed: '.$e->getMessage();
                $this->logger->warning($warning);
                $warnings[] = $warning;
            }
        }

        // Phase 2 — deelnames gebruik (RBAC disabled, filtered by deelnemers).
        if ($includeDeelnames === true) {
            try {
                $rawDeelnames = $this->fetchDeelnames(
                    organizationId: $organizationId,
                    register: $register,
                    schema: $schema
                );
                $deelnames    = $this->annotateDeelnames(rawDeelnames: $rawDeelnames);
            } catch (\Throwable $e) {
                $warning = 'Deelnames gebruik query failed: '.$e->getMessage();
                $this->logger->warning($warning);
                $warnings[] = $warning;
            }
        }

        // Deduplicate: remove deelnames entries that already appear in the owned set.
        $deelnames = $this->deduplicateDeelnames(
            owned: $owned,
            deelnames: $deelnames
        );

        return [
            'owned'     => $owned,
            'deelnames' => $deelnames,
            'warnings'  => $warnings,
        ];

    }//end getGebruikForOrganization()

    /**
     * Fetch regularly-owned gebruiksobjecten using standard RBAC.
     *
     * @param string $register Register slug (empty string = all registers).
     * @param string $schema   Schema slug (empty string = all schemas).
     *
     * @return array<int, array<string, mixed>> List of owned gebruiksobjecten.
     *
     * @spec openspec/changes/deelnames-gebruik/tasks.md#task-2
     */
    private function fetchOwnedGebruik(string $register, string $schema): array
    {
        $objectService = $this->getObjectService();

        $query = ['_source' => 'database'];

        if ($register !== '') {
            $query['@self']['register'] = $register;
        }

        if ($schema !== '') {
            $query['@self']['schema'] = $schema;
        }

        $result = $objectService->searchObjectsPaginated(query: $query);

        return $result['results'] ?? [];

    }//end fetchOwnedGebruik()

    /**
     * Fetch deelnames gebruiksobjecten with RBAC and multitenancy disabled.
     *
     * The query filters on the `deelnemers` field containing the given organization UUID.
     * No pagination limit is applied so large result sets are returned in full.
     *
     * @param string $organizationId UUID of the organization to search for in deelnemers.
     * @param string $register       Register slug (empty string = all registers).
     * @param string $schema         Schema slug (empty string = all schemas).
     *
     * @return array<int, array<string, mixed>> Raw deelnames gebruiksobjecten (without metadata).
     *
     * @spec openspec/changes/deelnames-gebruik/tasks.md#task-2
     */
    private function fetchDeelnames(
        string $organizationId,
        string $register,
        string $schema
    ): array {
        $objectService = $this->getObjectService();

        $query = [
            '_source'    => 'database',
            '_limit'     => 1000,
            'deelnemers' => $organizationId,
        ];

        if ($register !== '') {
            $query['@self']['register'] = $register;
        }

        if ($schema !== '') {
            $query['@self']['schema'] = $schema;
        }

        $result = $objectService->searchObjectsPaginated(
            query: $query,
            _rbac: false,
            _multitenancy: false
        );

        return $result['results'] ?? [];

    }//end fetchDeelnames()

    /**
     * Annotate raw deelnames results with source organization metadata and type marker.
     *
     * Adds `_type`, `_sourceOrganization`, and `_sourceOrganizationId` to each node.
     *
     * @param array<int, array<string, mixed>> $rawDeelnames Raw results from fetchDeelnames().
     *
     * @return array<int, array<string, mixed>> Annotated deelnames nodes.
     *
     * @spec openspec/changes/deelnames-gebruik/tasks.md#task-2
     */
    private function annotateDeelnames(array $rawDeelnames): array
    {
        $annotated = [];

        foreach ($rawDeelnames as $item) {
            $ownerName = '';
            $ownerId   = '';

            // Extract owning organization data from the object if present.
            if (isset($item['organisatie']) === true) {
                if (is_array($item['organisatie']) === true) {
                    $ownerName = $item['organisatie']['name'] ?? ($item['organisatie']['naam'] ?? '');
                    $ownerId   = $item['organisatie']['id'] ?? ($item['organisatie']['uuid'] ?? '');
                } else if (is_string($item['organisatie']) === true) {
                    $ownerId = $item['organisatie'];
                }
            }

            $item['_type'] = 'deelnames';
            $item['_sourceOrganization']   = $ownerName;
            $item['_sourceOrganizationId'] = $ownerId;

            $annotated[] = $item;
        }

        return $annotated;

    }//end annotateDeelnames()

    /**
     * Remove deelnames entries that duplicate an entry already in the owned set.
     *
     * An entry is considered a duplicate when both share the same object UUID (id field).
     * Ownership takes precedence: the owned version is kept, the deelnames version is dropped.
     *
     * @param array<int, array<string, mixed>> $owned     Owned gebruiksobjecten.
     * @param array<int, array<string, mixed>> $deelnames Annotated deelnames results.
     *
     * @return array<int, array<string, mixed>> Deduplicated deelnames list.
     *
     * @spec openspec/changes/deelnames-gebruik/tasks.md#task-2
     */
    public function deduplicateDeelnames(array $owned, array $deelnames): array
    {
        if (empty($owned) === true || empty($deelnames) === true) {
            return $deelnames;
        }

        // Build an index of owned UUIDs for O(1) lookups.
        $ownedIds = [];
        foreach ($owned as $ownedItem) {
            $id = $ownedItem['id'] ?? ($ownedItem['uuid'] ?? null);
            if ($id !== null) {
                $ownedIds[$id] = true;
            }
        }

        $unique = [];
        foreach ($deelnames as $deelItem) {
            $id = $deelItem['id'] ?? ($deelItem['uuid'] ?? null);
            if ($id === null || isset($ownedIds[$id]) === false) {
                $unique[] = $deelItem;
            }
        }

        return $unique;

    }//end deduplicateDeelnames()
}//end class
