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
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Service for generating DIWOO-compliant sitemaps.
 *
 * Provides functionality for building sitemap indexes and publication sitemaps
 * following the DIWOO metadata standard.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class SitemapService implements SitemapServiceInterface {

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
	 * The app id used for IAppConfig reads.
	 *
	 * @var string
	 */
	private const APP_NAME = 'opencatalogi';

	/**
	 * Constructor for SitemapService.
	 *
	 * @param ContainerInterface $container Server container for dependency injection
	 * @param IAppManager $appManager App manager for checking installed apps
	 * @param SettingsService $settingsService The settings service
	 * @param IURLGenerator $urlGenerator The Nextcloud URL generator
	 * @param IAppConfig $config App configuration (operator-tunable page size)
	 * @param TooiVocabularyService $tooiVocabulary The TOOI value-list resolver
	 * @param PublicationQueryService $queryService The SCH-PFTS-CAT-002 read-rule guard
	 * @param WooCategoryRegistry $categories The information categories, bundled plus local
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly SettingsService $settingsService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppConfig $config,
		private readonly TooiVocabularyService $tooiVocabulary,
		private readonly PublicationQueryService $queryService,
		private readonly WooCategoryRegistry $categories,
	) {

	}//end __construct()

	/**
	 * Per-request cache of resolved organisation → TOOI identifier lookups.
	 *
	 * @var array<string, string|null>
	 */
	private array $orgTooiCache = [];

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
		$value = $this->config->getValueInt(self::APP_NAME, self::CONFIG_MAX_PER_PAGE, self::DEFAULT_MAX_PER_PAGE);
		return max(1, $value);
	}//end getMaxPerPage()

	/**
	 * Attempts to retrieve the OpenRegister ObjectService from the container.
	 *
	 * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister ObjectService if available, null otherwise.
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 */
	private function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		}

		throw new RuntimeException('OpenRegister service is not available.');
	}//end getObjectService()

	/**
	 * Attempts to retrieve the OpenRegister FileService from the container.
	 *
	 * @return \OCA\OpenRegister\Service\FileService|null The OpenRegister FileService if available, null otherwise.
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 */
	private function getFileService(): ?\OCA\OpenRegister\Service\FileService {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			return $this->container->get('OCA\OpenRegister\Service\FileService');
		}

		throw new RuntimeException('OpenRegister FileService is not available.');
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

		$request->queries = $this->sitemapQueries(
			registerId: $request->registerId,
			schemaId: $request->schemaId,
			catalog: $request->catalog,
			categoryCode: $categoryCode
		);
		$builder = new SitemapIndexBuilder(objectService: $objectService, perPage: $this->getMaxPerPage());
		if ($request->queries === []) {
			return $builder->response(sitemaps: []);
		}

		$baseUrl = rtrim($this->urlGenerator->getBaseUrl(), '/');
		return $builder->response(
			sitemaps: $builder->entries(
				queries: $request->queries,
				baseUri: "$baseUrl/apps/opencatalogi/api/{$request->catalog->getSlug()}/sitemaps/{$request->categoryCode}/publications"
			)
		);
	}//end buildSitemapIndex()

	/**
	 * Build sitemap based on woo publications.
	 *
	 * @param string $catalogSlug The catalog slug identifier.
	 * @param string $categoryCode The DIWOO category code.
	 * @param integer $page The page number to retrieve.
	 *
	 * @return XMLResponse The publications sitemap XML response.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) StandardsVersionService::diwooSchemaLocation()
	 *                is a pure static builder over constants, like CallerScope::writeScope().
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

		$catalog = $request->catalog;
		$registerId = $request->registerId;
		$schemaId = $request->schemaId;

		// Fail closed: a schema the read-rule guard drops yields a valid, empty
		// document list, never a search (see isDroppedByReadRuleGuard()).
		$queries = $this->sitemapQueries(registerId: $registerId, schemaId: $schemaId, catalog: $catalog, categoryCode: $categoryCode);
		$builder = new SitemapIndexBuilder(objectService: $objectService, perPage: $this->getMaxPerPage());
		$publications = $builder->page(queries: $queries, page: $page)['results'];

		$fileService = $this->getFileService();

		$xmlDiwooDocuments = [];
		foreach ($publications as $publication) {
			$publication = $publication->jsonSerialize();

			// Fetch files since searchObjectsPaginated does not return them.
			$publication['@self']['files'] = (
				$fileService->formatFiles(
					$fileService->getFiles(object: $publication['id'])
				)['results'] ?? []
			);

			// Map each file to a separate DIWOO Document.
			foreach ($publication['@self']['files'] as $file) {
				if (isset($file['downloadUrl']) === false) {
					continue;
				}

				$xmlDiwooDocuments[] = $this->mapDiwooDocument(publication: $publication, file: $file)['diwoo:Document'];
			}
		}

		// The emitted DiWoo version is declared once, on StandardsVersionService, so the
		// readiness self-check can compare it with the version the publisher publishes.
		// It used to be a literal here, which made that comparison impossible.
		$xmlContent = [
			'@root' => 'diwoo:Documents',
			'@attributes' => [
				'xmlns' => StandardsVersionService::SITEMAP_NAMESPACE,
				'xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
				'xmlns:diwoo' => StandardsVersionService::DIWOO_NAMESPACE,
				'xsi:schemaLocation' => StandardsVersionService::diwooSchemaLocation(),
				'xmlns:xhtml' => 'http://www.w3.org/1999/xhtml',
				'xmlns:image' => 'http://www.google.com/schemas/sitemap-image/1.1',
				'xmlns:video' => 'http://www.google.com/schemas/sitemap-video/1.1',
				'xmlns:news' => 'http://www.google.com/schemas/sitemap-news/0.9',
			],
			'diwoo:Document' => $xmlDiwooDocuments,
		];

		return new XMLResponse(data: $xmlContent);
	}//end buildSitemap()

	/**
	 * The searches that feed one category sitemap (REQ-WPC-002).
	 *
	 * First the publications of the catalogue filed under the category: every
	 * register and schema of the catalogue, filtered on `wooCategory`. Then, on
	 * an instance that still runs a register titled `woo`, the schema whose title
	 * names the category, as before. Each schema passes the read-rule guard
	 * first; a source with nothing left is not searched.
	 *
	 * @param mixed $registerId The `woo` register, or null.
	 * @param mixed $schemaId The title-matched schema in it, or null.
	 * @param object $catalog The catalogue object.
	 * @param string $categoryCode The sitemap file name, e.g. sitemapindex-diwoo-infocat012.xml.
	 *
	 * @return array<int, array<string, mixed>> The search queries, without paging.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) CallerScope::writeScope() is a pure static function.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-each-category-sitemap-lists-the-publications-filed-under-it-req-wpc-002
	 */
	private function sitemapQueries(mixed $registerId, mixed $schemaId, object $catalog, string $categoryCode): array {
		$queries = [];

		$scope = $this->catalogScope(catalog: $catalog);
		$schemas = [];
		if ($scope['schemas'] !== []) {
			$schemas = array_values($this->queryService->applySchemaScopeReadRuleGuard(schemaIds: $scope['schemas']));
		}

		$code = WooCategoryRegistry::codeOf(sitemapFile: $categoryCode);
		if ($code !== null && $this->categories->schemasFor(code: $code) !== []) {
			// A category may name the schemas its own sitemap lists (REQ-WIC-003).
			// Whether a named slug RESOLVES is not the test: the test is whether the
			// category named any. A slug no register declares resolves to no id and
			// must leave the search empty, because reading "resolved to nothing" as
			// "names nothing" would widen the sitemap back to the whole catalogue —
			// the opposite of the instruction, and silently.
			$schemas = array_values(array_intersect($schemas, $this->declaredSchemaIds(code: $code)));
		}

		if ($code !== null && $scope['registers'] !== [] && $schemas !== []) {
			$queries[] = CallerScope::writeScope(query: ['wooCategory' => $code], registers: $scope['registers'], schemas: $schemas);
		}

		if ($registerId !== null && $this->isDroppedByReadRuleGuard(schemaId: $schemaId) === false) {
			$queries[] = ['@self' => ['register' => $registerId, 'schema' => $schemaId]];
		}

		return $queries;
	}//end sitemapQueries()

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
				if (is_array($schema) === false) {
					continue;
				}

				$slug = strtolower(trim((string)($schema['slug'] ?? '')));
				if ($slug === '' || in_array($slug, $wanted, true) === false) {
					continue;
				}

				$ids[] = ($schema['id'] ?? null);
			}
		}

		return array_values(array_filter($ids, static fn (mixed $id): bool => $id !== null));
	}//end declaredSchemaIds()

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
		if ($wooRegister !== null) {
			$request->registerId = $wooRegister['id'];
		}

		// The categories are data (REQ-WIC-001): a file name nothing is served at,
		// and a local category the registry refused for want of a waardelijst
		// member, are the same 400 here. That is the guarantee an unmapped local
		// category cannot reach a sitemap: it has no record, so it has no route.
		$category = $this->categories->findBySitemapFile(sitemapFile: $request->categoryCode);
		if ($category === null) {
			return new XMLResponse(data: 'Invalid category code', status: 400);
		}

		$request->schemaId = $this->titleMatchedSchemaId(schemas: ($wooRegister['schemas'] ?? []), category: $category);

		// Visibility governed by RBAC on the search (_rbac: true).
		$catalogResult = $objectService->searchObjectsPaginated(
			query: $request->catalogQuery(configuration: $settings['configuration']),
			_rbac: true,
			_multitenancy: false,
			deleted: false
		);
		$request->catalog = ($catalogResult['results'][0] ?? []);

		return $this->catalogScopeError(request: $request);
	}//end resolveSitemapRequest()

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

		// A title-matched schema outside the catalogue contributes nothing.
		$scope = $this->catalogScope(catalog: $request->catalog);
		if (in_array(needle: $request->schemaId, haystack: $scope['schemas']) === false) {
			$request->schemaId = null;
			$request->registerId = null;
		}

		// With neither a filed-category source nor a title-matched schema there
		// is nothing this catalogue can list for the category.
		if ($request->schemaId === null && ($scope['registers'] === [] || $scope['schemas'] === [])) {
			return new XMLResponse(data: 'Schema not configured in catalog', status: 400);
		}

		return null;
	}//end catalogScopeError()

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
		$haystack = array_map(
			static function ($schema) {
				$title = trim($schema['title']);
				if ($title === 'Jaarplan of jaarverslag') {
					return 'Jaarplannen en jaarverslagen';
				}

				return $title;
			},
			$schemas
		);

		$index = array_search(needle: trim($category['label']), haystack: $haystack);
		if ($index !== false && isset($schemas[$index]) === true && $category['schemas'] === []) {
			return $schemas[$index]['id'];
		}

		return null;
	}//end titleMatchedSchemaId()

	/**
	 * Maps publication + file metadata into a DIWOO Document XML array structure.
	 *
	 * Used to generate items inside diwoo:Documents.
	 *
	 * The three Woo-index-controlled axes — informatiecategorie, publisher
	 * `@resource`, and soortHandeling — are resolved through the bundled TOOI/DiWoo
	 * value lists ({@see TooiVocabularyService}). An axis that cannot resolve to an
	 * official URI is OMITTED (never emitted as a free-text `@resource`) and a
	 * `{documentLoc, axis, reason}` violation is appended to `$violations`, so the
	 * national Woo-index (KOOP) never rejects the document on ingest (WOO-TOOI-001..004).
	 *
	 * @param array $publication The publication as returned from ObjectService
	 * @param array $file The file metadata belonging to that publication
	 * @param array<int, array<string, string>> $violations Per-document unresolved-axis report (by reference)
	 *
	 * @return array A DIWOO metadata array ready for XMLResponse
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function mapDiwooDocument(array $publication, array $file, array &$violations = []): array {
		$created = ($publication['@self']['created'] ?? 'now');
		$updated = ($publication['@self']['updated'] ?? 'now');
		$published = ($file['published'] ?? $updated);
		$loc = ($file['downloadUrl'] ?? '');

		$formatUri = 'http://publications.europa.eu/resource/authority/file-type/';
		$formatUri .= strtoupper($file['extension']);

		// Diwoo:atTime — the moment the document was made public. Prefer the
		// file's own published timestamp, then the publication's publicationDate.
		// The removed object-level @self.published is always empty for the
		// magic-mapped publication objects, so it is no longer consulted.
		$handlingTime = ($file['published'] ?? $publication['publicationDate'] ?? date('Y-m-d H:i:s'));

		$diwoo = [
			'loc' => $loc,
			'lastmod' => date('Y-m-d H:i:s', strtotime($published)),
			'diwoo:creatiedatum' => date('Y-m-d', strtotime($created)),
			'diwoo:publisher' => $this->publisherElement(publication: $publication, file: $file),
		];
		if (isset($diwoo['diwoo:publisher']['@resource']) === false) {
			$this->addViolation(violations: $violations, loc: $loc, axis: 'publisher', reason: 'organisation has no TOOI identifier');
		}

		$diwoo['diwoo:format'] = [
			'@resource' => $formatUri,
			'#text' => strtolower($file['extension']),
		];

		// Each optional axis is its element, or null with the violation that omits it.
		$optionalAxes = [
			'diwoo:classificatiecollectie' => [
				$this->categoryElement(publication: $publication),
				'informatiecategorie',
				'category resolves to no member of the informatiecategorieen waardelijst',
			],
			'diwoo:documenthandelingen' => [
				$this->handlingElement(publication: $publication, handlingTime: $handlingTime),
				'soortHandeling',
				'handling type does not resolve to a DiWoo value-list member',
			],
		];
		foreach ($optionalAxes as $key => [$element, $axis, $reason]) {
			if ($element !== null) {
				$diwoo[$key] = $element;
				continue;
			}

			$this->addViolation(violations: $violations, loc: $loc, axis: $axis, reason: $reason);
		}

		return ['diwoo:Document' => ['diwoo:DiWoo' => $diwoo]];
	}//end mapDiwooDocument()

	/**
	 * The publisher element: the owner as text, with `@resource` bound to a valid
	 * TOOI organisatie URI, or without `@resource` when there is none.
	 *
	 * @param array $publication The publication as returned from ObjectService
	 * @param array $file        The file metadata belonging to that publication
	 *
	 * @return array{'#text': mixed, '@resource'?: string} The publisher element.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function publisherElement(array $publication, array $file): array {
		$publisher = ['#text' => ($file['owner'] ?? $publication['@self']['owner'] ?? '')];
		$orgUri = $this->tooiVocabulary->resolveOrganisatie($this->resolveOrganisationTooiIdentifier(publication: $publication));
		if ($orgUri !== null) {
			$publisher['@resource'] = $orgUri;
		}

		return $publisher;
	}//end publisherElement()

	/**
	 * The informatiecategorie element, bound to an official TOOI category URI,
	 * or null when the publication's category resolves to none.
	 *
	 * @param array $publication The publication as returned from ObjectService
	 *
	 * @return array|null The classificatiecollectie element, or null.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function categoryElement(array $publication): ?array {
		$categoryRaw = (
			$publication['wooCategory'] ?? $publication['category']
			?? $publication['tooiCategorieUri'] ?? $publication['tooiCategorieNaam'] ?? null
		);
		$category = $this->categories->resolveForDocument($this->stringOrNull(value: $categoryRaw));
		if ($category === null) {
			return null;
		}

		return [
			'diwoo:informatiecategorieen' => [
				'diwoo:informatiecategorie' => [
					'#text' => $category['label'],
					'@resource' => $category['uri'],
				],
			],
		];
	}//end categoryElement()

	/**
	 * The documenthandeling element, resolved through the DiWoo value list
	 * (default: ontvangst), or null when the handling type resolves to none.
	 *
	 * @param array  $publication  The publication as returned from ObjectService
	 * @param mixed  $handlingTime The moment the document was made public.
	 *
	 * @return array|null The documenthandelingen element, or null.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function handlingElement(array $publication, mixed $handlingTime): ?array {
		$handling = $this->tooiVocabulary->resolveSoortHandeling($this->stringOrNull(value: $publication['soortHandeling'] ?? null));
		if ($handling === null) {
			return null;
		}

		return [
			'diwoo:documenthandeling' => [
				'diwoo:soortHandeling' => [
					'#text' => $handling['label'],
					'@resource' => $handling['uri'],
				],
				'diwoo:atTime' => $handlingTime,
			],
		];
	}//end handlingElement()

	/**
	 * Return the value as a string, or null when it is not a non-empty string.
	 *
	 * @param mixed $value The candidate value.
	 *
	 * @return string|null The string, or null.
	 *
	 * @spec exclude Type-narrowing plumbing.
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_string($value) === true && trim($value) !== '') {
			return $value;
		}

		return null;
	}//end stringOrNull()

	/**
	 * Append a DIWOO value-list violation record.
	 *
	 * @param array<int, array<string, string>> $violations The violation list (by reference).
	 * @param string $loc The document location (download URL).
	 * @param string $axis The unresolved axis.
	 * @param string $reason The human-readable reason.
	 *
	 * @return void
	 *
	 * @spec exclude Violation-record plumbing for the DIWOO validator.
	 */
	private function addViolation(array &$violations, string $loc, string $axis, string $reason): void {
		$violations[] = ['documentLoc' => $loc, 'axis' => $axis, 'reason' => $reason];

	}//end addViolation()

	/**
	 * Resolve the TOOI organisatie identifier for a publication's owning organisation.
	 *
	 * Prefers a publication-level `tooiIdentifier`, then a `@self.organisation` value
	 * that is already a TOOI id URI, and finally the organisation object's own
	 * `tooiIdentifier` property (resolved once per organisation via OpenRegister and
	 * cached for the request). Returns null when none resolves.
	 *
	 * @param array<string, mixed> $publication The publication object.
	 *
	 * @return string|null The candidate TOOI organisatie identifier, or null.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	private function resolveOrganisationTooiIdentifier(array $publication): ?string {
		$direct = ($publication['tooiIdentifier'] ?? null);
		if (is_string($direct) === true && trim($direct) !== '') {
			return $direct;
		}

		$organisation = ($publication['@self']['organisation'] ?? null);
		if (is_string($organisation) === false || trim($organisation) === '') {
			return null;
		}

		// Already a TOOI id URI on the publication envelope.
		if (str_starts_with($organisation, TooiVocabularyService::ORG_ID_BASE) === true) {
			return $organisation;
		}

		if (array_key_exists($organisation, $this->orgTooiCache) === true) {
			return $this->orgTooiCache[$organisation];
		}

		$identifier = $this->fetchOrganisationTooiIdentifier(organisation: $organisation);
		$this->orgTooiCache[$organisation] = $identifier;
		return $identifier;
	}//end resolveOrganisationTooiIdentifier()

	/**
	 * Fetch an organisation's `tooiIdentifier` via OpenRegister by its UUID.
	 *
	 * @param string $organisation The organisation UUID.
	 *
	 * @return string|null The tooiIdentifier, or null when unresolved/unavailable.
	 *
	 * @spec exclude Object-lookup plumbing for the DIWOO publisher binding.
	 */
	private function fetchOrganisationTooiIdentifier(string $organisation): ?string {
		try {
			$objectService = $this->getObjectService();
			$results = $objectService->searchObjects(
				query: ['@self' => ['uuid' => $organisation]],
				_rbac: false,
				_multitenancy: false,
			);
		} catch (\Throwable $e) {
			return null;
		}

		if (is_array($results) === false || empty($results) === true) {
			return null;
		}

		$org = $results[0];
		$orgData = [];
		if (is_array($org) === true) {
			$orgData = $org;
		} elseif (method_exists($org, 'jsonSerialize') === true) {
			$orgData = $org->jsonSerialize();
		}

		return $this->stringOrNull(value: $orgData['tooiIdentifier'] ?? null);
	}//end fetchOrganisationTooiIdentifier()

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
		foreach ($publications as $publication) {
			$files = ($publication['@self']['files'] ?? []);
			foreach ($files as $file) {
				if (isset($file['downloadUrl']) === false) {
					continue;
				}

				$this->mapDiwooDocument(publication: $publication, file: $file, violations: $violations);
			}
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

		$publicationResult = $objectService->searchObjectsPaginated(
			query: [
				'@self' => ['register' => $request->registerId, 'schema' => $request->schemaId],
				'_limit' => $this->getMaxPerPage(),
				'_page' => max(1, $page),
			],
			_rbac: true,
			_multitenancy: false,
			deleted: false
		);

		$violations = $this->collectDiwooViolations(
			publications: $this->publicationsWithFiles(rows: ($publicationResult['results'] ?? []))
		);

		return [
			'catalogSlug' => $catalogSlug,
			'categoryCode' => $categoryCode,
			'valid' => ($violations === []),
			'violations' => $violations,
		];

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

			$publication['@self']['files'] = (
				$fileService->formatFiles(
					$fileService->getFiles(object: $publication['id'])
				)['results'] ?? []
			);
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
