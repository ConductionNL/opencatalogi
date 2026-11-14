<?php

/**
 * Service for generating DIWOO-compliant sitemaps.
 *
 * Provides functionality for building sitemap indexes and publication sitemaps
 * following the DIWOO metadata standard for Dutch government document publication.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/specs/woo-compliance/spec.md
 * @spec openspec/specs/woo-compliance/spec.md
 * @spec openspec/specs/woo-compliance/spec.md
 * @spec openspec/specs/woo-compliance/spec.md
 */

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Contract\SitemapServiceInterface;
use OCA\OpenCatalogi\Http\XMLResponse;
use OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Service for generating DIWOO-compliant sitemaps.
 *
 * Provides functionality for building sitemap indexes and publication sitemaps
 * following the DIWOO metadata standard.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/specs/woo-compliance/spec.md
 */
class SitemapService implements SitemapServiceInterface {

	/**
	 * The namespace attributes of the sitemap `urlset` root, in output order; the schema
	 * location is filled in from StandardsVersionService.
	 *
	 * @var array<string, string|null>
	 */
	private const DOCUMENTS_ATTRIBUTES = [
		'xmlns' => StandardsVersionService::SITEMAP_NAMESPACE,
		'xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
		'xmlns:diwoo' => StandardsVersionService::DIWOO_NAMESPACE,
		'xsi:schemaLocation' => null,
		'xmlns:xhtml' => 'http://www.w3.org/1999/xhtml',
		'xmlns:image' => 'http://www.google.com/schemas/sitemap-image/1.1',
		'xmlns:video' => 'http://www.google.com/schemas/sitemap-video/1.1',
		'xmlns:news' => 'http://www.google.com/schemas/sitemap-news/0.9',
	];

	/**
	 * Default maximum number of publications fetched per sitemap page.
	 *
	 * Overridable via the `sitemap_max_per_page` app-config key (see
	 * getMaxPerPage()). The constant is the shipped default only — operators
	 * tune the live value without a code change (audit Stream 4).
	 *
	 * @var int
	 */
	private const DEFAULT_MAX_PER_PAGE = 1000;

	/**
	 * App-config key that overrides DEFAULT_MAX_PER_PAGE.
	 *
	 * @var string
	 */
	private const CONFIG_MAX_PER_PAGE = 'sitemap_max_per_page';

	/**
	 * The DiWoo document mapper, made on first use; it caches TOOI lookups for the request.
	 *
	 * @var SitemapDocumentMapper|null
	 */
	private ?SitemapDocumentMapper $documentMapper = null;

	/**
	 * Constructor for SitemapService.
	 *
	 * @param OpenRegisterLookup $openRegister The guarded OpenRegister services
	 * @param SettingsService $settingsService The settings service
	 * @param InstanceSettings $instance The base URL and app configuration (operator-tunable page size)
	 * @param TooiVocabularyService $tooiVocabulary The TOOI value-list resolver
	 * @param PublicationQueryService $queryService The SCH-PFTS-CAT-002 read-rule guard
	 * @param WooCategoryRegistry $categories The information categories, bundled plus local
	 * @param FileFacts|null $fileFacts Reads each file's type from its bytes (REQ-PFF-001); without it no format is emitted.
	 */
	public function __construct(
		private readonly OpenRegisterLookup $openRegister,
		private readonly SettingsService $settingsService,
		private readonly InstanceSettings $instance,
		private readonly TooiVocabularyService $tooiVocabulary,
		private readonly PublicationQueryService $queryService,
		private readonly WooCategoryRegistry $categories,
		private readonly ?FileFacts $fileFacts = null,
	) {

	}//end __construct()

	/**
	 * The sitemap file names this instance serves, and the title each one carries.
	 *
	 * The categories are data (REQ-WIC-001), so this is a method over
	 * {@see WooCategoryRegistry} rather than the former `INFO_CAT` constant. A
	 * category an operator added appears here without a code change, and one the
	 * registry refused does not appear at all.
	 *
	 * @return array<string, string> File name → category title.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-information-categories-are-data-not-code-req-wic-001
	 */
	public function sitemapFiles(): array {
		return $this->categories->sitemapFiles();
	}//end sitemapFiles()

	/**
	 * Resolve the operator-configured maximum publications per sitemap page.
	 *
	 * Reads `sitemap_max_per_page` from IAppConfig, falling back to
	 * DEFAULT_MAX_PER_PAGE. Values below 1 are clamped to 1.
	 *
	 * @return int The effective per-page limit.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md (Requirement: Promote hardcoded constants)
	 */
	private function getMaxPerPage(): int {
		$value = $this->instance->intValue(self::CONFIG_MAX_PER_PAGE, self::DEFAULT_MAX_PER_PAGE);
		return max(1, $value);
	}//end getMaxPerPage()

	/**
	 * Attempts to retrieve the OpenRegister ObjectService from the container.
	 *
	 * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister ObjectService if available, null otherwise.
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 */
	private function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService {
		return $this->openRegister->installedService('OCA\OpenRegister\Service\ObjectService', 'OpenRegister service is not available.');
	}//end getObjectService()

	/**
	 * Attempts to retrieve the OpenRegister FileService from the container.
	 *
	 * @return \OCA\OpenRegister\Service\FileService|null The OpenRegister FileService if available, null otherwise.
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 */
	private function getFileService(): ?\OCA\OpenRegister\Service\FileService {
		return $this->openRegister->installedService('OCA\OpenRegister\Service\FileService', 'OpenRegister FileService is not available.');
	}//end getFileService()

	/**
	 * Build sitemap index based on woo category.
	 *
	 * Visibility is governed by RBAC in every search (_rbac: true): sitemaps
	 * expose only the published objects the public group may read.
	 *
	 * @param string $catalogSlug The catalog slug identifier.
	 * @param string $categoryCode The DIWOO category code.
	 *
	 * @return XMLResponse The sitemap index XML response.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function buildSitemapIndex(string $catalogSlug, string $categoryCode): XMLResponse {
		$objectService = $this->getObjectService();
		$request = new SitemapRequest(catalogSlug: $catalogSlug, categoryCode: $categoryCode);
		$error = $this->resolveSitemapRequest(request: $request, objectService: $objectService);
		if ($error !== null) {
			return $error;
		}

		$request->queries = $this->sitemapQueries(request: $request);
		$builder = new SitemapIndexBuilder(objectService: $objectService, perPage: $this->getMaxPerPage());
		if ($request->queries === []) {
			return $builder->response(sitemaps: []);
		}

		return $builder->response(sitemaps: $builder->entries(queries: $request->queries, baseUri: $this->publicationsBaseUri(request: $request)));
	}//end buildSitemapIndex()

	/**
	 * The base URI of the category's paged publication sitemaps.
	 *
	 * @param SitemapRequest $request The resolved request, with its catalog.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function publicationsBaseUri(SitemapRequest $request): string {
		$baseUrl = $this->instance->baseUrl();
		return "$baseUrl/apps/opencatalogi/api/{$request->catalog->getSlug()}/sitemaps/{$request->categoryCode}/publications";
	}//end publicationsBaseUri()

	/**
	 * Build sitemap based on woo publications.
	 *
	 * @param string $catalogSlug The catalog slug identifier.
	 * @param string $categoryCode The DIWOO category code.
	 * @param integer $page The page number to retrieve.
	 *
	 * @return XMLResponse The publications sitemap XML response.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function buildSitemap(string $catalogSlug, string $categoryCode, int $page): XMLResponse {
		$objectService = $this->getObjectService();
		$request = new SitemapRequest(catalogSlug: $catalogSlug, categoryCode: $categoryCode);
		$error = $this->resolveSitemapRequest(request: $request, objectService: $objectService);
		if ($error !== null) {
			return $error;
		}

		// Fail closed: a schema the read-rule guard drops yields a valid, empty
		// document list, never a search (see isDroppedByReadRuleGuard()).
		$builder = new SitemapIndexBuilder(objectService: $objectService, perPage: $this->getMaxPerPage());
		$publications = $this->publicationsWithFiles(rows: $builder->page(queries: $this->sitemapQueries(request: $request), page: $page)['results']);

		$documents = [];
		foreach (self::downloadableFiles(publications: $publications) as [$publication, $file]) {
			$documents[] = $this->mapDiwooDocument(publication: $publication, file: $file);
		}

		return new XMLResponse(data: self::documentsXml(documents: $documents));
	}//end buildSitemap()

	/**
	 * The sitemap `urlset` around the mapped `url` entries.
	 *
	 * The emitted DiWoo version is declared once, on StandardsVersionService, so the
	 * readiness self-check can compare it with the version the publisher publishes.
	 *
	 * An empty page is a `urlset` without children, not one with an empty `url`.
	 *
	 * @param array<int, array<string, mixed>> $documents The mapped `url` entries.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) StandardsVersionService::diwooSchemaLocation()
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private static function documentsXml(array $documents): array {
		$urlset = [
			'@root' => 'urlset',
			'@attributes' => array_replace(self::DOCUMENTS_ATTRIBUTES, ['xsi:schemaLocation' => StandardsVersionService::diwooSchemaLocation()]),
		];
		if ($documents !== []) {
			$urlset['url'] = $documents;
		}

		return $urlset;
	}//end documentsXml()

	/**
	 * Map one file of a publication to a DiWoo document; see SitemapDocumentMapper::map().
	 *
	 * @param array $publication The publication as returned from ObjectService.
	 * @param array $file The file metadata belonging to that publication.
	 * @param array<int, array<string, string>> $violations Per-document unresolved-axis report (by reference).
	 *
	 * @return array A DIWOO metadata array ready for XMLResponse.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function mapDiwooDocument(array $publication, array $file, array &$violations = []): array {
		if ($this->documentMapper === null) {
			$this->documentMapper = new SitemapDocumentMapper(
				tooiVocabulary: $this->tooiVocabulary,
				categories: $this->categories,
				openRegister: $this->openRegister,
				fileFacts: $this->fileFacts
			);
		}

		return $this->documentMapper->map(publication: $publication, file: $file, violations: $violations);
	}//end mapDiwooDocument()

	/**
	 * Every file with a download URL, with the publication it belongs to; each becomes one DiWoo document.
	 *
	 * @param array<int, array<string, mixed>> $publications The publications, with their files under `@self.files`.
	 *
	 * @return \Generator<int, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private static function downloadableFiles(array $publications): \Generator {
		foreach ($publications as $publication) {
			foreach (($publication['@self']['files'] ?? []) as $file) {
				if (isset($file['downloadUrl']) === true) {
					yield [$publication, $file];
				}
			}
		}
	}//end downloadableFiles()

	/**
	 * The searches that feed one category sitemap (REQ-WPC-002).
	 *
	 * First the publications of the catalogue filed under the category: every
	 * register and schema of the catalogue, filtered on `wooCategory`. Then, on
	 * an instance that still runs a register titled `woo`, the schema whose title
	 * names the category, as before. Each schema passes the read-rule guard
	 * first; a source with nothing left is not searched.
	 *
	 * @param SitemapRequest $request The resolved request: the `woo` register and the title-matched
	 *                                schema in it (or null), the catalogue, and the sitemap file name.
	 *
	 * @return array<int, array<string, mixed>> The search queries, without paging.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) CallerScope::writeScope() is a pure static function.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-each-category-sitemap-lists-the-publications-filed-under-it-req-wpc-002
	 */
	private function sitemapQueries(SitemapRequest $request): array {
		$queries = [];
		$scope = $this->catalogScope(catalog: $request->catalog);
		$code = WooCategoryRegistry::codeOf(sitemapFile: $request->categoryCode);
		$schemas = $this->categorySchemas(schemaIds: $scope['schemas'], code: $code);
		if ($code !== null && $scope['registers'] !== [] && $schemas !== []) {
			$queries[] = CallerScope::writeScope(query: ['wooCategory' => $code], registers: $scope['registers'], schemas: $schemas);
		}

		if ($request->registerId !== null && $this->isDroppedByReadRuleGuard(schemaId: $request->schemaId) === false) {
			$queries[] = ['@self' => ['register' => $request->registerId, 'schema' => $request->schemaId]];
		}

		return $queries;
	}//end sitemapQueries()

	/**
	 * The catalog schemas a category's sitemap may list: the read-rule guard's survivors,
	 * narrowed to the schemas the category names when it names any.
	 *
	 * Whether a named slug RESOLVES is not the test: the test is whether the category
	 * named any. A slug no register declares resolves to no id and leaves the list
	 * empty, because reading "resolved to nothing" as "names nothing" would widen the
	 * sitemap back to the whole catalogue (REQ-WIC-003).
	 *
	 * @param array $schemaIds The catalog's schema ids.
	 * @param string|null $code The category code, or null for no known category.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function categorySchemas(array $schemaIds, ?string $code): array {
		$schemas = [];
		if ($schemaIds !== []) {
			$schemas = array_values($this->queryService->applySchemaScopeReadRuleGuard(schemaIds: $schemaIds));
		}

		if ($code !== null && $this->categories->schemasFor(code: $code) !== []) {
			$schemas = array_values(array_intersect($schemas, $this->declaredSchemaIds(code: $code)));
		}

		return $schemas;
	}//end categorySchemas()

	/**
	 * The schema ids a category names for its own sitemap (REQ-WIC-003).
	 *
	 * The category stores schema slugs, because a slug is what an operator writes
	 * and what survives a reinstall; the search needs ids. Every register in the
	 * settings carries its schemas as full objects, so the slugs resolve from data
	 * already loaded. A slug no register declares resolves to nothing and is
	 * therefore absent from the result. The caller asks this only of a category that
	 * named slugs, and treats an empty answer as "no schema to search", never as
	 * "every schema".
	 *
	 * @param string $code The category code.
	 *
	 * @return array<int, mixed> The schema ids the named slugs resolved to.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-category-names-the-schemas-its-sitemap-lists-req-wic-003
	 */
	private function declaredSchemaIds(string $code): array {
		$wanted = array_map('strtolower', $this->categories->schemasFor(code: $code));
		$ids = [];
		foreach (($this->settingsService->getSettings()['availableRegisters'] ?? []) as $register) {
			foreach (($register['schemas'] ?? []) as $schema) {
				$ids[] = self::wantedSchemaId(schema: $schema, wanted: $wanted);
			}
		}

		return array_values(array_filter($ids, static fn (mixed $id): bool => $id !== null));
	}//end declaredSchemaIds()

	/**
	 * The id of a register schema whose slug is one of the wanted slugs, or null.
	 *
	 * @param mixed $schema The register's schema entry.
	 * @param array<string> $wanted The lower-case slugs a category names.
	 *
	 * @return mixed
	 */
	private static function wantedSchemaId(mixed $schema, array $wanted): mixed {
		if (is_array($schema) === false) {
			return null;
		}

		$slug = strtolower(trim((string)($schema['slug'] ?? '')));
		if ($slug === '' || in_array($slug, $wanted, true) === false) {
			return null;
		}

		return ($schema['id'] ?? null);
	}//end wantedSchemaId()

	/**
	 * The registers and schemas a catalogue publishes from.
	 *
	 * @param object $catalog The catalogue object.
	 *
	 * @return array{registers: array<int, mixed>, schemas: array<int, mixed>}
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-each-category-sitemap-lists-the-publications-filed-under-it-req-wpc-002
	 */
	private function catalogScope(object $catalog): array {
		$object = [];
		if (method_exists($catalog, 'getObject') === true) {
			$object = $catalog->getObject();
		}

		$clean = static fn (mixed $ids): array => array_values(
			array_filter((array)$ids, static fn (mixed $id): bool => $id !== null && $id !== '')
		);

		return [
			'registers' => $clean($object['registers'] ?? []),
			'schemas' => $clean($object['schemas'] ?? []),
		];
	}//end catalogScope()

	/**
	 * Whether the SCH-PFTS-CAT-002 read-rule guard drops the sitemap's schema.
	 *
	 * The sitemap picks its schema by woo-register category, but admits it on
	 * membership of the catalog's `schemas` list ({@see resolveSitemapRequest()})
	 * — the same scope source as every other public catalog reader — and then
	 * searches it with `_rbac: true`. A schema without an `authorization` block
	 * is open to every non-private row there, and the sitemap also emits each
	 * row's document URLs to the national Woo index. So it gets the same
	 * anonymous-only guard as `/api/{catalogSlug}` (WOO-581 review). A signed-in
	 * caller is untouched; the admin-only diwooReport keeps session RBAC.
	 *
	 * @param int|string|null $schemaId The schema the request resolved to.
	 *
	 * @return bool True when the caller must get an empty sitemap.
	 *
	 * @spec openspec/changes/archive/2026-08-28-fix-fts-catalog-model-alignment/specs/search/spec.md
	 */
	private function isDroppedByReadRuleGuard(int|string|null $schemaId): bool {
		if ($schemaId === null) {
			return true;
		}

		return $this->queryService->applySchemaScopeReadRuleGuard(schemaIds: [$schemaId]) === [];
	}//end isDroppedByReadRuleGuard()

	/**
	 * Validates a sitemap request and resolves its catalog, schema and register.
	 *
	 * Ensures the requested category and catalog exist, resolves the Woo catalog
	 * and verifies the schema belongs to it. The catalog, register and schema are
	 * set on the request.
	 *
	 * @param SitemapRequest $request The request; receives the resolved catalog, register and schema.
	 * @param object $objectService The OpenRegister ObjectService
	 *
	 * @return XMLResponse|null Null when the request is valid, otherwise the error response.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function resolveSitemapRequest(SitemapRequest $request, $objectService): ?XMLResponse {
		$settings = $this->settingsService->getSettings();

		if (isset($settings['availableRegisters']) === false) {
			return new XMLResponse(data: 'Could net fetch settings', status: 500);
		}

		$wooRegister = $this->wooRegister(registers: $settings['availableRegisters']);
		$request->registerId = ($wooRegister['id'] ?? null);

		// The categories are data (REQ-WIC-001): a file name nothing is served at,
		// and a local category the registry refused for want of a waardelijst
		// member, are the same 400 here. That is the guarantee an unmapped local
		// category cannot reach a sitemap: it has no record, so it has no route.
		$category = $this->categories->findBySitemapFile(sitemapFile: $request->categoryCode);
		if ($category === null) {
			return new XMLResponse(data: 'Invalid category code', status: 400);
		}

		$request->schemaId = $this->titleMatchedSchemaId(schemas: ($wooRegister['schemas'] ?? []), category: $category);

		$request->catalog = self::firstVisible(objectService: $objectService, query: $request->catalogQuery(configuration: $settings['configuration']));

		return $this->catalogScopeError(request: $request);
	}//end resolveSitemapRequest()

	/**
	 * The first object a search finds, or an empty array; visibility is governed by RBAC on the search.
	 *
	 * @param mixed $objectService The OpenRegister ObjectService.
	 * @param array $query The search.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private static function firstVisible(mixed $objectService, array $query): mixed {
		$result = $objectService->searchObjectsPaginated(query: $query, _rbac: true, _multitenancy: false, deleted: false);
		return ($result['results'][0] ?? []);
	}//end firstVisible()

	/**
	 * Check the resolved catalog and keep the title-matched schema only inside it.
	 *
	 * @param SitemapRequest $request The request with its catalog; loses its schema
	 *                                and register when the schema lies outside the catalog.
	 *
	 * @return XMLResponse|null Null when the catalog can list the category, otherwise the error response.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function catalogScopeError(SitemapRequest $request): ?XMLResponse {
		if (empty($request->catalog) === true) {
			return new XMLResponse(data: 'Invalid Woo catalog', status: 400);
		}

		$scope = $this->catalogScope(catalog: $request->catalog);
		self::dropSchemaOutside(request: $request, scope: $scope);

		// With neither a filed-category source nor a title-matched schema there
		// is nothing this catalogue can list for the category.
		if ($request->schemaId === null && ($scope['registers'] === [] || $scope['schemas'] === [])) {
			return new XMLResponse(data: 'Schema not configured in catalog', status: 400);
		}

		return null;
	}//end catalogScopeError()

	/**
	 * Forget a title-matched schema (and its register) outside the catalogue: it contributes nothing.
	 *
	 * @param SitemapRequest $request The request.
	 * @param array $scope The catalogue's registers and schemas.
	 *
	 * @return void
	 */
	private static function dropSchemaOutside(SitemapRequest $request, array $scope): void {
		if (in_array(needle: $request->schemaId, haystack: $scope['schemas']) === false) {
			$request->schemaId = null;
			$request->registerId = null;
		}
	}//end dropSchemaOutside()

	/**
	 * The register titled `woo` (any case); the last one when there are several.
	 *
	 * @param array<int, array<string, mixed>> $registers The available registers.
	 *
	 * @return array<string, mixed>|null The register, or null when there is none.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function wooRegister(array $registers): ?array {
		$wooRegister = null;
		foreach ($registers as $register) {
			if (strcasecmp(($register['title'] ?? ''), 'woo') === 0) {
				$wooRegister = $register;
			}
		}

		return $wooRegister;
	}//end wooRegister()

	/**
	 * The `woo` register schema whose title matches the category label.
	 *
	 * Titles and label are compared trimmed, with `Jaarplan of jaarverslag` read as
	 * `Jaarplannen en jaarverslagen` because the schema does not follow the Woo
	 * category name. A category that names its own schemas (REQ-WIC-003) does not
	 * also get a title-matched schema: the named list is the whole answer.
	 *
	 * @param array<int, array<string, mixed>> $schemas The `woo` register schemas.
	 * @param array<string, mixed> $category The category.
	 *
	 * @return mixed The schema id, or null.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function titleMatchedSchemaId(array $schemas, array $category): mixed {
		$haystack = array_map(self::schemaTitle(...), $schemas);

		$index = array_search(needle: trim($category['label']), haystack: $haystack);
		if ($index !== false && isset($schemas[$index]) === true && $category['schemas'] === []) {
			return $schemas[$index]['id'];
		}

		return null;
	}//end titleMatchedSchemaId()

	/**
	 * A schema's title as the category label it matches; one schema title differs from its label.
	 *
	 * @param mixed $schema The schema entry.
	 *
	 * @return string
	 */
	private static function schemaTitle(mixed $schema): string {
		$title = trim($schema['title']);
		if ($title === 'Jaarplan of jaarverslag') {
			return 'Jaarplannen en jaarverslagen';
		}

		return $title;
	}//end schemaTitle()

	/**
	 * Dry-run the DIWOO mapping for a catalog and collect per-document violations.
	 *
	 * Runs the same {@see mapDiwooDocument()} path used to serve the sitemap, but
	 * discards the XML and returns only the unresolved-axis report. Advisory: it
	 * never prevents the sitemap from being served (WOO-TOOI-004).
	 *
	 * @param array<int, array<string, mixed>> $publications The catalog's publications (with `@self.files`).
	 *
	 * @return array<int, array<string, string>> The `{documentLoc, axis, reason}` violations.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function collectDiwooViolations(array $publications): array {
		$violations = [];
		foreach (self::downloadableFiles(publications: $publications) as [$publication, $file]) {
			$this->mapDiwooDocument(publication: $publication, file: $file, violations: $violations);
		}

		return $violations;
	}//end collectDiwooViolations()

	/**
	 * Validate a catalog's DIWOO output in a dry run (WOO-TOOI-004).
	 *
	 * Fetches the catalog's publicly visible publications and their files exactly
	 * as {@see buildSitemap()} does, then reports every axis that could not resolve
	 * to an official TOOI/DiWoo value-list URI. Advisory only — it does not serve
	 * or block the sitemap.
	 *
	 * @param string $catalogSlug The catalog slug.
	 * @param string $categoryCode The DIWOO category code (e.g. `infocat014`).
	 * @param integer $page The 1-based page number.
	 *
	 * @return array{catalogSlug: string, categoryCode: string, valid: bool, violations: array<int, array<string, string>>, error?: string}
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function validateDiwooOutput(string $catalogSlug, string $categoryCode, int $page = 1): array {
		$request = new SitemapRequest(catalogSlug: $catalogSlug, categoryCode: $categoryCode);
		try {
			$objectService = $this->getObjectService();
		} catch (\Throwable $e) {
			return $this->diwooError(request: $request, error: 'OpenRegister is not available');
		}

		if ($this->resolveSitemapRequest(request: $request, objectService: $objectService) !== null) {
			return $this->diwooError(request: $request, error: 'Unknown catalog or category');
		}

		$scope = ['register' => $request->registerId, 'schema' => $request->schemaId];
		$query = ['@self' => $scope, '_limit' => $this->getMaxPerPage(), '_page' => max(1, $page)];
		$publicationResult = $objectService->searchObjectsPaginated(query: $query, _rbac: true, _multitenancy: false, deleted: false);
		$violations = $this->collectDiwooViolations(publications: $this->publicationsWithFiles(rows: ($publicationResult['results'] ?? [])));

		return ['catalogSlug' => $catalogSlug, 'categoryCode' => $categoryCode, 'valid' => ($violations === []), 'violations' => $violations];
	}//end validateDiwooOutput()

	/**
	 * The publications as arrays, each with its files under `@self.files`.
	 *
	 * The search does not return files, so they are fetched per publication.
	 *
	 * @param iterable<mixed> $rows The search rows, arrays or serialisable objects.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function publicationsWithFiles(iterable $rows): array {
		$fileService = $this->getFileService();
		$publications = [];
		foreach ($rows as $publication) {
			if (is_array($publication) === false) {
				$publication = $publication->jsonSerialize();
			}

			$publication['@self']['files'] = ($fileService->formatFiles($fileService->getFiles(object: $publication['id']))['results'] ?? []);
			$publications[] = $publication;
		}

		return $publications;
	}//end publicationsWithFiles()

	/**
	 * Build a DIWOO validator error report.
	 *
	 * @param SitemapRequest $request The catalog slug and category code asked for.
	 * @param string $error The error message.
	 *
	 * @return array{catalogSlug: string, categoryCode: string, valid: bool, violations: array<int, array<string, string>>, error: string}
	 *
	 * @spec exclude Error-shape plumbing for the DIWOO validator.
	 */
	private function diwooError(SitemapRequest $request, string $error): array {
		return [
			'catalogSlug' => $request->catalogSlug,
			'categoryCode' => $request->categoryCode,
			'valid' => false,
			'violations' => [],
			'error' => $error,
		];

	}//end diwooError()
}//end class
