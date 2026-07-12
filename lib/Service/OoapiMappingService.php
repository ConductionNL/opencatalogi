<?php
/**
 * OpenCatalogi OOAPI 5.0 mapping service.
 *
 * Pure, dependency-free mapping layer that turns OpenCatalogi-owned OOAPI
 * objects (course/program/offering, materialized by OpenConnector's
 * ooapi-catalog Synchronization; organization, the existing Organisation
 * object) into OOAPI 5.0 resource shapes. The property mapping is declared on
 * the OpenRegister schema via an `x-ooapi` extension, mirroring how `x-dcat`
 * declares behaviour on schemas ({@see DcatMappingService}). Unlike `x-dcat`,
 * a schema WITHOUT an `x-ooapi` annotation is never offered as an OOAPI
 * resource at all (OOAPI-004) — there is no conservative default mapping,
 * because there is no sensible generic OOAPI shape for an arbitrary schema.
 *
 * This class has NO Nextcloud dependencies so the mapping/annotation-resolution
 * chain is fully unit-testable in isolation.
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
 * @spec openspec/changes/ooapi-catalog-publication/tasks.md#task-2-course-program-offering-schemas-x-ooapi-mapping-layer
 */

namespace OCA\OpenCatalogi\Service;

/**
 * Maps OpenCatalogi OOAPI-owned objects to OOAPI 5.0 resource shapes.
 *
 * @spec openspec/changes/ooapi-catalog-publication/tasks.md#task-2-course-program-offering-schemas-x-ooapi-mapping-layer
 */
class OoapiMappingService
{

    /**
     * Determine whether a schema declares an `x-ooapi` annotation at all.
     *
     * A schema without this annotation MUST NOT be offered as an OOAPI
     * resource (OOAPI-004) — there is no default/fallback mapping.
     *
     * @param array<string, mixed>|null $schema The OpenRegister schema array (jsonSerialize shape).
     *
     * @return boolean True when the schema declares a (non-empty, array-shaped) `x-ooapi` annotation.
     *
     * @spec openspec/changes/ooapi-catalog-publication/specs/ooapi-catalog-publication/spec.md#requirement-schema-driven-x-ooapi-mapping-annotation-no-php-hardcoded-resource-shape-ooapi-004
     */
    public function isAnnotated(?array $schema): bool
    {
        $annotation = ($schema['x-ooapi'] ?? null);
        return (is_array($annotation) === true && isset($annotation['resource']) === true && $annotation['resource'] !== '');

    }//end isAnnotated()

    /**
     * Resolve the OOAPI resource type (`organization`, `course`, `program`, `offering`)
     * a schema's `x-ooapi` annotation declares.
     *
     * @param array<string, mixed>|null $schema The OpenRegister schema array.
     *
     * @return string|null The declared resource type, or null when unannotated.
     *
     * @spec openspec/changes/ooapi-catalog-publication/specs/ooapi-catalog-publication/spec.md#requirement-schema-driven-x-ooapi-mapping-annotation-no-php-hardcoded-resource-shape-ooapi-004
     */
    public function resolveResourceType(?array $schema): ?string
    {
        if ($this->isAnnotated($schema) === false) {
            return null;
        }

        return (string) $schema['x-ooapi']['resource'];

    }//end resolveResourceType()

    /**
     * Resolve the effective OOAPI field mapping for an annotated schema.
     *
     * Returns null for an "identity" annotation (declares only `resource`,
     * no `mapping` key) — course/program/offering are already OOAPI-shaped by
     * OpenConnector's upstream mapping (design.md D2), so the caller passes
     * the object's own properties through unchanged. Returns the declared map
     * (output OOAPI dot-path => source object dot-path) when present, e.g. for
     * `organization`.
     *
     * @param array<string, mixed>|null $schema The OpenRegister schema array.
     *
     * @return array<string, string>|null The output-path => source-path map, or null (identity).
     *
     * @spec openspec/changes/ooapi-catalog-publication/specs/ooapi-catalog-publication/spec.md#requirement-schema-driven-x-ooapi-mapping-annotation-no-php-hardcoded-resource-shape-ooapi-004
     */
    public function resolveMapping(?array $schema): ?array
    {
        if ($this->isAnnotated($schema) === false) {
            return null;
        }

        $mapping = ($schema['x-ooapi']['mapping'] ?? null);
        if (is_array($mapping) === true && empty($mapping) === false) {
            return $mapping;
        }

        return null;

    }//end resolveMapping()

    /**
     * Build a single OOAPI 5.0 resource from a materialized/rendered object.
     *
     * Always sets `$idField` from the object's own identity (`@self.uuid`,
     * falling back to `id`). When `$mapping` is null (identity annotation) every
     * own-property of the object is copied through as-is (skipping the
     * `@self` envelope and null/empty values, which naturally satisfies
     * OOAPI-005's "omit, never null" rule for optional fields such as the RIO
     * identifier). When `$mapping` is provided, each declared output dot-path
     * is populated from the corresponding source dot-path, again skipping
     * null/empty source values.
     *
     * @param array<string, mixed>  $object  The source object (jsonSerialize shape).
     * @param array<string, string>|null $mapping The resolved mapping ({@see resolveMapping()}), or null for identity.
     * @param string                 $idField The OOAPI resource id field name (e.g. `courseId`).
     *
     * @return array<string, mixed> The OOAPI 5.0 resource.
     *
     * @spec openspec/changes/ooapi-catalog-publication/specs/ooapi-catalog-publication/spec.md#requirement-schema-driven-x-ooapi-mapping-annotation-no-php-hardcoded-resource-shape-ooapi-004
     * @spec openspec/changes/ooapi-catalog-publication/specs/ooapi-catalog-publication/spec.md#requirement-rio-identifier-passthrough-when-present-ooapi-005
     */
    public function buildResource(array $object, ?array $mapping, string $idField): array
    {
        $resource = [$idField => $this->resolveId($object)];

        if ($mapping === null) {
            foreach ($object as $key => $value) {
                // '@self'/'id' are the OR object envelope (the id is already set via
                // $idField above); 'catalog' is OpenCatalogi-internal scoping metadata
                // (the materialized object's owning catalog, design.md D6 addendum) —
                // not part of the OOAPI 5.0 wire shape, so never leaked into the
                // identity-mapped resource.
                if ($key === '@self' || $key === 'id' || $key === 'catalog') {
                    continue;
                }

                if ($this->isEmpty($value) === true) {
                    continue;
                }

                $resource[$key] = $value;
            }

            return $resource;
        }

        foreach ($mapping as $outputPath => $sourcePath) {
            $value = $this->extractValue($object, $sourcePath);
            if ($this->isEmpty($value) === true) {
                continue;
            }

            $this->setNested($resource, $outputPath, $value);
        }

        return $resource;

    }//end buildResource()

    /**
     * Resolve an object's stable identity for the OOAPI resource id field.
     *
     * @param array<string, mixed> $object The source object.
     *
     * @return string The object's UUID (preferring `@self.uuid`, falling back to `id`).
     */
    private function resolveId(array $object): string
    {
        return (string) ($object['@self']['uuid'] ?? $object['id'] ?? '');

    }//end resolveId()

    /**
     * Extract a value from an object by a dot-path.
     *
     * @param array<string, mixed> $object The source object.
     * @param string                $path   The dot-separated source path (e.g. `code`, `a.b`).
     *
     * @return mixed The resolved value, or null when absent.
     */
    private function extractValue(array $object, string $path): mixed
    {
        $cursor = $object;
        foreach (explode('.', $path) as $segment) {
            if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;

    }//end extractValue()

    /**
     * Assign a value into a (possibly nested) output dot-path, creating
     * intermediate arrays as needed.
     *
     * @param array<string, mixed> $target The output resource under construction (by reference).
     * @param string                $path   The dot-separated output path (e.g. `primaryCode.code`).
     * @param mixed                 $value  The value to assign.
     *
     * @return void
     */
    private function setNested(array &$target, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $cursor   = &$target;
        foreach ($segments as $index => $segment) {
            if ($index === (count($segments) - 1)) {
                $cursor[$segment] = $value;
                break;
            }

            if (isset($cursor[$segment]) === false || is_array($cursor[$segment]) === false) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }

    }//end setNested()

    /**
     * Determine whether a mapped/copied value counts as "absent" for
     * omission purposes (OOAPI-005: omit, never emit null/empty).
     *
     * @param mixed $value The candidate value.
     *
     * @return boolean True when the value is null, an empty string, or an empty array.
     */
    private function isEmpty(mixed $value): bool
    {
        return ($value === null || $value === '' || $value === []);

    }//end isEmpty()
}//end class
