<?php
/**
 * State of one public full-text search assembly.
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
 * @spec openspec/specs/search/spec.md
 */

namespace OCA\OpenCatalogi\Service;

/**
 * Holds what {@see PublicationQueryService::assemblePublicSearchResults()} builds
 * up while it shapes one page of results: the forwarded query, the per-request
 * lookup caches, the rows kept and the number of candidates dropped.
 *
 * @spec openspec/specs/search/spec.md
 */
final class PublicSearchAssembly
{

    /**
     * The query as forwarded to OpenRegister.
     *
     * @var array<string, mixed>
     */
    public array $searchQuery = [];

    /**
     * Whether the caller opted into body-text search with `_content`.
     *
     * @var bool
     */
    public bool $contentSearch = false;

    /**
     * OpenRegister's SchemaMapper, resolved once the candidates are in.
     *
     * @var object|null
     */
    public ?object $schemaMapper = null;

    /**
     * The publication schema in scope, used to link documents to their publication.
     *
     * @var integer|null
     */
    public ?int $publicationSchemaId = null;

    /**
     * The register document refinements run in, or null when the scope spans several.
     *
     * @var integer|null
     */
    public ?int $refinementRegisterId = null;

    /**
     * Schema slug per schema id, null for a failed lookup.
     *
     * @var array<int, string|null>
     */
    public array $schemaSlugById = [];

    /**
     * Linked-publication summary per document uuid.
     *
     * @var array<string, array|null>
     */
    public array $publicationCache = [];

    /**
     * Publication lookup result per publication slug.
     *
     * @var array<string, array|null>
     */
    public array $slugCache = [];

    /**
     * Object ids already kept, for deduplication.
     *
     * @var array<int|string, bool>
     */
    public array $seenObjectIds = [];

    /**
     * The rows kept so far.
     *
     * @var array<int, array>
     */
    public array $rows = [];

    /**
     * Candidates dropped on this page (deduplication does not count).
     *
     * @var integer
     */
    public int $droppedCount = 0;


    /**
     * Constructor.
     *
     * @param object                                      $objectService OpenRegister ObjectService instance.
     * @param array{registers: array<int>, schemas: array<int>} $scope   The guarded catalogue scope.
     *
     * @spec openspec/specs/search/spec.md
     */
    public function __construct(
        public readonly object $objectService,
        public readonly array $scope,
    ) {

    }//end __construct()
}//end class
