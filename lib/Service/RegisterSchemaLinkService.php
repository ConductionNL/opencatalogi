<?php
/**
 * OpenCatalogi Register Schema Link Service
 *
 * Repairs the register-to-schema linkage that OpenRegister's version-gated
 * register import can leave behind after a settings import.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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
 * @spec openspec/specs/admin-settings/spec.md
 */

namespace OCA\OpenCatalogi\Service;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Keeps the persisted publication register holding every schema an import created.
 *
 * `ImportHandler::importRegister()` skips the register update whenever the payload's
 * `components.registers.publication.version` is not newer than the persisted one. That
 * version has been 0.1.0 since day one, so on any install that already has the register,
 * a fragment adding a schema updates nothing and the schema is left orphaned — no
 * register holds it, so no magic table is provisioned and the `{type}_register` /
 * `{type}_schema` pair the app resolves is inconsistent.
 *
 * Extracted from SettingsService so the OpenRegister `Register` / `MagicMapper` types
 * live on one small collaborator instead of raising that class's object coupling.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
class RegisterSchemaLinkService
{

    /**
     * The OpenRegister app id, used to gate every container lookup below.
     *
     * @var string
     */
    private const OPENREGISTER_APP_ID = 'openregister';

    /**
     * The slug of the shared publication register.
     *
     * @var string
     */
    private const PUBLICATION_REGISTER_SLUG = 'publication';

    /**
     * RegisterSchemaLinkService constructor.
     *
     * @param ContainerInterface $container  Container for dependency injection.
     * @param IAppManager        $appManager App manager interface.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager
    ) {

    }//end __construct()

    /**
     * Ensure every schema this import created is held by the publication register.
     *
     * Patches the persisted register additively: missing schema ids are appended and
     * given a magic-mapping entry, then the physical table for each newly linked pair is
     * provisioned. Existing entries, ordering and admin edits are left untouched, so it
     * is safe to run on every import.
     *
     * Failures are swallowed — a linkage that cannot be repaired must not sink an
     * otherwise successful settings import (same posture as backfillCatalogScopes()).
     *
     * @param array $importResult The result from the importFromApp call.
     *
     * @return void
     *
     * @spec openspec/specs/admin-settings/spec.md
     */
    public function reconcile(array $importResult): void
    {
        try {
            $register = $this->findPublicationRegister($importResult);
            if ($register === null) {
                return;
            }

            $heldIds       = $this->normaliseHeldSchemaIds($register->getSchemas());
            $configuration = $register->getConfiguration();

            $missing = $this->collectUnlinkedSchemas(
                schemas: ($importResult['schemas'] ?? []),
                heldIds: $heldIds,
                configuration: $configuration
            );

            if ($missing === []) {
                return;
            }

            $register->setSchemas($heldIds);
            $register->setConfiguration($configuration);
            $this->getRegisterMapper()->update($register);

            // The import's own table reconciliation already ran (inside importFromApp),
            // before these schemas belonged to the register, so provision them here or
            // the tables would not appear until the next import.
            $this->provisionMagicTables($register, $missing);
        } catch (\Throwable) {
            // Never let linkage repair sink the settings import.
            return;
        }//end try

    }//end reconcile()

    /**
     * Normalise a register's stored schema list to a list of integer ids.
     *
     * @param array $storedIds The raw `schemas` value held by the register.
     *
     * @return array<int> The ids that are usable for comparison.
     *
     * @spec exclude Pure value normalisation with no domain behaviour of its own.
     */
    private function normaliseHeldSchemaIds(array $storedIds): array
    {
        $heldIds = [];
        foreach ($storedIds as $heldId) {
            if (is_numeric($heldId) === true) {
                $heldIds[] = (int) $heldId;
            }
        }

        return $heldIds;

    }//end normaliseHeldSchemaIds()

    /**
     * Collect the imported schemas the register does not hold yet.
     *
     * Appends each newly found id to `$heldIds` and seeds a magic-mapping entry in
     * `$configuration` when the fragment did not supply one, so the caller can persist
     * both in a single update.
     *
     * @param array $schemas       The schema entities returned by the import.
     * @param array $heldIds       The ids already held, appended to in place.
     * @param array $configuration The register configuration, extended in place.
     *
     * @return array<int, object> The newly linked schemas, keyed by id.
     *
     * @spec exclude Internal collection step of reconcile(); covered through that method.
     */
    private function collectUnlinkedSchemas(array $schemas, array &$heldIds, array &$configuration): array
    {
        $missing = [];

        foreach ($schemas as $schema) {
            if ($this->isUsableSchema($schema) === false) {
                continue;
            }

            $schemaId = (int) $schema->getId();
            if (in_array($schemaId, $heldIds, true) === true) {
                continue;
            }

            $heldIds[]          = $schemaId;
            $missing[$schemaId] = $schema;
            $slug = (string) $schema->getSlug();

            if (isset($configuration['schemas'][$slug]) === false) {
                $configuration['schemas'][$slug] = [
                    'magicMapping'    => true,
                    'autoCreateTable' => true,
                ];
            }
        }//end foreach

        return $missing;

    }//end collectUnlinkedSchemas()

    /**
     * Whether an import result entry is a schema entity this service can link.
     *
     * @param mixed $schema The candidate entry from the import result.
     *
     * @return boolean True when both getId() and getSlug() are callable.
     *
     * @spec exclude Shape guard over an untyped cross-app payload.
     */
    private function isUsableSchema(mixed $schema): bool
    {
        return is_object($schema) === true
            && method_exists($schema, 'getId') === true
            && method_exists($schema, 'getSlug') === true;

    }//end isUsableSchema()

    /**
     * Provision the physical table for each newly linked register/schema pair.
     *
     * @param object $register The publication register.
     * @param array  $schemas  The newly linked schema entities.
     *
     * @return void
     *
     * @spec exclude Table provisioning step of reconcile(); covered through that method.
     */
    private function provisionMagicTables(object $register, array $schemas): void
    {
        $magicMapper = $this->getMagicMapper();
        foreach ($schemas as $schema) {
            try {
                $magicMapper->ensureTableForRegisterSchema(register: $register, schema: $schema);
            } catch (\Throwable) {
                // A single unprovisionable table must not block the others.
                continue;
            }
        }

    }//end provisionMagicTables()

    /**
     * Resolve the shared publication register entity.
     *
     * Prefers the entity returned by the import (already loaded) and falls back to a
     * slug lookup for the case where the register import was version-gated away.
     *
     * @param array $importResult The result from the importFromApp call.
     *
     * @return \OCA\OpenRegister\Db\Register|null The register, or null when unavailable.
     *
     * @spec exclude Lookup helper for reconcile(); covered through that method.
     */
    private function findPublicationRegister(array $importResult): ?\OCA\OpenRegister\Db\Register
    {
        foreach (($importResult['registers'] ?? []) as $register) {
            if (is_object($register) === true
                && method_exists($register, 'getSlug') === true
                && $register->getSlug() === self::PUBLICATION_REGISTER_SLUG
            ) {
                return $register;
            }
        }

        try {
            return $this->getRegisterMapper()->find(
                id: self::PUBLICATION_REGISTER_SLUG,
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable) {
            return null;
        }

    }//end findPublicationRegister()

    /**
     * Attempts to retrieve the OpenRegister RegisterMapper from the container.
     *
     * @return \OCA\OpenRegister\Db\RegisterMapper The RegisterMapper.
     * @throws \RuntimeException If the mapper is not available.
     *
     * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
     *       RegisterMapper from the container; pure framework plumbing, no domain behavior.
     */
    private function getRegisterMapper(): \OCA\OpenRegister\Db\RegisterMapper
    {
        if (in_array(needle: self::OPENREGISTER_APP_ID, haystack: $this->appManager->getInstalledApps()) === true) {
            return $this->container->get('OCA\OpenRegister\Db\RegisterMapper');
        }

        throw new RuntimeException('RegisterMapper is not available.');

    }//end getRegisterMapper()

    /**
     * Attempts to retrieve the OpenRegister MagicMapper from the container.
     *
     * @return \OCA\OpenRegister\Db\MagicMapper The MagicMapper.
     * @throws \RuntimeException If the mapper is not available.
     *
     * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
     *       MagicMapper from the container; pure framework plumbing, no domain behavior.
     */
    private function getMagicMapper(): \OCA\OpenRegister\Db\MagicMapper
    {
        if (in_array(needle: self::OPENREGISTER_APP_ID, haystack: $this->appManager->getInstalledApps()) === true) {
            return $this->container->get('OCA\OpenRegister\Db\MagicMapper');
        }

        throw new RuntimeException('MagicMapper is not available.');

    }//end getMagicMapper()
}//end class
