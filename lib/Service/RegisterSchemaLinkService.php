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
            $register = $this->findPublicationRegister(importResult: $importResult);
            if ($register !== null) {
                $this->linkSchemas(
                    register: $register,
                    link: $this->unlinkedSchemas(register: $register, schemas: ($importResult['schemas'] ?? []))
                );
            }
        } catch (\Throwable) {
            // Never let linkage repair sink the settings import.
            return;
        }//end try

    }//end reconcile()

    /**
     * Write the extended schema list and configuration to the register, then provision the new tables.
     *
     * Nothing is written when no schema is missing.
     *
     * The import's own table reconciliation already ran (inside importFromApp),
     * before these schemas belonged to the register, so they are provisioned here or
     * the tables would not appear until the next import.
     *
     * @param \OCA\OpenRegister\Db\Register $register The publication register.
     * @param array                         $link     The held ids, the configuration and the missing schemas.
     *
     * @return void
     *
     * @spec exclude Write step of reconcile(); covered through that method.
     */
    private function linkSchemas(\OCA\OpenRegister\Db\Register $register, array $link): void
    {
        if ($link['missing'] === []) {
            return;
        }

        $register->setSchemas($link['heldIds']);
        $register->setConfiguration($link['configuration']);
        $this->getRegisterMapper()->update($register);
        $this->provisionMagicTables(register: $register, schemas: $link['missing']);

    }//end linkSchemas()

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
     * The register's held ids and configuration, extended with the import's schemas it does not hold yet.
     *
     * Unusable entries and schemas already held are skipped.
     *
     * @param \OCA\OpenRegister\Db\Register $register The publication register.
     * @param array                         $schemas  The schema entities returned by the import.
     *
     * @return array{heldIds: array<int>, configuration: array, missing: array<int, object>}
     *
     * @spec exclude Internal collection step of reconcile(); covered through that method.
     */
    private function unlinkedSchemas(\OCA\OpenRegister\Db\Register $register, array $schemas): array
    {
        $link = [
            'heldIds'       => $this->normaliseHeldSchemaIds(storedIds: $register->getSchemas()),
            'configuration' => $register->getConfiguration(),
            'missing'       => [],
        ];
        foreach ($schemas as $schema) {
            if ($this->isNewSchema(schema: $schema, heldIds: $link['heldIds']) === true) {
                $link = $this->withSchema(link: $link, schema: $schema);
            }
        }

        return $link;

    }//end unlinkedSchemas()

    /**
     * Whether an import entry is a usable schema the register does not hold yet.
     *
     * @param mixed      $schema  The candidate entry from the import result.
     * @param array<int> $heldIds The ids already held.
     *
     * @return boolean
     *
     * @spec exclude Shape guard over an untyped cross-app payload.
     */
    private function isNewSchema(mixed $schema, array $heldIds): bool
    {
        return $this->isUsableSchema(schema: $schema) === true
            && in_array((int) $schema->getId(), $heldIds, true) === false;

    }//end isNewSchema()

    /**
     * Add one schema to the held ids and the missing list, with a magic-table entry when its slug has none.
     *
     * @param array  $link   The held ids, the configuration and the missing schemas.
     * @param object $schema The schema to link.
     *
     * @return array The extended link.
     *
     * @spec exclude Internal collection step of reconcile(); covered through that method.
     */
    private function withSchema(array $link, object $schema): array
    {
        $schemaId = (int) $schema->getId();
        $link['heldIds'][]          = $schemaId;
        $link['missing'][$schemaId] = $schema;
        $link['configuration']['schemas'][(string) $schema->getSlug()] ??= [
            'magicMapping'    => true,
            'autoCreateTable' => true,
        ];

        return $link;

    }//end withSchema()

    /**
     * Whether an import result entry is a schema entity this service can link.
     *
     * @param mixed $schema The candidate entry from the import result.
     *
     * The accessors are checked with is_callable(): on an OpenRegister entity
     * getSlug() is a magic accessor, which method_exists() does not see.
     *
     * @return boolean True when both getId() and getSlug() are callable.
     *
     * @spec exclude Shape guard over an untyped cross-app payload.
     */
    private function isUsableSchema(mixed $schema): bool
    {
        return is_object($schema) === true
            && is_callable([$schema, 'getId']) === true
            && is_callable([$schema, 'getSlug']) === true;

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
            if ($this->isPublicationRegister(register: $register) === true) {
                return $register;
            }
        }

        return $this->registerFromMapper();

    }//end findPublicationRegister()

    /**
     * The publication register as OpenRegister's mapper finds it, or null when it cannot.
     *
     * @return \OCA\OpenRegister\Db\Register|null
     *
     * @spec exclude Lookup helper for reconcile(); covered through that method.
     */
    private function registerFromMapper(): ?\OCA\OpenRegister\Db\Register
    {
        try {
            return $this->getRegisterMapper()->find(
                id: self::PUBLICATION_REGISTER_SLUG,
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable) {
            return null;
        }

    }//end registerFromMapper()

    /**
     * Whether an import entry is the publication register.
     *
     * @param mixed $register The candidate entry from the import result.
     *
     * @return boolean
     *
     * @spec exclude Shape guard over an untyped cross-app payload.
     */
    private function isPublicationRegister(mixed $register): bool
    {
        return is_object($register) === true
            && is_callable([$register, 'getSlug']) === true
            && $register->getSlug() === self::PUBLICATION_REGISTER_SLUG;

    }//end isPublicationRegister()

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
